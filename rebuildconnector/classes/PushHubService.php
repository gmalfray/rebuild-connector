<?php

defined('_PS_VERSION_') || exit;

/**
 * Relai vers le hub push centralisé Rebuild IT (push.rebuild-it.fr).
 *
 * Le module relaie au hub :
 *   - l'enregistrement / la suppression des devices (POST/DELETE /v1/devices) ;
 *   - l'envoi des notifications (POST /v1/notify).
 *
 * Le hub détient le compte de service FCM (unique, Rebuild IT) et envoie réellement à FCM.
 * Le module ne porte aucun secret FCM — la résilience est gérée côté hub.
 * L'URL du hub est hardcodée dans SettingsService::HUB_URL ; seule la clé de licence
 * est configurable en back-office.
 *
 * Auth : header `Authorization: Bearer <hub_license_key>`.
 */
class PushHubService
{
    private const TIMEOUT = 10;

    private SettingsService $settingsService;

    public function __construct(?SettingsService $settingsService = null)
    {
        $this->settingsService = $settingsService ?: new SettingsService();
    }

    /**
     * Le mode hub est actif quand l'URL et la clé de licence sont toutes deux configurées.
     */
    public function isEnabled(): bool
    {
        return $this->settingsService->isHubEnabled();
    }

    /**
     * Relaie une notification au hub.
     *
     * @param array<string, string> $notification ['title' => ..., 'body' => ...]
     * @param array<string, mixed>  $data
     *
     * @return bool true si le hub a accepté la requête (HTTP 2xx), false sinon
     */
    public function notify(string $event, array $notification, array $data = []): bool
    {
        $payload = [
            'event' => $event,
            'title' => isset($notification['title']) ? (string) $notification['title'] : '',
            'body' => isset($notification['body']) ? (string) $notification['body'] : '',
            'data' => $this->normalizeData($data),
        ];

        return $this->request('POST', '/v1/notify', $payload) !== null;
    }

    /**
     * Relaie l'enregistrement (ou la mise à jour) d'un device au hub. Best-effort.
     *
     * @param array<int, string> $topics
     */
    public function registerDevice(string $token, ?string $platform, array $topics): bool
    {
        $payload = [
            'fcm_token' => $token,
            'platform' => $platform !== null && $platform !== '' ? $platform : 'android',
            'topics' => array_values($topics),
        ];

        return $this->request('POST', '/v1/devices', $payload) !== null;
    }

    /**
     * Relaie la suppression d'un device au hub. Best-effort.
     */
    public function unregisterDevice(string $token): bool
    {
        return $this->request('DELETE', '/v1/devices/' . rawurlencode($token), null) !== null;
    }

    /**
     * Synchronise (backfill) tous les devices existants en base vers le hub.
     *
     * Idempotent : le hub fait un upsert à chaque appel de /v1/devices.
     * Best-effort : un échec sur un device n'interrompt pas les suivants.
     * Itère ligne par ligne sans tout charger en mémoire (SELECT paginé par lot).
     *
     * @param FcmDeviceService $fcmDeviceService
     * @return array{synced: int, failed: int, skipped: int}
     */
    public function syncAllDevices(FcmDeviceService $fcmDeviceService): array
    {
        $result = ['synced' => 0, 'failed' => 0, 'skipped' => 0];

        if (!$this->isEnabled()) {
            return $result;
        }

        $offset = 0;
        $batchSize = 50;

        do {
            $rows = $fcmDeviceService->getDevicesBatch($offset, $batchSize);

            foreach ($rows as $row) {
                $token = isset($row['token']) ? trim((string) $row['token']) : '';
                if ($token === '') {
                    ++$result['skipped'];
                    continue;
                }

                $platform = isset($row['platform']) && is_string($row['platform']) && $row['platform'] !== ''
                    ? $row['platform']
                    : null;

                $topics = FcmDeviceService::decodeTopicsStatic(
                    isset($row['topics']) ? (string) $row['topics'] : '[]'
                );

                if ($this->registerDevice($token, $platform, $topics)) {
                    ++$result['synced'];
                } else {
                    ++$result['failed'];
                    $this->log(sprintf('Hub backfill: échec pour le token %.20s…', $token));
                }
            }

            $offset += $batchSize;
        } while (count($rows) === $batchSize);

        $this->log(sprintf(
            'Hub backfill terminé : %d synchronisés, %d échecs, %d ignorés.',
            $result['synced'],
            $result['failed'],
            $result['skipped']
        ));

        return $result;
    }

    /**
     * Auto-provisionnement « zéro config » d'une licence hub d'essai pour cette boutique.
     *
     * Endpoint public du hub (aucune authentification), rate-limité côté hub (5/h/IP) :
     *   - HTTP 201 → licence créée, `license_key` renvoyée UNE seule fois ;
     *   - HTTP 409 → une licence existe déjà pour ce domaine (`reason: already_exists`), la clé
     *     n'est PAS renvoyée par le hub — l'admin doit la ressaisir manuellement ou contacter le hub ;
     *   - toute autre situation (timeout, réseau, 4xx/5xx) → échec best-effort.
     *
     * Ne logge JAMAIS la clé obtenue.
     *
     * @return array{provisioned: bool, reason: ?string, license_key: ?string}
     */
    public function provisionLicenseDetailed(string $shopUrl, ?string $label = null): array
    {
        $shopUrl = trim($shopUrl);
        if ($shopUrl === '') {
            return ['provisioned' => false, 'reason' => 'invalid_shop_url', 'license_key' => null];
        }

        $payload = ['shop_url' => $shopUrl];
        $label = $label !== null ? trim($label) : '';
        if ($label !== '') {
            $payload['label'] = $label;
        }

        $result = $this->performRequest('POST', '/v1/licenses/provision', $payload, false);

        if ($result['status'] === 201) {
            $licenseKey = isset($result['body']['license_key']) && is_string($result['body']['license_key'])
                ? $result['body']['license_key']
                : '';

            if ($licenseKey === '') {
                $this->log('Hub provision: réponse 201 sans license_key exploitable.');
                return ['provisioned' => false, 'reason' => 'invalid_response', 'license_key' => null];
            }

            return ['provisioned' => true, 'reason' => null, 'license_key' => $licenseKey];
        }

        if ($result['status'] === 409) {
            $this->log('Hub provision: licence déjà existante pour ce domaine.');

            return ['provisioned' => false, 'reason' => 'already_exists', 'license_key' => null];
        }

        if ($result['status'] > 0) {
            $this->log(sprintf('Hub provision HTTP error %d', $result['status']));
        } else {
            $this->log('Hub provision: erreur réseau ou cURL indisponible.');
        }

        return ['provisioned' => false, 'reason' => 'network_error', 'license_key' => null];
    }

    /**
     * Variante simple de {@see provisionLicenseDetailed()} : retourne la clé obtenue, ou null
     * si le hub n'a rien provisionné (déjà existant, erreur réseau, réponse invalide…).
     *
     * Best-effort — ne lève jamais d'exception métier (les erreurs cURL sont absorbées).
     */
    public function provisionLicense(string $shopUrl, ?string $label = null): ?string
    {
        return $this->provisionLicenseDetailed($shopUrl, $label)['license_key'];
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>|null Réponse décodée si HTTP 2xx, null sinon.
     */
    protected function request(string $method, string $path, ?array $body): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $result = $this->performRequest($method, $path, $body, true);

        if ($result['status'] < 200 || $result['status'] >= 300) {
            if ($result['status'] > 0) {
                $this->log(sprintf('Hub HTTP error %d on %s %s', $result['status'], $method, $path));
            }

            return null;
        }

        return $result['body'];
    }

    /**
     * Bas niveau : effectue l'appel HTTP vers le hub et retourne le statut + le corps décodé,
     * sans interpréter le résultat (fait par les appelants : `request()` / `provisionLicenseDetailed()`).
     *
     * @param array<string, mixed>|null $body
     *
     * @return array{status: int, body: array<string, mixed>} status = 0 si la requête n'a même pas pu partir.
     */
    protected function performRequest(string $method, string $path, ?array $body, bool $authenticated): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => []];
        }

        $base = rtrim($this->settingsService->getHubUrl(), '/');
        $handle = curl_init($base . $path);
        if ($handle === false) {
            return ['status' => 0, 'body' => []];
        }

        $headers = ['Accept: application/json'];
        if ($authenticated) {
            $headers[] = 'Authorization: Bearer ' . $this->settingsService->getHubLicenseKey();
        }

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_TIMEOUT, self::TIMEOUT);

        if ($body !== null) {
            $encoded = json_encode($body);
            if ($encoded === false) {
                curl_close($handle);
                return ['status' => 0, 'body' => []];
            }
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_POSTFIELDS, $encoded);
        }

        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($handle);
        if ($response === false) {
            $this->log('Hub cURL error: ' . curl_error($handle));
            curl_close($handle);

            return ['status' => 0, 'body' => []];
        }

        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        $decoded = json_decode(is_string($response) ? $response : '', true);

        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private function normalizeData(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $normalized[(string) $key] = (string) $value;
                continue;
            }

            $serialized = json_encode($value);
            if ($serialized !== false) {
                $normalized[(string) $key] = $serialized;
            }
        }

        return $normalized;
    }

    private function log(string $message): void
    {
        if (!$this->isDevMode()) {
            return;
        }

        error_log('[RebuildConnector] ' . $message);
    }

    private function isDevMode(): bool
    {
        if (!defined('_PS_MODE_DEV_')) {
            return false;
        }

        return (bool) constant('_PS_MODE_DEV_');
    }
}
