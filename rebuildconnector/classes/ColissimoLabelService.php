<?php

defined('_PS_VERSION_') || exit;

/**
 * Service de génération d'étiquettes Colissimo via l'API REST de La Poste.
 *
 * APPROCHE RETENUE : Repli REST direct avec réutilisation des classes lib du module Colissimo.
 *
 * Pourquoi ne pas utiliser ColissimoLabelGenerator directement :
 *   - Il exige un objet ColissimoOrder ($data['colissimo_order']) déjà hydraté depuis
 *     ps_colissimo_order, qui peut ne pas exister pour toutes les commandes Colissimo
 *     (la ligne est créée par hookActionValidateOrder du module, mais si le module n'était
 *     pas actif lors de la commande, elle est absente).
 *   - Il exige également ColissimoAddress ($data['additional_address']), ColissimoService, etc.
 *   - Charger module.classes.php entraînerait 25+ require_once avec des dépendances croisées.
 *
 * Ce qu'on fait :
 *   - On charge uniquement les classes lib nécessaires (Request/Response/Client) via require_once.
 *   - On lit les credentials et l'adresse expéditeur depuis Configuration directement.
 *   - On construit le payload fidèlement à ce que ColissimoLabelGenerator::generate() produit
 *     pour une livraison à domicile standard.
 *   - On stocke le PDF dans modules/colissimo/documents/labels/ et on crée les lignes
 *     ps_colissimo_order + ps_colissimo_label pour que GET /shipping-label les serve ensuite.
 *
 * SÉCURITÉ : Les credentials Colissimo ne sont JAMAIS loggués ni inclus dans les messages d'erreur.
 */
class ColissimoLabelService
{
    /**
     * Génère une étiquette Colissimo pour la commande donnée via le webservice La Poste.
     *
     * @return array{tracking_number: string, label_id: int}
     * @throws \RuntimeException
     *   Code 404 : commande introuvable
     *   Code 501 : module Colissimo absent ou credentials non configurés
     *   Code 502 : échec du webservice Colissimo
     */
    public function generateColissimoLabel(int $orderId): array
    {
        // 1. Vérifier que le module Colissimo est installé et actif
        if (!Module::isInstalled('colissimo') || !Module::isEnabled('colissimo')) {
            throw new \RuntimeException(
                'Le module Colissimo n\'est pas installé ou n\'est pas activé sur cette boutique.',
                501
            );
        }

        // 2. Lire les credentials (jamais loggués)
        $creds = $this->readCredentials();
        if (empty($creds)) {
            throw new \RuntimeException(
                'Credentials Colissimo non configurés. Vérifiez COLISSIMO_ACCOUNT_LOGIN et COLISSIMO_ACCOUNT_PASSWORD dans la configuration du module Colissimo.',
                501
            );
        }

        // 3. Charger les classes lib du module Colissimo (Request / Response / Client)
        $this->requireColissimoLibClasses();

        // 4. Charger la commande
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            throw new \RuntimeException('Commande introuvable.', 404);
        }

        // 5. Contrôle shop (protection IDOR multistore)
        $currentShopId = (int) Context::getContext()->shop->id;
        if ($currentShopId > 0 && (int) $order->id_shop !== $currentShopId) {
            throw new \RuntimeException('Commande introuvable.', 404);
        }

        // 6. Adresses
        $deliveryAddr = new Address((int) $order->id_address_delivery);
        $senderData = $this->readSenderAddress();

        // 7. Résoudre le product_code Colissimo depuis le carrier de la commande
        $productCode = $this->resolveProductCode((int) $order->id_carrier);

        // 8. Calculer le poids total de la commande
        $weight = $this->calculateOrderWeight($order);

        // 9. Appel webservice
        $apiResult = $this->callColissimoApi($order, $deliveryAddr, $senderData, $productCode, $weight, $creds);

        // 10. Persistance : lignes BDD + PDF sur disque
        $labelId = $this->persistLabel($orderId, (int) $order->id_carrier, $apiResult['tracking_number'], $apiResult['pdf_raw']);

        // 11. Synchroniser le numéro de suivi sur order_carrier
        $this->syncTrackingNumberToOrder($order, $apiResult['tracking_number']);

        return [
            'tracking_number' => $apiResult['tracking_number'],
            'label_id' => $labelId,
        ];
    }

    // =========================================================================
    // Credentials
    // =========================================================================

    /**
     * Lit les credentials Colissimo depuis ps_configuration.
     * Supporte les deux modes : clé de connexion (COLISSIMO_CONNEXION_KEY) et login/password.
     *
     * @return array{key: string}|array{contract_number: string, password: string, partner_code: string}|array{}
     */
    private function readCredentials(): array
    {
        if ((bool) Configuration::get('COLISSIMO_CONNEXION_KEY')) {
            $key = trim((string) Configuration::get('COLISSIMO_ACCOUNT_KEY'));
            if ($key === '') {
                return [];
            }
            return ['key' => $key];
        }

        $contractNumber = trim((string) Configuration::get('COLISSIMO_ACCOUNT_LOGIN'));
        $password = trim((string) Configuration::get('COLISSIMO_ACCOUNT_PASSWORD'));

        if ($contractNumber === '' || $password === '') {
            return [];
        }

        return [
            'contract_number' => $contractNumber,
            'password' => $password,
            'partner_code' => (string) (Configuration::get('COLISSIMO_ACCOUNT_PARENT_ID') ?: ''),
        ];
    }

    // =========================================================================
    // Adresse expéditeur
    // =========================================================================

    /**
     * Lit l'adresse expéditeur depuis COLISSIMO_SENDER_ADDRESS (JSON stocké par le module Colissimo).
     *
     * @return array<string, string>
     */
    private function readSenderAddress(): array
    {
        $json = (string) Configuration::get('COLISSIMO_SENDER_ADDRESS');
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    // =========================================================================
    // Product code
    // =========================================================================

    /**
     * Retrouve le product_code Colissimo (ex. 'DOM', 'COL', 'BPR'…) à partir de l'id_carrier PS.
     * Le module Colissimo stocke l'id_reference du carrier dans colissimo_service.id_carrier.
     * Fallback sur 'DOM' (France domicile sans signature) si non trouvé.
     */
    private function resolveProductCode(int $carrierId): string
    {
        if ($carrierId <= 0) {
            return 'DOM';
        }

        // colissimo_service.id_carrier = Carrier::id_reference (pas l'id direct)
        $carrier = new Carrier($carrierId);
        $idReference = Validate::isLoadedObject($carrier) ? (int) $carrier->id_reference : $carrierId;

        $query = new DbQuery();
        $query->select('product_code');
        $query->from('colissimo_service');
        $query->where('id_carrier = ' . $idReference);
        $query->where('is_return = 0');
        $query->orderBy('id_colissimo_service ASC');

        $productCode = (string) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($query);
        return $productCode !== '' ? $productCode : 'DOM';
    }

    // =========================================================================
    // Poids
    // =========================================================================

    /**
     * Calcule le poids total de la commande en kg.
     * Retourne 0.5 kg par défaut si aucun produit n'a de poids renseigné.
     */
    private function calculateOrderWeight(Order $order): float
    {
        $total = 0.0;
        $products = $order->getProducts();
        foreach ($products as $product) {
            $qty = isset($product['product_quantity']) ? max(1, (int) $product['product_quantity']) : 1;
            $weight = isset($product['product_weight']) ? (float) $product['product_weight'] : 0.0;
            $total += $weight * $qty;
        }
        // Minimum 0.01 kg requis par le webservice ; 0.5 par défaut si non renseigné
        if ($total <= 0.0) {
            return 0.5;
        }
        return round($total, 3);
    }

    // =========================================================================
    // Appel API REST
    // =========================================================================

    /**
     * Construit le payload et appelle le webservice Colissimo.
     *
     * @param array<string, string> $senderData
     * @param array<string, mixed>  $creds
     * @return array{tracking_number: string, pdf_raw: string}
     * @throws \RuntimeException code 502 en cas d'erreur webservice
     */
    private function callColissimoApi(
        Order $order,
        Address $deliveryAddr,
        array $senderData,
        string $productCode,
        float $weight,
        array $creds
    ): array {
        // PDF_A4_300dpi est la valeur par défaut configurée par le module Colissimo lors de l'installation
        $labelFormat = (string) (Configuration::get('COLISSIMO_LABEL_FORMAT') ?: 'PDF_A4_300dpi');

        // Montant transport en centimes (minimum 1 centime requis par le WS)
        $shippingAmountCents = max(1, (int) round((float) $order->total_shipping_tax_excl * 100));

        /** @var \ColissimoGenerateLabelRequest $request */
        $request = new \ColissimoGenerateLabelRequest($creds);

        $request->setOutput([
            'x' => 0,
            'y' => 0,
            'outputPrintingType' => $labelFormat,
        ]);

        $request->setShipmentServices([
            'productCode'          => $productCode,
            'depositDate'          => date('Y-m-d'),
            'transportationAmount' => $shippingAmountCents,
            'orderNumber'          => (string) $order->reference,
        ]);

        $request->setShipmentOptions([
            'weight' => $weight,
        ]);

        // Adresse expéditeur (lue depuis COLISSIMO_SENDER_ADDRESS, configurée dans le module)
        $request->setSenderAddress([
            'senderParcelRef' => (string) $order->reference,
            'address'         => [
                'companyName'  => (string) ($senderData['sender_company'] ?? ''),
                'lastName'     => (string) ($senderData['sender_lastname'] ?? ''),
                'firstName'    => (string) ($senderData['sender_firstname'] ?? ''),
                'line2'        => (string) ($senderData['sender_address1'] ?? ''),
                'line3'        => (string) ($senderData['sender_address2'] ?? ''),
                'countryCode'  => (string) ($senderData['sender_country'] ?? 'FR'),
                'city'         => (string) ($senderData['sender_city'] ?? ''),
                'zipCode'      => (string) ($senderData['sender_zipcode'] ?? ''),
                'phoneNumber'  => (string) ($senderData['sender_phone'] ?? ''),
                'email'        => (string) ($senderData['sender_email'] ?? ''),
            ],
        ]);

        // Email client
        $customerEmail = '';
        if ((int) $order->id_customer > 0) {
            $customer = new Customer((int) $order->id_customer);
            if (Validate::isLoadedObject($customer)) {
                $customerEmail = (string) $customer->email;
            }
        }

        $deliveryCountryIso = (string) Country::getIsoById((int) $deliveryAddr->id_country);

        $addresseeData = [
            'addresseeParcelRef'  => (string) $order->reference,
            'codeBarForReference' => true,
            'address'             => [
                'companyName'  => (string) ($deliveryAddr->company ?? ''),
                'lastName'     => (string) $deliveryAddr->lastname,
                'firstName'    => (string) $deliveryAddr->firstname,
                'line2'        => (string) $deliveryAddr->address1,
                'line3'        => (string) ($deliveryAddr->address2 ?? ''),
                'countryCode'  => $deliveryCountryIso,
                'city'         => (string) $deliveryAddr->city,
                'zipCode'      => (string) $deliveryAddr->postcode,
                'phoneNumber'  => (string) ($deliveryAddr->phone ?: $deliveryAddr->phone_mobile),
                'mobileNumber' => (string) ($deliveryAddr->phone_mobile ?? ''),
                'email'        => $customerEmail,
            ],
        ];

        if ((int) $deliveryAddr->id_state > 0) {
            $state = new State((int) $deliveryAddr->id_state);
            if (Validate::isLoadedObject($state)) {
                $addresseeData['address']['stateOrProvinceCode'] = (string) $state->iso_code;
            }
        }

        $request->setAddresseeAddress($addresseeData);
        $request->buildRequest();

        $client = new \ColissimoClient(1); // mode production
        $client->setRequest($request);

        try {
            /** @var \ColissimoGenerateLabelResponse $response */
            $response = $client->request();
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'Échec de l\'appel au webservice Colissimo : ' . $e->getMessage(),
                502
            );
        }

        if (empty($response->parcelNumber)) {
            $errorMsg = 'Erreur inconnue retournée par le webservice Colissimo.';
            if (!empty($response->messages) && is_array($response->messages)) {
                $first = reset($response->messages);
                if (is_array($first) && isset($first['messageContent'])) {
                    $errorCode = isset($first['id']) ? (string) $first['id'] : '';
                    $errorMsg = $errorCode !== ''
                        ? $errorCode . ' : ' . (string) $first['messageContent']
                        : (string) $first['messageContent'];
                }
            }
            throw new \RuntimeException($errorMsg, 502);
        }

        $pdfRaw = !empty($response->label) ? (string) base64_decode($response->label) : '';

        return [
            'tracking_number' => (string) $response->parcelNumber,
            'pdf_raw'         => $pdfRaw,
        ];
    }

    // =========================================================================
    // Persistance BDD + disque
    // =========================================================================

    /**
     * Crée les lignes ps_colissimo_order (si absente) et ps_colissimo_label,
     * puis écrit le PDF sur disque dans modules/colissimo/documents/labels/.
     *
     * Le nom de fichier respecte la convention de ColissimoLabel::getFilePath() :
     *   {id_colissimo_label}-{shipping_number}.pdf
     */
    private function persistLabel(
        int $orderId,
        int $carrierId,
        string $trackingNumber,
        string $pdfRaw
    ): int {
        $colissimoOrderId = $this->ensureColissimoOrder($orderId, $carrierId);

        $inserted = Db::getInstance()->insert('colissimo_label', [
            'id_colissimo_order'      => (int) $colissimoOrderId,
            'id_colissimo_deposit_slip' => 0,
            'shipping_number'         => pSQL($trackingNumber),
            'label_format'            => 'pdf',
            'cn23_format'             => 'pdf',
            'return_label'            => 0,
            'cn23'                    => 0,
            'coliship'                => 0,
            'migration'               => 0,
            'file_deleted'            => 0,
            'date_add'                => date('Y-m-d H:i:s'),
        ]);

        if (!$inserted) {
            throw new \RuntimeException(
                'Impossible d\'enregistrer l\'étiquette en base de données.',
                500
            );
        }

        $labelId = (int) Db::getInstance()->Insert_ID();

        // Écriture du PDF sur disque
        if ($labelId > 0 && $pdfRaw !== '') {
            $labelsDir = _PS_MODULE_DIR_ . 'colissimo/documents/labels/';
            if (!is_dir($labelsDir)) {
                @mkdir($labelsDir, 0755, true);
            }
            // Valider le numéro de suivi avant de composer le chemin (anti-traversal)
            if (ctype_alnum($trackingNumber)) {
                $filename = $labelId . '-' . $trackingNumber . '.pdf';
                $safePath = realpath($labelsDir);
                if ($safePath !== false) {
                    file_put_contents($safePath . DIRECTORY_SEPARATOR . $filename, $pdfRaw);
                }
            }
        }

        return $labelId;
    }

    /**
     * Retourne l'id_colissimo_order existant pour la commande, ou en crée un.
     */
    private function ensureColissimoOrder(int $orderId, int $carrierId): int
    {
        $query = new DbQuery();
        $query->select('id_colissimo_order');
        $query->from('colissimo_order');
        $query->where('id_order = ' . (int) $orderId);

        $existing = (int) Db::getInstance()->getValue($query);
        if ($existing > 0) {
            return $existing;
        }

        $serviceId = $this->findColissimoServiceId($carrierId);

        $inserted = Db::getInstance()->insert('colissimo_order', [
            'id_order'                 => (int) $orderId,
            'id_colissimo_service'     => (int) $serviceId,
            'id_colissimo_pickup_point' => 0,
            'migration'                => 0,
            'ddp'                      => 0,
            'ddp_cost'                 => '0.000000',
            'hidden'                   => 0,
        ]);

        if (!$inserted) {
            throw new \RuntimeException(
                'Impossible de créer l\'entrée colissimo_order en base de données.',
                500
            );
        }

        return (int) Db::getInstance()->Insert_ID();
    }

    /**
     * Cherche l'id_colissimo_service associé au carrier PS (via id_reference).
     * Retourne 0 si non trouvé.
     */
    private function findColissimoServiceId(int $carrierId): int
    {
        if ($carrierId <= 0) {
            return 0;
        }
        // colissimo_service.id_carrier = Carrier::id_reference
        $carrier = new Carrier($carrierId);
        $idReference = Validate::isLoadedObject($carrier) ? (int) $carrier->id_reference : $carrierId;

        $query = new DbQuery();
        $query->select('id_colissimo_service');
        $query->from('colissimo_service');
        $query->where('id_carrier = ' . $idReference);
        $query->where('is_return = 0');
        $query->orderBy('id_colissimo_service ASC');

        return (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($query);
    }

    // =========================================================================
    // Synchronisation tracking → order_carrier
    // =========================================================================

    /**
     * Met à jour le champ tracking_number dans ps_order_carrier pour la commande.
     * Opération non bloquante : une erreur ici ne fait pas échouer la génération.
     */
    private function syncTrackingNumberToOrder(Order $order, string $trackingNumber): void
    {
        try {
            $idOrderCarrier = (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
                'SELECT id_order_carrier FROM ' . _DB_PREFIX_ . 'order_carrier'
                . ' WHERE id_order = ' . (int) $order->id
                . ' ORDER BY id_order_carrier DESC LIMIT 1'
            );

            if ($idOrderCarrier > 0) {
                Db::getInstance()->update(
                    'order_carrier',
                    ['tracking_number' => pSQL($trackingNumber)],
                    'id_order_carrier = ' . (int) $idOrderCarrier
                );
            }
        } catch (\Throwable $e) {
            // Non bloquant : l'étiquette est générée même si ce champ ne peut pas être mis à jour
        }
    }

    // =========================================================================
    // Chargement classes lib Colissimo
    // =========================================================================

    /**
     * Charge les classes du module Colissimo nécessaires à l'appel API.
     * Utilise class_exists() pour ne pas recharger si déjà présentes (module Colissimo actif).
     *
     * @throws \RuntimeException code 501 si un fichier est manquant
     */
    private function requireColissimoLibClasses(): void
    {
        $base = _PS_MODULE_DIR_ . 'colissimo/';

        $classMap = [
            'AbstractColissimoResponse'       => 'lib/Response/AbstractColissimoResponse.php',
            'ColissimoReturnedResponseInterface' => 'lib/Response/ColissimoReturnedResponseInterface.php',
            'ColissimoResponseParser'          => 'lib/Response/ColissimoResponseParser.php',
            'ColissimoGenerateLabelResponse'   => 'lib/Response/ColissimoGenerateLabelResponse.php',
            'AbstractColissimoRequest'         => 'lib/Request/AbstractColissimoRequest.php',
            'ColissimoGenerateLabelRequest'    => 'lib/Request/ColissimoGenerateLabelRequest.php',
            'ColissimoClient'                  => 'lib/ColissimoClient.php',
        ];

        foreach ($classMap as $className => $relative) {
            if (class_exists($className) || interface_exists($className)) {
                continue;
            }
            $path = $base . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException(
                    'Le module Colissimo est installé mais le fichier ' . basename($relative)
                    . ' est introuvable. Vérifiez l\'intégrité du module.',
                    501
                );
            }
            require_once $path;
        }
    }
}
