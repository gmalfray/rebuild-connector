<?php

defined('_PS_VERSION_') || exit;

class SettingsService
{
    private const CONFIG_KEY = 'REBUILDCONNECTOR_SETTINGS';

    /**
     * URL du hub push centralisé. Hardcodée — les boutiques distribuées n'ont pas accès
     * au compte de service FCM, seul le hub y a accès.
     * Override DEV uniquement : définir la constante PHP `REBUILDCONNECTOR_HUB_URL_OVERRIDE`.
     */
    private const HUB_URL = 'https://push.rebuild-it.fr';
    private const DEFAULT_SCOPES = [
        'orders.read',
        'orders.write',
        'products.read',
        'products.write',
        'stock.write',
        'customers.read',
        'dashboard.read',
        'baskets.read',
        'reports.read',
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

        // Migration lazy à l'installation/upgrade : si une clé en clair existe sans hash, on migre.
        if (!empty($settings['api_key']) && is_string($settings['api_key']) && empty($settings['api_key_hash'])) {
            $settings['api_key_hash'] = password_hash($settings['api_key'], PASSWORD_BCRYPT, ['cost' => 12]);
            unset($settings['api_key']);
            $updated = true;
        }

        // Génère une clé hashée si aucune n'existe (installation initiale).
        if (empty($settings['api_key_hash']) && empty($settings['api_key'])) {
            $newKey = $this->generateApiKey();
            $settings['api_key_hash'] = password_hash($newKey, PASSWORD_BCRYPT, ['cost' => 12]);
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
            $settings['rate_limit_enabled'] = true;
            $updated = true;
        }

        if (!isset($settings['rate_limit'])) {
            $settings['rate_limit'] = 60;
            $updated = true;
        }

        if (!isset($settings['hub_license_key']) || !is_string($settings['hub_license_key'])) {
            $settings['hub_license_key'] = '';
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

    /**
     * Retourne la clé en clair UNIQUEMENT si encore présente (ancienne installation, migration lazy
     * pas encore déclenchée). Ne doit PAS être utilisé pour exposer la clé en clair au BO ou à
     * l'API — réservé à la migration interne dans verifyApiKey().
     *
     * @internal
     */
    public function getApiKey(): ?string
    {
        $settings = $this->all();
        if (!isset($settings['api_key']) || !is_string($settings['api_key'])) {
            return null;
        }

        return $settings['api_key'];
    }

    /**
     * Indique si une clé Admin est configurée (hash ou clair legacy). Utilisé pour l'indicateur
     * d'état dans le BO.
     */
    public function hasApiKey(): bool
    {
        $settings = $this->all();
        $hasHash = !empty($settings['api_key_hash']) && is_string($settings['api_key_hash']);
        $hasClear = !empty($settings['api_key']) && is_string($settings['api_key']);

        return $hasHash || $hasClear;
    }

    /**
     * Stocke la clé sous forme de hash bcrypt dans api_key_hash et supprime le clair.
     */
    public function setApiKey(string $apiKey): void
    {
        $settings = $this->all();
        $settings['api_key_hash'] = password_hash($apiKey, PASSWORD_BCRYPT, ['cost' => 12]);
        unset($settings['api_key']);
        $this->save($settings);
    }

    /**
     * Vérifie une clé API Admin avec migration lazy transparente.
     *
     * - Si api_key_hash présent : password_verify().
     * - Si seulement api_key en clair (ancienne installation) : hash_equals() + migration immédiate
     *   vers le hash si la clé est correcte.
     */
    public function verifyApiKey(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        $settings = $this->all();

        // Chemin normal : hash bcrypt présent.
        if (!empty($settings['api_key_hash']) && is_string($settings['api_key_hash'])) {
            return password_verify($key, $settings['api_key_hash']);
        }

        // Migration lazy : clé en clair encore présente (ancienne installation).
        if (!empty($settings['api_key']) && is_string($settings['api_key'])) {
            if (!hash_equals($settings['api_key'], $key)) {
                return false;
            }
            // Clé correcte → migrer immédiatement vers le hash, supprimer le clair.
            $settings['api_key_hash'] = password_hash($key, PASSWORD_BCRYPT, ['cost' => 12]);
            unset($settings['api_key']);
            $this->save($settings);

            return true;
        }

        return false;
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

    /**
     * @throws \InvalidArgumentException si l'URL n'est pas HTTPS (ou vide pour désactiver).
     */
    public function setWebhookUrl(string $url): void
    {
        $url = trim($url);
        if ($url !== '' && stripos($url, 'https://') !== 0) {
            throw new \InvalidArgumentException('L\'URL webhook doit utiliser HTTPS.');
        }

        $settings = $this->all();
        $settings['webhook_url'] = $url;
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
            'api_key_configured' => $this->hasApiKey(),
            'token_ttl' => isset($settings['token_ttl']) ? (int) $settings['token_ttl'] : 3600,
            'jwt_secret_preview' => $this->renderSecretPreview(
                isset($settings['jwt_secret']) && is_string($settings['jwt_secret'])
                    ? $settings['jwt_secret']
                    : ''
            ),
            'scopes' => $this->getScopes(),
            'scopes_text' => implode("\n", $this->getScopes()),
            'webhook_url' => $this->getWebhookUrl(),
            'webhook_secret_preview' => $this->renderSecretPreview($this->getWebhookSecret()),
            'allowed_ips' => $this->getAllowedIpRangesRaw(),
            'rate_limit_enabled' => $this->isRateLimitEnabled(),
            'rate_limit' => $this->getRateLimit(),
            'env_overrides' => $this->getEnvOverridesRaw(),
            'hub_url' => $this->getHubUrl(),
            'hub_license_key_preview' => $this->renderSecretPreview($this->getHubLicenseKey()),
            'hub_enabled' => $this->isHubEnabled(),
        ];
    }

    /* ───────────── Hub push centralisé (push.rebuild-it.fr) ───────────── */

    /**
     * URL du hub push. Hardcodée — les boutiques distribuées n'ont pas de compte FCM.
     * Override DEV uniquement via la constante PHP REBUILDCONNECTOR_HUB_URL_OVERRIDE.
     */
    public function getHubUrl(): string
    {
        if (defined('REBUILDCONNECTOR_HUB_URL_OVERRIDE')) {
            $override = constant('REBUILDCONNECTOR_HUB_URL_OVERRIDE');
            if (is_string($override) && $override !== '') {
                return $override;
            }
        }

        return self::HUB_URL;
    }

    public function getHubLicenseKey(): string
    {
        $settings = $this->all();
        return isset($settings['hub_license_key']) && is_string($settings['hub_license_key'])
            ? trim($settings['hub_license_key'])
            : '';
    }

    public function setHubLicenseKey(string $key): void
    {
        $settings = $this->all();
        $settings['hub_license_key'] = trim($key);
        $this->save($settings);
    }

    public function clearHubLicenseKey(): void
    {
        $settings = $this->all();
        $settings['hub_license_key'] = '';
        $this->save($settings);
    }

    /**
     * Le mode hub est actif dès qu'une clé de licence est configurée.
     * L'URL du hub est hardcodée (push.rebuild-it.fr) — les boutiques distribuées n'ont pas
     * accès au compte de service FCM, la résilience est gérée côté hub.
     */
    public function isHubEnabled(): bool
    {
        return $this->getHubLicenseKey() !== '';
    }

    /**
     * Régénère la clé API globale Admin. Stocke le hash bcrypt (jamais le clair en base) et
     * retourne le clair UNE SEULE FOIS pour affichage one-time dans le BO.
     */
    public function regenerateApiKey(): string
    {
        $newKey = $this->generateApiKey();
        $settings = $this->all();
        $settings['api_key_hash'] = password_hash($newKey, PASSWORD_BCRYPT, ['cost' => 12]);
        unset($settings['api_key']); // S'assure que l'ancien clair legacy est supprimé.
        $this->save($settings);

        return $newKey;
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
