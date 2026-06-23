<?php

defined('_PS_VERSION_') || exit;

/**
 * Service d'accès aux bordereaux d'expédition.
 *
 * Architecture prévue pour évoluer vers une génération via API transporteur :
 *   - Chaque stratégie transporteur est encapsulée dans sa méthode dédiée.
 *   - La méthode getShippingLabel() est le point d'entrée unique (dispatch par transporteur).
 *   - Pour ajouter un nouveau transporteur, il suffit d'ajouter un cas dans resolveCarrierType()
 *     et une méthode fetchXxxLabel().
 *   - Quand la génération via API sera implémentée, elle passera par le même contrat de retour.
 */
class ShippingLabelService
{
    /**
     * Identifiants de transporteurs reconnus.
     */
    private const CARRIER_COLISSIMO = 'colissimo';
    private const CARRIER_MONDIAL_RELAY = 'mondialrelay';

    /**
     * Timeout cURL pour le proxy Mondial Relay (secondes).
     */
    private const CURL_TIMEOUT = 10;

    /**
     * Retourne le PDF du bordereau d'expédition pour une commande donnée.
     *
     * @return array{pdf: string, filename: string}|null  null = pas de bordereau disponible
     * @throws \RuntimeException en cas d'erreur technique inattendue
     */
    public function getShippingLabel(int $orderId): ?array
    {
        // Contrôle IDOR : la commande doit appartenir à la boutique courante
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return null;
        }

        $currentShopId = (int) Context::getContext()->shop->id;
        if ($currentShopId > 0 && (int) $order->id_shop !== $currentShopId) {
            return null;
        }

        $carrierType = $this->resolveCarrierType($order);

        switch ($carrierType) {
            case self::CARRIER_COLISSIMO:
                return $this->fetchColissimoLabel($orderId);

            case self::CARRIER_MONDIAL_RELAY:
                return $this->fetchMondialRelayLabel($orderId);

            default:
                // Transporteur non géré : on tente quand même Colissimo puis Mondial Relay
                // (cas où le carrier_name ne matche pas mais les données existent en base)
                $result = $this->fetchColissimoLabel($orderId);
                if ($result !== null) {
                    return $result;
                }
                return $this->fetchMondialRelayLabel($orderId);
        }
    }

    /**
     * Retourne les métadonnées du bordereau sans streamer le contenu.
     * Utilisé pour enrichir le détail commande avec has_shipping_label / carrier_type.
     *
     * @return array{has_shipping_label: bool, carrier_type: string|null}
     */
    public function getShippingLabelMeta(int $orderId): array
    {
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return ['has_shipping_label' => false, 'carrier_type' => null];
        }

        $currentShopId = (int) Context::getContext()->shop->id;
        if ($currentShopId > 0 && (int) $order->id_shop !== $currentShopId) {
            return ['has_shipping_label' => false, 'carrier_type' => null];
        }

        $carrierType = $this->resolveCarrierType($order);

        switch ($carrierType) {
            case self::CARRIER_COLISSIMO:
                $has = $this->colissimoLabelExists($orderId);
                return ['has_shipping_label' => $has, 'carrier_type' => self::CARRIER_COLISSIMO];

            case self::CARRIER_MONDIAL_RELAY:
                $has = $this->mondialRelayLabelExists($orderId);
                return ['has_shipping_label' => $has, 'carrier_type' => self::CARRIER_MONDIAL_RELAY];

            default:
                // Sonde les deux modules
                if ($this->colissimoLabelExists($orderId)) {
                    return ['has_shipping_label' => true, 'carrier_type' => self::CARRIER_COLISSIMO];
                }
                if ($this->mondialRelayLabelExists($orderId)) {
                    return ['has_shipping_label' => true, 'carrier_type' => self::CARRIER_MONDIAL_RELAY];
                }
                return ['has_shipping_label' => false, 'carrier_type' => null];
        }
    }

    // -------------------------------------------------------------------------
    // Détection du transporteur
    // -------------------------------------------------------------------------

    /**
     * Détermine le type de transporteur à partir du nom du carrier PS.
     * Retourne null si le transporteur n'est pas reconnu.
     */
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

        // Le nom est en minuscules pour la comparaison, sans accents
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
    // Colissimo — PDF local sur disque
    // -------------------------------------------------------------------------

    /**
     * @return array{pdf: string, filename: string}|null
     */
    private function fetchColissimoLabel(int $orderId): ?array
    {
        $row = $this->findColissimoLabelRow($orderId);
        if ($row === null) {
            return null;
        }

        $filePath = $this->buildColissimoFilePath(
            (int) $row['id_colissimo_label'],
            (string) $row['shipping_number']
        );

        if (!is_file($filePath) || !is_readable($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false || $content === '') {
            return null;
        }

        $filename = 'bordereau-colissimo-' . $orderId . '-' . $row['shipping_number'] . '.pdf';
        return ['pdf' => $content, 'filename' => $filename];
    }

    private function colissimoLabelExists(int $orderId): bool
    {
        $row = $this->findColissimoLabelRow($orderId);
        if ($row === null) {
            return false;
        }

        $filePath = $this->buildColissimoFilePath(
            (int) $row['id_colissimo_label'],
            (string) $row['shipping_number']
        );

        return is_file($filePath) && is_readable($filePath);
    }

    /**
     * Jointure ps_orders → ps_colissimo_order → ps_colissimo_label.
     * Retourne la première ligne non supprimée (return_label = 0, file_deleted = 0).
     *
     * @return array<string, mixed>|null
     */
    private function findColissimoLabelRow(int $orderId): ?array
    {
        $query = new DbQuery();
        $query->select('cl.id_colissimo_label, cl.shipping_number');
        $query->from('colissimo_order', 'co');
        $query->innerJoin('colissimo_label', 'cl', 'cl.id_colissimo_order = co.id_colissimo_order');
        $query->where('co.id_order = ' . (int) $orderId);
        $query->where('cl.return_label = 0');
        $query->where('cl.file_deleted = 0');
        $query->orderBy('cl.id_colissimo_label DESC');

        $result = Db::getInstance()->getRow($query);
        return is_array($result) && $result !== [] ? $result : null;
    }

    private function buildColissimoFilePath(int $labelId, string $shippingNumber): string
    {
        return _PS_MODULE_DIR_ . 'colissimo/documents/labels/'
            . $labelId . '-' . $shippingNumber . '.pdf';
    }

    // -------------------------------------------------------------------------
    // Mondial Relay — proxy HTTP vers URL distante
    // -------------------------------------------------------------------------

    /**
     * @return array{pdf: string, filename: string}|null
     */
    private function fetchMondialRelayLabel(int $orderId): ?array
    {
        $row = $this->findMondialRelayRow($orderId);
        if ($row === null) {
            return null;
        }

        $labelUrl = trim((string) $row['label_url']);
        if ($labelUrl === '') {
            return null;
        }

        $content = $this->fetchRemotePdf($labelUrl);
        if ($content === null) {
            return null;
        }

        $expeditionNum = isset($row['expedition_num']) ? trim((string) $row['expedition_num']) : '';
        $filename = 'bordereau-mondialrelay-' . $orderId
            . ($expeditionNum !== '' ? '-' . $expeditionNum : '')
            . '.pdf';

        return ['pdf' => $content, 'filename' => $filename];
    }

    private function mondialRelayLabelExists(int $orderId): bool
    {
        $row = $this->findMondialRelayRow($orderId);
        if ($row === null) {
            return false;
        }
        $labelUrl = trim((string) ($row['label_url'] ?? ''));
        return $labelUrl !== '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findMondialRelayRow(int $orderId): ?array
    {
        $query = new DbQuery();
        $query->select('label_url, expedition_num');
        $query->from('mondialrelay_selected_relay');
        $query->where('id_order = ' . (int) $orderId);
        $query->where('label_url IS NOT NULL');
        $query->where('label_url != ""');
        $query->orderBy('id_mondialrelay_selected_relay DESC');

        $result = Db::getInstance()->getRow($query);
        return is_array($result) && $result !== [] ? $result : null;
    }

    /**
     * Récupère un PDF distant via cURL et le retourne en chaîne binaire.
     * Retourne null en cas d'erreur HTTP ou si la réponse n'est pas un PDF.
     */
    private function fetchRemotePdf(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            // Fallback file_get_contents (limité, sans contrôle SSL)
            $content = @file_get_contents($url);
            if ($content === false || $content === '') {
                return null;
            }
            return $this->isPdfContent($content) ? $content : null;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'PrestaFlow-Connector/1.4 (label-proxy)',
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '' || !is_string($response) || $response === '') {
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return null;
        }

        return $this->isPdfContent($response) ? $response : null;
    }

    /**
     * Vérifie que le contenu commence par la signature PDF (%PDF).
     */
    private function isPdfContent(string $content): bool
    {
        return strncmp($content, '%PDF', 4) === 0;
    }
}
