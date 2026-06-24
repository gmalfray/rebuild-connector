<?php

defined('_PS_VERSION_') || exit;

/**
 * Service de vérification de mise à jour du module Rebuild Connector.
 *
 * Interroge l'endpoint updates.rebuild-it.fr une fois toutes les 12 h maximum
 * (cache via Configuration). En cas d'échec réseau ou de JSON invalide, le
 * service est silencieux : aucun bandeau n'est affiché, aucun log bruyant.
 */
class UpdateCheckService
{
    private const ENDPOINT = 'https://updates.rebuild-it.fr/rebuildconnector/version.json';
    private const CACHE_KEY = 'REBUILDCONNECTOR_UPDATE_CHECK';
    private const CACHE_TTL = 43200; // 12 heures en secondes
    private const REQUEST_TIMEOUT = 5; // secondes

    private string $currentVersion;

    public function __construct(string $currentVersion)
    {
        $this->currentVersion = $currentVersion;
    }

    /**
     * Retourne les informations de mise à jour disponible, ou null si le module
     * est à jour (ou si la vérification a échoué).
     *
     * @return array{latest: string, url: string, download_url: string}|null
     */
    public function getAvailableUpdate(): ?array
    {
        $cached = $this->getFromCache();

        if ($cached === null) {
            $cached = $this->fetchAndCache();
        }

        if ($cached === null) {
            return null;
        }

        $latest = isset($cached['latest']) && is_string($cached['latest']) ? $cached['latest'] : '';
        if ($latest === '') {
            return null;
        }

        if (version_compare($latest, $this->currentVersion, '>')) {
            return [
                'latest'       => $latest,
                'url'          => isset($cached['url']) && is_string($cached['url']) ? $cached['url'] : '',
                'download_url' => isset($cached['download_url']) && is_string($cached['download_url']) ? $cached['download_url'] : '',
            ];
        }

        return null;
    }

    /**
     * Retourne le cache s'il est encore valide (TTL 12 h), null sinon.
     *
     * @return array<string, mixed>|null
     */
    private function getFromCache(): ?array
    {
        $raw = Configuration::get(self::CACHE_KEY);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $checkedAt = isset($data['checked_at']) ? (int) $data['checked_at'] : 0;
        if ((time() - $checkedAt) >= self::CACHE_TTL) {
            return null;
        }

        return $data;
    }

    /**
     * Interroge l'endpoint distant, met en cache, et retourne les données.
     * Fail-silent : retourne null en cas d'erreur réseau ou de JSON invalide.
     *
     * @return array<string, mixed>|null
     */
    private function fetchAndCache(): ?array
    {
        $json = $this->fetchRemote();
        if ($json === null) {
            return null;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }

        $cacheData = [
            'checked_at'   => time(),
            'latest'       => isset($payload['latest']) && is_string($payload['latest']) ? $payload['latest'] : '',
            'url'          => isset($payload['url']) && is_string($payload['url']) ? $payload['url'] : '',
            'download_url' => isset($payload['download_url']) && is_string($payload['download_url']) ? $payload['download_url'] : '',
        ];

        $encoded = json_encode($cacheData);
        if (is_string($encoded)) {
            Configuration::updateValue(self::CACHE_KEY, $encoded);
        }

        return $cacheData;
    }

    /**
     * Effectue la requête HTTP vers l'endpoint de mise à jour.
     * Utilise cURL si disponible, sinon file_get_contents avec stream context.
     * Timeout court (5 s) — fail-silent sur tout échec.
     */
    private function fetchRemote(): ?string
    {
        if (function_exists('curl_init')) {
            return $this->fetchWithCurl();
        }

        return $this->fetchWithFileGetContents();
    }

    private function fetchWithCurl(): ?string
    {
        $ch = curl_init();
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => self::ENDPOINT,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'RebuildConnector/' . $this->currentVersion . ' PrestaShop/' . _PS_VERSION_,
        ]);

        $result = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $httpCode !== 200) {
            return null;
        }

        return is_string($result) ? $result : null;
    }

    private function fetchWithFileGetContents(): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout'       => self::REQUEST_TIMEOUT,
                'ignore_errors' => true,
                'user_agent'    => 'RebuildConnector/' . $this->currentVersion . ' PrestaShop/' . _PS_VERSION_,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ]);

        $result = @file_get_contents(self::ENDPOINT, false, $context);

        return ($result !== false && is_string($result)) ? $result : null;
    }
}
