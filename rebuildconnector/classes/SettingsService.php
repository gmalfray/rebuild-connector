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
        $settings['fcm_device_tokens'] = $tokens;
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
            'scopes' => $this->getScopes(),
            'scopes_text' => implode("\n", $this->getScopes()),
        ];
    }

    private function generateApiKey(): string
    {
        return Tools::passwdGen(40);
    }

    private function generateJwtSecret(): string
    {
        try {
            /** @var string $bytes */
            $bytes = random_bytes(32);
            return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        } catch (\Exception $exception) {
            return Tools::passwdGen(64);
        }
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
}
