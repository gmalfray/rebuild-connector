<?php

defined('_PS_VERSION_') || exit;

class FcmDeviceService
{
    public const TABLE_NAME = 'rebuildconnector_fcm_device';

    /**
     * @param array<int, string> $topics
     * @return array<int, string>
     */
    public function getTokens(array $topics = []): array
    {
        $topics = self::sanitizeTopics($topics);

        $sql = sprintf(
            'SELECT `token`, `topics` FROM `%s%s`',
            _DB_PREFIX_,
            self::TABLE_NAME
        );

        /** @var array<int, array<string, mixed>> $rows */
        $rows = Db::getInstance()->executeS($sql) ?: [];

        $tokens = [];
        foreach ($rows as $row) {
            $token = isset($row['token']) ? trim((string) $row['token']) : '';
            if ($token === '') {
                continue;
            }

            if ($topics === []) {
                $tokens[] = $token;
                continue;
            }

            $deviceTopics = $this->decodeTopics(
                isset($row['topics']) ? (string) $row['topics'] : '[]'
            );

            if (array_intersect($deviceTopics, $topics) !== []) {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param array<int, string> $topics
     */
    public function registerDevice(string $token, array $topics = [], ?string $deviceId = null, ?string $platform = null): void
    {
        $token = trim($token);
        if ($token === '') {
            return;
        }

        $topics = self::sanitizeTopics($topics);
        $now = date('Y-m-d H:i:s');

        $topicsPayload = json_encode($topics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($topicsPayload === false) {
            $topicsPayload = '[]';
        }

        $data = [
            'token' => pSQL($token),
            'topics' => pSQL($topicsPayload),
            'device_id' => $deviceId !== null ? pSQL(Tools::substr($deviceId, 0, 191)) : null,
            'platform' => $platform !== null ? pSQL(Tools::substr($platform, 0, 32)) : null,
            'date_upd' => pSQL($now),
        ];

        $existingId = $this->findIdByToken($token);

        if ($existingId !== null) {
            Db::getInstance()->update(
                self::TABLE_NAME,
                $data,
                '`id_rebuildconnector_fcm_device` = ' . (int) $existingId
            );

            return;
        }

        $data['date_add'] = pSQL($now);

        Db::getInstance()->insert(self::TABLE_NAME, $data);
    }

    public function unregisterDevice(string $token): void
    {
        $token = trim($token);
        if ($token === '') {
            return;
        }

        Db::getInstance()->delete(
            self::TABLE_NAME,
            '`token` = "' . pSQL($token) . '"'
        );
    }

    /**
     * @param array<int, mixed> $topics
     * @return array<int, string>
     */
    public static function sanitizeTopics(array $topics): array
    {
        $sanitized = [];
        foreach ($topics as $topic) {
            $topic = trim((string) $topic);
            if ($topic === '') {
                continue;
            }

            if (!preg_match('/^[A-Za-z0-9-_.~%]{1,900}$/', $topic)) {
                continue;
            }

            $sanitized[] = $topic;
        }

        return array_values(array_unique($sanitized));
    }

    public static function install(): bool
    {
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS `%s%s` (
                `id_rebuildconnector_fcm_device` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `token` VARCHAR(255) NOT NULL UNIQUE,
                `device_id` VARCHAR(191) DEFAULT NULL,
                `platform` VARCHAR(32) DEFAULT NULL,
                `topics` LONGTEXT DEFAULT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL
            ) ENGINE=%s DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            _DB_PREFIX_,
            self::TABLE_NAME,
            _MYSQL_ENGINE_
        );

        return Db::getInstance()->execute($sql);
    }

    public static function uninstall(): void
    {
        $sql = sprintf(
            'DROP TABLE IF EXISTS `%s%s`',
            _DB_PREFIX_,
            self::TABLE_NAME
        );

        Db::getInstance()->execute($sql);
    }

    private function findIdByToken(string $token): ?int
    {
        $sql = sprintf(
            'SELECT `id_rebuildconnector_fcm_device`
             FROM `%s%s`
             WHERE `token` = "%s"
             LIMIT 1',
            _DB_PREFIX_,
            self::TABLE_NAME,
            pSQL($token)
        );

        $value = Db::getInstance()->getValue($sql);

        return $value !== false ? (int) $value : null;
    }

    /**
     * @return array<int, string>
     */
    private function decodeTopics(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return [];
        }

        return self::sanitizeTopics($decoded);
    }
}
