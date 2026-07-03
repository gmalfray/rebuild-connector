<?php

defined('_PS_VERSION_') || exit;

class RateLimiterService
{
    public const TABLE_NAME = 'rebuildconnector_rate_limit';

    /**
     * @var array<string, bool>
     */
    private array $cache = [];

    public static function install(): bool
    {
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS `%s%s` (
                `id_rebuildconnector_rate_limit` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `identifier` VARCHAR(191) NOT NULL,
                `period_start` DATETIME NOT NULL,
                `count` INT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY `uniq_identifier_period` (`identifier`, `period_start`)
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

    public function isAllowed(string $identifier, int $limit): bool
    {
        $identifier = trim($identifier);
        if ($identifier === '' || $limit <= 0) {
            return true;
        }

        $key = $identifier . '|' . (string) $limit;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $now = new \DateTimeImmutable('now');
        $periodStart = $now->setTime(
            (int) $now->format('H'),
            (int) $now->format('i'),
            0
        )->format('Y-m-d H:i:s');

        $identifierSql = pSQL($identifier);
        $periodSql = pSQL($periodStart);
        $table = sprintf('`%s%s`', _DB_PREFIX_, self::TABLE_NAME);

        $insertSql = sprintf(
            'INSERT INTO %s (`identifier`, `period_start`, `count`)
             VALUES ("%s", "%s", 1)
             ON DUPLICATE KEY UPDATE `count` = `count` + 1',
            $table,
            $identifierSql,
            $periodSql
        );

        Db::getInstance()->execute($insertSql);

        // ⚠️ PAS de "LIMIT 1" ici : Db::getValue() (via getRow) ajoute TOUJOURS " LIMIT 1" en PS
        // 1.7.8. Un "LIMIT 1" déjà présent donne "LIMIT 1 LIMIT 1" → erreur SQL silencieuse →
        // getValue renvoie 0 → le rate-limiter ne bloquait jamais. $use_cache=false par sécurité.
        $countSql = sprintf(
            'SELECT `count` FROM %s WHERE `identifier` = "%s" AND `period_start` = "%s"',
            $table,
            $identifierSql,
            $periodSql
        );

        $count = (int) Db::getInstance()->getValue($countSql, false);
        $allowed = $count <= $limit;

        $this->cache[$key] = $allowed;

        // Purge opportuniste : la table grossit à chaque fenêtre de rate-limit et rien ne l'élague
        // (pas de cron sur ce module). On ne l'exécute qu'occasionnellement (~1 requête sur 200)
        // pour ne pas payer un DELETE à chaque appel.
        if (mt_rand(1, 200) === 1) {
            $this->prune();
        }

        return $allowed;
    }

    public function prune(int $minutes = 180): void
    {
        $minutes = max(1, $minutes);
        $threshold = (new \DateTimeImmutable('now'))
            ->modify('-' . $minutes . ' minutes')
            ->format('Y-m-d H:i:s');

        $sql = sprintf(
            'DELETE FROM `%s%s` WHERE `period_start` < "%s"',
            _DB_PREFIX_,
            self::TABLE_NAME,
            pSQL($threshold)
        );

        Db::getInstance()->execute($sql);
    }
}
