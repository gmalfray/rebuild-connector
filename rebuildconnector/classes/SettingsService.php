<?php

defined('_PS_VERSION_') || exit;

class SettingsService
{
    private const CONFIG_KEY = 'REBUILDCONNECTOR_SETTINGS';
    private const DEFAULT_SCOPES = [
        'orders.read',
        'orders.write',
        'products.read',
        'products.write',
        'stock.write',
        'customers.read',
        'dashboard.read',
        'baskets.read',
        'notifications.send',
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $raw = Configuration::get(self::CONFIG_KEY);
        if (!is_string($raw) || $raw === '') {
            $this->cache = [];
            return $this->cache;
        }

        $decoded = json_decode($raw, true);
        $this->cache = is_array($decoded) ? $decoded : [];

        return $this->cache;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function save(array $settings): void
    {
        $this->cache = $settings;
        Configuration::updateValue(
            self::CONFIG_KEY,
            json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function ensureDefaults(): void
    {
        $settings = $this->all();
        $updated = false;

        if (empty($settings['api_key']) || !is_string($settings['api_key'])) {
            $settings['api_key'] = $this->generateApiKey();
            $updated = true;
        }

        if (empty($settings['jwt_secret']) || !is_string($settings['jwt_secret'])) {
            $settings['jwt_secret'] = $this->generateJwtSecret();
            $updated = true;
        }

        if (empty($settings['token_ttl']) || !is_numeric($settings['token_ttl'])) {
            $settings['token_ttl'] = 3600;
            $updated = true;
        }

        if (!isset($settings['scopes']) || !is_array($settings['scopes'])) {
            $settings['scopes'] = self::DEFAULT_SCOPES;
            $updated = true;
        }

        if (!isset($settings['webhook_url']) || !is_string($settings['webhook_url'])) {
            $settings['webhook_url'] = '';
            $updated = true;
        }

        if (!isset($settings['webhook_secret']) || !is_string($settings['webhook_secret'])) {
            $settings['webhook_secret'] = '';
            $updated = true;
        }

        if (!isset($settings['allowed_ips'])) {
            $settings['allowed_ips'] = '';
            $updated = true;
        }

        if (!isset($settings['rate_limit_enabled'])) {
            $settings['rate_limit_enabled'] = false;
            $updated = true;
        }

        if (!isset($settings['rate_limit'])) {
            $settings['rate_limit'] = 60;
            $updated = true;
        }

        if (!isset($settings['env_overrides'])) {
            $settings['env_overrides'] = '';
            $updated = true;
        }

        if ($updated) {
            $this->save($settings);
        }
    }

    public function getApiKey(): ?string
    {
        $settings = $this->all();
        if (!isset($settings['api_key']) || !is_string($settings['api_key'])) {
            return null;
        }

        return $settings['api_key'];
    }

    public function setApiKey(string $apiKey): void
    {
        $settings = $this->all();
        $settings['api_key'] = $apiKey;
        $this->save($settings);
    }

    public function getJwtSecret(): string
    {
        $settings = $this->all();
        if (!isset($settings['jwt_secret']) || !is_string($settings['jwt_secret']) || $settings['jwt_secret'] === '') {
            $settings['jwt_secret'] = $this->generateJwtSecret();
            $this->save($settings);
        }

        return $settings['jwt_secret'];
    }

    public function regenerateJwtSecret(): string
    {
        $settings = $this->all();
        $settings['jwt_secret'] = $this->generateJwtSecret();
        $this->save($settings);

        return $settings['jwt_secret'];
    }

    public function getTokenTtl(): int
    {
        $settings = $this->all();
        if (!isset($settings['token_ttl'])) {
            return 3600;
        }

        $ttl = (int) $settings['token_ttl'];
        if ($ttl <= 0) {
            $ttl = 3600;
        }

        return $ttl;
    }

    public function setTokenTtl(int $ttl): void
    {
        $ttl = max(300, $ttl);
        $settings = $this->all();
        $settings['token_ttl'] = $ttl;
        $this->save($settings);
    }

    /**
     * @return array<int, string>
     */
    public function getScopes(): array
    {
        $settings = $this->all();
        if (!isset($settings['scopes']) || !is_array($settings['scopes'])) {
            return self::DEFAULT_SCOPES;
        }

        $scopes = [];
        foreach ($settings['scopes'] as $scope) {
            if (is_string($scope) && $scope !== '') {
                $scopes[] = $scope;
            }
        }

        return $scopes === [] ? self::DEFAULT_SCOPES : array_values(array_unique($scopes));
    }

    /**
     * @param array<int, string> $scopes
     */
    public function setScopes(array $scopes): void
    {
        $normalized = [];
        foreach ($scopes as $scope) {
            $trimmed = trim((string) $scope);
            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }

        $settings = $this->all();
        $settings['scopes'] = $normalized === [] ? self::DEFAULT_SCOPES : array_values(array_unique($normalized));
        $this->save($settings);
    }

    public function setScopesFromString(string $scopes): void
    {
        $parts = preg_split('/[\r\n,]+/', $scopes) ?: [];
        $this->setScopes($parts);
    }

    public function getWebhookUrl(): string
    {
        $settings = $this->all();
        if (!isset($settings['webhook_url']) || !is_string($settings['webhook_url'])) {
            return '';
        }

        return trim($settings['webhook_url']);
    }

    public function setWebhookUrl(string $url): void
    {
        $settings = $this->all();
        $settings['webhook_url'] = trim($url);
        $this->save($settings);
    }

    public function getWebhookSecret(): string
    {
        $settings = $this->all();
        if (!isset($settings['webhook_secret']) || !is_string($settings['webhook_secret'])) {
            return '';
        }

        return (string) $settings['webhook_secret'];
    }

    public function setWebhookSecret(string $secret): void
    {
        $settings = $this->all();
        $settings['webhook_secret'] = $secret;
        $this->save($settings);
    }

    public function clearWebhookSecret(): void
    {
        $settings = $this->all();
        $settings['webhook_secret'] = '';
        $this->save($settings);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getFcmServiceAccount(): ?array
    {
        $settings = $this->all();
        if (!isset($settings['fcm_service_account'])) {
            return null;
        }

        $account = $settings['fcm_service_account'];
        if (is_array($account)) {
            return $account;
        }

        if (is_string($account)) {
            $decoded = json_decode($account, true);
            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    public function setFcmServiceAccount(?string $json): void
    {
        $settings = $this->all();
        $settings['fcm_service_account'] = $json !== null ? trim($json) : null;
        $this->save($settings);
    }

    public function getFcmDeviceTokensRaw(): string
    {
        $settings = $this->all();
        if (!isset($settings['fcm_device_tokens'])) {
            return '';
        }

        $tokens = $settings['fcm_device_tokens'];
        if (is_string($tokens)) {
            return $tokens;
        }

        if (is_array($tokens)) {
            return implode("\n", $tokens);
        }

        return '';
    }

    public function setFcmDeviceTokens(string $tokens): void
    {
        $settings = $this->all();
        $settings['fcm_device_tokens'] = trim($tokens);
        $this->save($settings);
    }

    /**
     * @return array<int, string>
     */
    public function getFcmDeviceTokens(): array
    {
        $settings = $this->all();
        if (!isset($settings['fcm_device_tokens'])) {
            return [];
        }

        $raw = $settings['fcm_device_tokens'];
        if (is_string($raw)) {
            $pieces = preg_split('/[\r\n,]+/', $raw) ?: [];
        } elseif (is_array($raw)) {
            $pieces = $raw;
        } else {
            return [];
        }

        $tokens = [];
        foreach ($pieces as $token) {
            if (!is_string($token)) {
                continue;
            }
            $trimmed = trim($token);
            if ($trimmed !== '') {
                $tokens[] = $trimmed;
            }
        }

        return array_values(array_unique($tokens));
    }

    public function getFcmTopicsRaw(): string
    {
        $settings = $this->all();
        if (!isset($settings['fcm_topics'])) {
            return '';
        }

        $topics = $settings['fcm_topics'];
        if (is_string($topics)) {
            return trim($topics);
        }

        if (is_array($topics)) {
            return implode("\n", $this->sanitizeTopics($topics));
        }

        return '';
    }

    public function setFcmTopics(string $topics): void
    {
        $settings = $this->all();
        $parts = preg_split('/[\r\n,]+/', $topics) ?: [];
        $settings['fcm_topics'] = implode("\n", $this->sanitizeTopics($parts));
        $this->save($settings);
    }

    /**
     * @return array<int, string>
     */
    public function getFcmTopics(): array
    {
        $settings = $this->all();
        if (!isset($settings['fcm_topics'])) {
            return [];
        }

        $raw = $settings['fcm_topics'];
        if (is_string($raw)) {
            $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        } elseif (is_array($raw)) {
            $parts = $raw;
        } else {
            return [];
        }

        return $this->sanitizeTopics($parts);
    }

    public function getAllowedIpRangesRaw(): string
    {
        $settings = $this->all();
        if (!isset($settings['allowed_ips'])) {
            return '';
        }

        $value = $settings['allowed_ips'];
        if (is_array($value)) {
            return implode("\n", array_map('trim', $value));
        }

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return array<int, string>
     */
    public function getAllowedIpRanges(): array
    {
        $raw = $this->getAllowedIpRangesRaw();
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];

        $ranges = [];
        foreach ($parts as $part) {
            $normalized = $this->sanitizeIpRange((string) $part);
            if ($normalized !== null) {
                $ranges[] = $normalized;
            }
        }

        return array_values(array_unique($ranges));
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function setAllowedIpRanges(string $list): void
    {
        $parts = preg_split('/[\r\n,]+/', $list) ?: [];
        $normalized = [];
        foreach ($parts as $part) {
            $candidate = $this->sanitizeIpRange((string) $part);
            if ($candidate === null && trim((string) $part) !== '') {
                throw new \InvalidArgumentException('Invalid IP range: ' . $part);
            }
            if ($candidate !== null) {
                $normalized[] = $candidate;
            }
        }

        $settings = $this->all();
        $settings['allowed_ips'] = implode("\n", array_values(array_unique($normalized)));
        $this->save($settings);
    }

    public function isRateLimitEnabled(): bool
    {
        $settings = $this->all();
        return isset($settings['rate_limit_enabled']) ? (bool) $settings['rate_limit_enabled'] : false;
    }

    public function setRateLimitEnabled(bool $enabled): void
    {
        $settings = $this->all();
        $settings['rate_limit_enabled'] = $enabled;
        $this->save($settings);
    }

    public function getRateLimit(): int
    {
        $settings = $this->all();
        $limit = isset($settings['rate_limit']) ? (int) $settings['rate_limit'] : 60;

        return $limit > 0 ? $limit : 60;
    }

    public function setRateLimit(int $limit): void
    {
        $limit = max(1, $limit);
        $settings = $this->all();
        $settings['rate_limit'] = $limit;
        $this->save($settings);
    }

    public function getEnvOverridesRaw(): string
    {
        $settings = $this->all();
        if (!isset($settings['env_overrides'])) {
            return '';
        }

        return is_string($settings['env_overrides']) ? trim($settings['env_overrides']) : '';
    }

    /**
     * @return array<string, string>
     */
    public function getEnvOverrides(): array
    {
        return $this->parseEnvOverrides($this->getEnvOverridesRaw());
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function setEnvOverrides(string $overrides): void
    {
        $normalized = $this->parseEnvOverrides($overrides, true);

        $settings = $this->all();
        $settings['env_overrides'] = $normalized === []
            ? ''
            : implode("\n", array_map(
                static function ($key, $value): string {
                    return $key . '=' . $value;
                },
                array_keys($normalized),
                $normalized
            ));
        $this->save($settings);
    }

    /**
     * @return array<string, mixed>
     */
    public function exportForTemplate(): array
    {
        $settings = $this->all();

        return [
            'api_key' => isset($settings['api_key']) && is_string($settings['api_key']) ? $settings['api_key'] : '',
            'token_ttl' => isset($settings['token_ttl']) ? (int) $settings['token_ttl'] : 3600,
            'jwt_secret_preview' => $this->renderSecretPreview(
                isset($settings['jwt_secret']) && is_string($settings['jwt_secret'])
                    ? $settings['jwt_secret']
                    : ''
            ),
            'fcm_service_account' => isset($settings['fcm_service_account']) && is_string($settings['fcm_service_account'])
                ? $settings['fcm_service_account']
                : '',
            'fcm_device_tokens' => $this->getFcmDeviceTokensRaw(),
            'fcm_topics' => $this->getFcmTopicsRaw(),
            'fcm_topics_list' => $this->getFcmTopics(),
            'scopes' => $this->getScopes(),
            'scopes_text' => implode("\n", $this->getScopes()),
            'webhook_url' => $this->getWebhookUrl(),
            'webhook_secret_preview' => $this->renderSecretPreview($this->getWebhookSecret()),
            'allowed_ips' => $this->getAllowedIpRangesRaw(),
            'rate_limit_enabled' => $this->isRateLimitEnabled(),
            'rate_limit' => $this->getRateLimit(),
            'env_overrides' => $this->getEnvOverridesRaw(),
        ];
    }

    private function generateApiKey(): string
    {
        return Tools::passwdGen(40);
    }

    private function generateJwtSecret(): string
    {
        try {
            $bytes = random_bytes(32);
        } catch (\Exception $exception) {
            return Tools::passwdGen(64);
        }

        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function renderSecretPreview(string $secret): string
    {
        if ($secret === '') {
            return '';
        }

        $length = Tools::strlen($secret);
        if ($length <= 8) {
            return $secret;
        }

        return Tools::substr($secret, 0, 4) . str_repeat('•', max(0, $length - 8)) . Tools::substr($secret, -4);
    }

    public function clearCache(): void
    {
        $this->cache = null;
    }

    /**
     * @param array<int, string> $topics
     * @return array<int, string>
     */
    private function sanitizeTopics(array $topics): array
    {
        $normalized = [];
        foreach ($topics as $topic) {
            $topic = trim($topic);
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

    private function sanitizeIpRange(string $range): ?string
    {
        $range = trim($range);
        if ($range === '') {
            return null;
        }

        if (strpos($range, '/') === false) {
            return filter_var($range, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)
                ? $range
                : null;
        }

        [$ip, $mask] = explode('/', $range, 2);
        $ip = trim($ip);
        $mask = trim($mask);

        if ($ip === '' || $mask === '') {
            return null;
        }

        $validatedIp = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6);
        if ($validatedIp === false) {
            return null;
        }

        if (!ctype_digit($mask)) {
            return null;
        }

        $maskValue = (int) $mask;
        $maxMask = strpos($validatedIp, ':') !== false ? 128 : 32;
        if ($maskValue < 0 || $maskValue > $maxMask) {
            return null;
        }

        return $validatedIp . '/' . $maskValue;
    }

    /**
     * @return array<string, string>
     * @throws \InvalidArgumentException
     */
    private function parseEnvOverrides(string $raw, bool $validate = false): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $result = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            $position = strpos($line, '=');
            if ($position === false) {
                if ($validate) {
                    throw new \InvalidArgumentException('Invalid env override line: ' . $line);
                }
                continue;
            }

            $key = trim(substr($line, 0, $position));
            $value = trim(substr($line, $position + 1));

            if ($key === '') {
                if ($validate) {
                    throw new \InvalidArgumentException('Invalid env override line: ' . $line);
                }

                continue;
            }

            if (!preg_match('/^[A-Z0-9_]+$/', $key)) {
                if ($validate) {
                    throw new \InvalidArgumentException('Invalid env key: ' . $key);
                }

                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
