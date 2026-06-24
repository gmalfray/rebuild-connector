<?php

defined('_PS_VERSION_') || exit;

/**
 * Relai vers le hub push centralisé Rebuild IT (push.rebuild-it.fr).
 *
 * Quand un `hub_url` + une `hub_license_key` sont configurés, le module relaie au hub :
 *   - l'enregistrement / la suppression des devices (POST/DELETE /v1/devices) ;
 *   - l'envoi des notifications (POST /v1/notify).
 *
 * Le hub détient le compte de service FCM (unique, Rebuild IT) et envoie réellement à FCM.
 * Le module ne porte donc plus de secret FCM dans ce mode. Le FCM direct (FcmService) reste
 * disponible en **fallback** : si l'appel au hub échoue techniquement (réseau / HTTP non 2xx),
 * l'appelant retombe sur l'envoi direct pour ne pas perdre la notification.
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
     * @return bool true si le hub a accepté la requête (HTTP 2xx), false sinon (→ fallback FCM direct)
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
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>|null Réponse décodée si HTTP 2xx, null sinon.
     */
    protected function request(string $method, string $path, ?array $body): ?array
    {
        if (!$this->isEnabled() || !function_exists('curl_init')) {
            return null;
        }

        $base = rtrim($this->settingsService->getHubUrl(), '/');
        $handle = curl_init($base . $path);
        if ($handle === false) {
            return null;
        }

        $headers = [
            'Authorization: Bearer ' . $this->settingsService->getHubLicenseKey(),
            'Accept: application/json',
        ];

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_TIMEOUT, self::TIMEOUT);

        if ($body !== null) {
            $encoded = json_encode($body);
            if ($encoded === false) {
                curl_close($handle);
                return null;
            }
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_POSTFIELDS, $encoded);
        }

        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($handle);
        if ($response === false) {
            $this->log('Hub cURL error: ' . curl_error($handle));
            curl_close($handle);
            return null;
        }

        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($status < 200 || $status >= 300) {
            $this->log(sprintf('Hub HTTP error %d on %s %s', $status, $method, $path));
            return null;
        }

        $decoded = json_decode(is_string($response) ? $response : '', true);

        return is_array($decoded) ? $decoded : [];
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
