<?php

defined('_PS_VERSION_') || exit;

class FcmService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private SettingsService $settingsService;

    public function __construct(?SettingsService $settingsService = null)
    {
        $this->settingsService = $settingsService ?: new SettingsService();
    }

    /**
     * @param array<int, mixed> $tokens
     * @param array<string, string> $notification
     * @param array<string, mixed> $data
     * @param array<int, string> $topics
     * @param array<int, mixed> $fallbackTokens
     */
    public function sendNotification(
        array $tokens,
        array $notification,
        array $data = [],
        array $topics = [],
        array $fallbackTokens = []
    ): bool {
        $primaryTokens = $this->sanitizeTokens($tokens);
        $fallbackTokens = $this->sanitizeTokens($fallbackTokens);
        $topics = $this->sanitizeTopics($topics);

        if ($primaryTokens === [] && $fallbackTokens === [] && $topics === []) {
            return false;
        }

        $serviceAccount = $this->getServiceAccount();
        if ($serviceAccount === null) {
            $this->log('FCM service account missing.');
            return false;
        }

        $projectId = isset($serviceAccount['project_id']) ? (string) $serviceAccount['project_id'] : '';
        if ($projectId === '') {
            $this->log('FCM project_id missing from service account.');
            return false;
        }

        $accessToken = $this->fetchAccessToken($serviceAccount);
        if ($accessToken === null) {
            $this->log('Unable to fetch FCM access token.');
            return false;
        }

        $normalizedData = $this->normalizeData($data);

        $topicDelivered = false;
        if ($topics !== []) {
            foreach ($topics as $topic) {
                $message = [
                    'message' => [
                        'topic' => $topic,
                        'notification' => $notification,
                    ],
                ];

                if ($normalizedData !== []) {
                    $message['message']['data'] = $normalizedData;
                }

                if ($this->dispatchMessage($projectId, $accessToken, $message)) {
                    $topicDelivered = true;
                } else {
                    $this->log(sprintf('FCM topic delivery failed for topic "%s".', $topic));
                }
            }
        }

        $tokenDelivered = false;
        if (!$topicDelivered && $primaryTokens !== []) {
            foreach ($primaryTokens as $token) {
                $message = [
                    'message' => [
                        'token' => $token,
                        'notification' => $notification,
                    ],
                ];

                if ($normalizedData !== []) {
                    $message['message']['data'] = $normalizedData;
                }

                if ($this->dispatchMessage($projectId, $accessToken, $message)) {
                    $tokenDelivered = true;
                } else {
                    $this->log(sprintf('FCM delivery failed for token "%s".', $token));
                }
            }
        }

        $fallbackDelivered = false;
        if (!$topicDelivered && !$tokenDelivered && $fallbackTokens !== []) {
            foreach ($fallbackTokens as $token) {
                $message = [
                    'message' => [
                        'token' => $token,
                        'notification' => $notification,
                    ],
                ];

                if ($normalizedData !== []) {
                    $message['message']['data'] = $normalizedData;
                }

                if ($this->dispatchMessage($projectId, $accessToken, $message)) {
                    $fallbackDelivered = true;
                } else {
                    $this->log(sprintf('FCM fallback delivery failed for token "%s".', $token));
                }
            }
        }

        return $topicDelivered || $tokenDelivered || $fallbackDelivered;
    }

    /**
     * @param array<int, mixed> $topics
     * @return array<int, string>
     */
    private function sanitizeTopics(array $topics): array
    {
        $normalized = [];
        foreach ($topics as $topic) {
            $topic = trim((string) $topic);
            if ($topic === '') {
                continue;
            }

            if (!preg_match('/^[A-Za-z0-9-_.~%]{1,900}$/', $topic)) {
                continue;
            }

            $normalized[] = $topic;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<int, mixed> $tokens
     * @return array<int, string>
     */
    private function sanitizeTokens(array $tokens): array
    {
        $cleaned = [];
        foreach ($tokens as $token) {
            $trimmed = trim((string) $token);
            if ($trimmed !== '') {
                $cleaned[] = $trimmed;
            }
        }

        return array_values(array_unique($cleaned));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getServiceAccount(): ?array
    {
        $account = $this->settingsService->getFcmServiceAccount();
        if ($account !== null) {
            return $account;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $serviceAccount
     */
    protected function fetchAccessToken(array $serviceAccount): ?string
    {
        if (!isset($serviceAccount['client_email'], $serviceAccount['private_key'])) {
            return null;
        }

        $now = time();
        $claims = [
            'iss' => (string) $serviceAccount['client_email'],
            'scope' => self::FCM_SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $jwt = $this->createSignedJwt($claims, (string) $serviceAccount['private_key']);
        if ($jwt === null) {
            return null;
        }

        $payload = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        $response = $this->performHttpRequest(self::TOKEN_URL, $payload, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        if ($response === null) {
            return null;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['access_token'])) {
            return null;
        }

        return is_string($decoded['access_token']) ? $decoded['access_token'] : null;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function createSignedJwt(array $claims, string $privateKey): ?string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];

        $headerSegment = $this->base64UrlEncode(json_encode($header));
        $claimsSegment = $this->base64UrlEncode(json_encode($claims));

        if ($headerSegment === null || $claimsSegment === null) {
            return null;
        }

        $signingInput = $headerSegment . '.' . $claimsSegment;

        $signature = '';
        $success = openssl_sign($signingInput, $signature, $privateKey, 'sha256');
        if (!$success || $signature === '') {
            return null;
        }

        $signatureSegment = $this->base64UrlEncode($signature);
        if ($signatureSegment === null) {
            return null;
        }

        return $signingInput . '.' . $signatureSegment;
    }

    /**
     * @param array<string, mixed> $message
     */
    protected function dispatchMessage(string $projectId, string $accessToken, array $message): bool
    {
        $endpoint = sprintf(
            'https://fcm.googleapis.com/v1/projects/%s/messages:send',
            rawurlencode($projectId)
        );

        $body = json_encode($message);
        if ($body === false) {
            return false;
        }

        $response = $this->performHttpRequest($endpoint, $body, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);

        if ($response === null) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function normalizeData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $normalized[$key] = (string) $value;
                continue;
            }

            $serialized = json_encode($value);
            if ($serialized !== false) {
                $normalized[$key] = $serialized;
            }
        }

        return $normalized;
    }

    /**
     * @param array<int, string> $headers
     */
    protected function performHttpRequest(string $url, string $body, array $headers): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($handle, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($handle);
        if ($response === false) {
            $this->log('cURL error: ' . curl_error($handle));
            curl_close($handle);
            return null;
        }

        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($status < 200 || $status >= 300) {
            $this->log(sprintf('FCM HTTP error %d for %s', $status, $url));
            return null;
        }

        return $response;
    }

    private function base64UrlEncode(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
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
