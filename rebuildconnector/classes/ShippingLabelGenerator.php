<?php

defined('_PS_VERSION_') || exit;

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/ShippingLabelService.php';

/**
 * Générateur d'étiquettes de transport.
 *
 * Ce service encapsule la logique de déclenchement de la génération d'étiquettes
 * via les APIs des modules transporteurs installés sur la boutique.
 *
 * ÉTAT D'IMPLÉMENTATION PAR TRANSPORTEUR
 * ----------------------------------------
 *   - Colissimo   : scaffold présent, appel API non implémenté.
 *                   Voir generateColissimo() — TODO.
 *   - Mondial Relay: scaffold présent, appel SOAP non implémenté.
 *                   Voir generateMondialRelay() — TODO.
 *
 * Tant que la génération n'est pas branchée, les méthodes retournent
 * ShippingLabelGenerationResult::notConfigured() et le contrôleur répond 501.
 *
 * Ce qui est nécessaire pour finir :
 *   Colissimo :
 *     1. Source du module `colissimo` (récupérer depuis le VPS via infra-ops ou accès SFTP).
 *     2. Vérifier si le module expose ColissimoApi / ColissimoWebservice réutilisable.
 *     3. Identifiants contrat La Poste : ps_configuration clés `LPCOL_LOGIN`, `LPCOL_PWD`
 *        (ou équivalent selon la version du module).
 *     4. Endpoint La Poste : REST `https://ws.colissimo.fr/sls-ws/rest/generateLabel`
 *        (ou SOAP équivalent si le module < v5).
 *     5. Persistance du PDF retourné + ligne dans ps_colissimo_label.
 *
 *   Mondial Relay :
 *     1. Source du module `mondialrelay` (idem, depuis le VPS).
 *     2. Vérifier si MondialRelayWs / WSI2 est réutilisable.
 *     3. Code enseigne + clé privée : ps_configuration `PS_MONDIALRELAY_ENSEIGNE` / `PS_MONDIALRELAY_KEY`.
 *     4. Appel SOAP `WSI2_CreationEtiquette` sur `https://api.mondialrelay.com/Web_Services.asmx`.
 *     5. Persistance de `label_url` + `expedition_num` dans ps_mondialrelay_selected_relay.
 */
class ShippingLabelGenerator
{
    private const CARRIER_COLISSIMO     = 'colissimo';
    private const CARRIER_MONDIAL_RELAY = 'mondialrelay';

    private ShippingLabelService $labelService;

    public function __construct()
    {
        $this->labelService = new ShippingLabelService();
    }

    /**
     * Tente de générer une étiquette pour la commande donnée.
     *
     * Logique d'exécution :
     *   1. Contrôle existence commande + IDOR multistore.
     *   2. Idempotence : si une étiquette existe déjà, retourne ALREADY_EXISTS sans appeler le transporteur.
     *   3. Détecte le transporteur via le nom du carrier PS (même logique que ShippingLabelService).
     *   4. Dispatche vers la méthode de génération dédiée.
     *
     * @throws \InvalidArgumentException si la commande n'existe pas ou n'appartient pas à la boutique
     */
    public function generate(int $orderId): ShippingLabelGenerationResult
    {
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            throw new \InvalidArgumentException('Commande introuvable : ' . $orderId);
        }

        // Contrôle IDOR multistore
        $currentShopId = (int) Context::getContext()->shop->id;
        if ($currentShopId > 0 && (int) $order->id_shop !== $currentShopId) {
            throw new \InvalidArgumentException('Commande introuvable : ' . $orderId);
        }

        // Idempotence : si l'étiquette existe déjà, rien à générer
        $meta = $this->labelService->getShippingLabelMeta($orderId);
        if ($meta['has_shipping_label']) {
            return ShippingLabelGenerationResult::alreadyExists((string) ($meta['carrier_type'] ?? ''));
        }

        $carrierType = $this->resolveCarrierType($order);

        if ($carrierType === null) {
            return ShippingLabelGenerationResult::unsupportedCarrier();
        }

        switch ($carrierType) {
            case self::CARRIER_COLISSIMO:
                return $this->generateColissimo($order);

            case self::CARRIER_MONDIAL_RELAY:
                return $this->generateMondialRelay($order);

            default:
                return ShippingLabelGenerationResult::unsupportedCarrier();
        }
    }

    // -------------------------------------------------------------------------
    // Détection du transporteur (identique à ShippingLabelService)
    // -------------------------------------------------------------------------

    private function resolveCarrierType(Order $order): ?string
    {
        $carrierId = (int) $order->id_carrier;
        if ($carrierId <= 0) {
            return null;
        }

        $carrier = new Carrier($carrierId);
        if (!Validate::isLoadedObject($carrier)) {
            return null;
        }

        $name = Tools::strtolower((string) $carrier->name);

        if (strpos($name, 'colissimo') !== false) {
            return self::CARRIER_COLISSIMO;
        }

        if (strpos($name, 'mondial') !== false || strpos($name, 'relay') !== false) {
            return self::CARRIER_MONDIAL_RELAY;
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Colissimo — génération via webservice La Poste
    // -------------------------------------------------------------------------

    /**
     * Génère une étiquette Colissimo.
     *
     * TODO — Ce qui bloque :
     *   1. Récupérer le source du module `colissimo` depuis le VPS pensebonheur (via infra-ops / SFTP).
     *   2. Vérifier si ColissimoApi ou ColissimoWebservice est réutilisable (instanciable hors du module).
     *   3. Si oui : instancier et appeler generateLabel(Order).
     *   4. Si non : appel REST direct à `https://ws.colissimo.fr/sls-ws/rest/generateLabel`
     *      avec les identifiants depuis ps_configuration (LPCOL_LOGIN / LPCOL_PWD).
     *   5. Persister le PDF dans `modules/colissimo/documents/labels/{id}-{tracking}.pdf`
     *      et insérer la ligne dans ps_colissimo_label (id_colissimo_order, shipping_number, etc.).
     *
     * @param Order $order
     */
    private function generateColissimo(Order $order): ShippingLabelGenerationResult
    {
        // TODO : implémenter l'appel au webservice Colissimo
        return ShippingLabelGenerationResult::notConfigured(self::CARRIER_COLISSIMO);
    }

    // -------------------------------------------------------------------------
    // Mondial Relay — génération via SOAP WSI2_CreationEtiquette
    // -------------------------------------------------------------------------

    /**
     * Génère une étiquette Mondial Relay.
     *
     * TODO — Ce qui bloque :
     *   1. Récupérer le source du module `mondialrelay` depuis le VPS pensebonheur (via infra-ops / SFTP).
     *   2. Vérifier si MondialRelayWs / WSI2 est réutilisable (instanciable hors du module).
     *   3. Si oui : appeler WSI2_CreationEtiquette via la classe du module.
     *   4. Si non : appel SOAP direct à `https://api.mondialrelay.com/Web_Services.asmx`
     *      avec le code enseigne (PS_MONDIALRELAY_ENSEIGNE) et la clé privée depuis ps_configuration.
     *   5. Persister `label_url` et `expedition_num` dans ps_mondialrelay_selected_relay.
     *
     * @param Order $order
     */
    private function generateMondialRelay(Order $order): ShippingLabelGenerationResult
    {
        // TODO : implémenter l'appel SOAP WSI2_CreationEtiquette
        return ShippingLabelGenerationResult::notConfigured(self::CARRIER_MONDIAL_RELAY);
    }
}

/**
 * Résultat d'une tentative de génération d'étiquette.
 *
 * Value object immuable, construit via les factories statiques.
 */
class ShippingLabelGenerationResult
{
    /** Étiquette déjà présente, aucune génération déclenchée (idempotence). */
    public const STATUS_ALREADY_EXISTS      = 'already_exists';
    /** Étiquette générée avec succès lors de cet appel. */
    public const STATUS_GENERATED           = 'generated';
    /** Transporteur détecté mais la génération n'est pas encore configurée (501). */
    public const STATUS_NOT_CONFIGURED      = 'not_configured';
    /** Transporteur non reconnu ou non supporté (422). */
    public const STATUS_UNSUPPORTED_CARRIER = 'unsupported_carrier';
    /** Erreur retournée par le webservice du transporteur (502). */
    public const STATUS_WEBSERVICE_ERROR    = 'webservice_error';

    private string $status;
    private string $carrierType;
    private ?string $trackingNumber;
    private ?string $errorDetail;

    private function __construct(
        string $status,
        string $carrierType = '',
        ?string $trackingNumber = null,
        ?string $errorDetail = null
    ) {
        $this->status         = $status;
        $this->carrierType    = $carrierType;
        $this->trackingNumber = $trackingNumber;
        $this->errorDetail    = $errorDetail;
    }

    /** Étiquette déjà disponible — rien à générer. */
    public static function alreadyExists(string $carrierType): self
    {
        return new self(self::STATUS_ALREADY_EXISTS, $carrierType);
    }

    /** Étiquette générée avec succès. */
    public static function generated(string $carrierType, ?string $trackingNumber = null): self
    {
        return new self(self::STATUS_GENERATED, $carrierType, $trackingNumber);
    }

    /** Génération non encore implémentée/configurée pour ce transporteur. */
    public static function notConfigured(string $carrierType): self
    {
        return new self(self::STATUS_NOT_CONFIGURED, $carrierType);
    }

    /** Transporteur non reconnu ou non supporté. */
    public static function unsupportedCarrier(): self
    {
        return new self(self::STATUS_UNSUPPORTED_CARRIER);
    }

    /** Erreur retournée par le webservice transporteur. */
    public static function webserviceError(string $carrierType, string $detail): self
    {
        return new self(self::STATUS_WEBSERVICE_ERROR, $carrierType, null, $detail);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCarrierType(): string
    {
        return $this->carrierType;
    }

    public function getTrackingNumber(): ?string
    {
        return $this->trackingNumber;
    }

    public function getErrorDetail(): ?string
    {
        return $this->errorDetail;
    }
}
