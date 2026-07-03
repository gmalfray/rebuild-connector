<?php

defined('_PS_VERSION_') || exit;

class AuditLogService
{
    public const TABLE_NAME = 'rebuildconnector_audit_log';

    /**
     * Durée de rétention par défaut des lignes d'audit (en jours).
     * Aucune purge n'était faite jusque-là (pas de cron sur ce module) → croissance non bornée.
     */
    public const RETENTION_DAYS = 90;

    public static function install(): bool
    {
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS `%s%s` (
                `id_rebuildconnector_audit_log` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `event` VARCHAR(64) NOT NULL,
                `context` LONGTEXT DEFAULT NULL,
                `token_subject` VARCHAR(120) DEFAULT NULL,
                `scopes` VARCHAR(255) DEFAULT NULL,
                `ip_address` VARCHAR(64) DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                KEY `idx_event_created` (`event`, `created_at`)
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

    /**
     * @param array<string, mixed> $context
     */
    public function record(string $event, array $context = []): void
    {
        $event = trim($event);
        if ($event === '') {
            return;
        }

        $tokenSubject = null;
        if (isset($context['token_subject']) && is_string($context['token_subject'])) {
            $tokenSubject = Tools::substr($context['token_subject'], 0, 120);
        }

        $ipAddress = null;
        if (isset($context['ip']) && is_string($context['ip'])) {
            $ipAddress = Tools::substr($context['ip'], 0, 64);
        }

        $scopesString = null;
        if (isset($context['scopes']) && is_array($context['scopes'])) {
            $scopes = [];
            foreach ($context['scopes'] as $scope) {
                if (is_string($scope) && $scope !== '') {
                    $scopes[] = $scope;
                }
            }
            if ($scopes !== []) {
                $scopesString = Tools::substr(implode(',', $scopes), 0, 255);
            }
        }

        unset($context['token_subject'], $context['ip'], $context['scopes']);

        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($contextJson === false) {
            $contextJson = '{}';
        }

        $data = [
            'event' => pSQL(Tools::substr($event, 0, 64)),
            'context' => pSQL($contextJson),
            'token_subject' => $tokenSubject !== null ? pSQL($tokenSubject) : null,
            'scopes' => $scopesString !== null ? pSQL($scopesString) : null,
            'ip_address' => $ipAddress !== null ? pSQL($ipAddress) : null,
            'created_at' => pSQL(date('Y-m-d H:i:s')),
        ];

        Db::getInstance()->insert(self::TABLE_NAME, $data);

        // Purge opportuniste : sans cron, la table grossit sans fin. On n'exécute le DELETE
        // qu'occasionnellement (~1 insert sur 200) pour ne pas le payer à chaque requête.
        if (mt_rand(1, 200) === 1) {
            $this->prune();
        }
    }

    /**
     * Supprime les lignes d'audit plus vieilles que $days jours (rétention par défaut 90 j).
     */
    public function prune(int $days = self::RETENTION_DAYS): void
    {
        $days = max(1, $days);
        $threshold = (new \DateTimeImmutable('now'))
            ->modify('-' . $days . ' days')
            ->format('Y-m-d H:i:s');

        $sql = sprintf(
            'DELETE FROM `%s%s` WHERE `created_at` < "%s"',
            _DB_PREFIX_,
            self::TABLE_NAME,
            pSQL($threshold)
        );

        Db::getInstance()->execute($sql);
    }
}
