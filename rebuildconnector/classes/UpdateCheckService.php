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

    /**
     * Statuts explicites retournés par checkForUpdateFresh(), pour distinguer
     * « à jour » d'un échec de vérification (réseau/JSON) — les deux se traduisaient
     * auparavant par un simple `null`, ce qui trompait l'utilisateur du bouton BO
     * en cas d'échec réseau (message « vous êtes à jour » affiché à tort).
     */
    public const STATUS_UPDATE_AVAILABLE = 'update_available';
    public const STATUS_UP_TO_DATE = 'up_to_date';
    public const STATUS_CHECK_FAILED = 'check_failed';

    private string $currentVersion;

    public function __construct(string $currentVersion)
    {
        $this->currentVersion = $currentVersion;
    }

    /**
     * Retourne les informations de mise à jour disponible, ou null si le module
     * est à jour (ou si la vérification a échoué).
     * Utilise le cache local (TTL 12 h) — pour le bandeau automatique BO.
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

        return $this->extractUpdateFromData($cached);
    }

    /**
     * Force une vérification fraîche :
     *   - contourne le cache edge Cloudflare via ?nocache=1 sur l'endpoint distant ;
     *   - ignore le cache local (ps_configuration) et le remplace par la réponse fraîche.
     * À utiliser uniquement sur action explicite de l'utilisateur (bouton BO).
     *
     * Retourne un statut explicite distinguant :
     *   - STATUS_UPDATE_AVAILABLE : une version plus récente est disponible ;
     *   - STATUS_UP_TO_DATE       : la vérification a réussi et le module est à jour ;
     *   - STATUS_CHECK_FAILED     : la vérification a échoué (réseau/timeout/JSON invalide)
     *                               — ne doit PAS être interprété comme « à jour ».
     * À utiliser uniquement sur action explicite de l'utilisateur (bouton BO).
     *
     * @return array{status: self::STATUS_*, update: array{latest: string, url: string, download_url: string}|null}
     */
    public function checkForUpdateFresh(): array
    {
        $fresh = $this->fetchAndCache(true);

        if ($fresh === null) {
            return ['status' => self::STATUS_CHECK_FAILED, 'update' => null];
        }

        $update = $this->extractUpdateFromData($fresh);

        return [
            'status' => $update !== null ? self::STATUS_UPDATE_AVAILABLE : self::STATUS_UP_TO_DATE,
            'update' => $update,
        ];
    }

    /**
     * Extrait les informations de mise à jour depuis les données (cache ou fetch).
     *
     * @param array<string, mixed> $data
     * @return array{latest: string, url: string, download_url: string}|null
     */
    private function extractUpdateFromData(array $data): ?array
    {
        $latest = isset($data['latest']) && is_string($data['latest']) ? $data['latest'] : '';
        if ($latest === '') {
            return null;
        }

        if (version_compare($latest, $this->currentVersion, '>')) {
            return [
                'latest'       => $latest,
                'url'          => isset($data['url']) && is_string($data['url']) ? $data['url'] : '',
                'download_url' => isset($data['download_url']) && is_string($data['download_url']) ? $data['download_url'] : '',
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
     * @param bool $nocache Ajoute ?nocache=1 à l'URL pour bypasser le cache edge Cloudflare.
     * @return array<string, mixed>|null
     */
    private function fetchAndCache(bool $nocache = false): ?array
    {
        $url = $nocache ? self::ENDPOINT . '?nocache=1' : self::ENDPOINT;
        $json = $this->fetchRemote($url);
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
     * Effectue la requête HTTP vers l'URL fournie.
     * Utilise cURL si disponible, sinon file_get_contents avec stream context.
     * Timeout court (5 s) — fail-silent sur tout échec.
     *
     * `protected` (et non `private`) pour permettre aux tests unitaires de surcharger
     * cette méthode dans une sous-classe et simuler succès/échec sans appel réseau réel.
     */
    protected function fetchRemote(string $url): ?string
    {
        if (function_exists('curl_init')) {
            return $this->fetchWithCurl($url);
        }

        return $this->fetchWithFileGetContents($url);
    }

    private function fetchWithCurl(string $url): ?string
    {
        $ch = curl_init();
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
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

    private function fetchWithFileGetContents(string $url): ?string
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

        $result = @file_get_contents($url, false, $context);

        return ($result !== false && is_string($result)) ? $result : null;
    }
}
