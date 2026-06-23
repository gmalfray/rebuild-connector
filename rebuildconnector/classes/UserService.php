<?php

defined('_PS_VERSION_') || exit;

class UserService
{
    private const TABLE = 'rebuildconnector_user';

    private const AVAILABLE_SCOPES = [
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

    public static function install(): bool
    {
        $prefix = _DB_PREFIX_;
        $sql = 'CREATE TABLE IF NOT EXISTS `' . $prefix . self::TABLE . '` (
            `id_user` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_employee` INT UNSIGNED NOT NULL,
            `label` VARCHAR(100) NOT NULL DEFAULT \'\',
            `api_key_hash` VARCHAR(255) NOT NULL,
            `scopes` TEXT NOT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `revoked_at` DATETIME NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_user`),
            KEY `idx_active` (`active`),
            KEY `idx_employee` (`id_employee`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

        return (bool) Db::getInstance()->execute($sql);
    }

    public static function uninstall(): bool
    {
        $prefix = _DB_PREFIX_;
        return (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . $prefix . self::TABLE . '`');
    }

    /**
     * Crée un utilisateur nommé avec les scopes fournis.
     * Retourne la clé API en clair une seule fois.
     *
     * @param array<int, string> $scopes
     * @return array{id_user: int, api_key: string}
     */
    public function createUser(int $idEmployee, string $label, array $scopes): array
    {
        $apiKey = bin2hex(random_bytes(20));
        $hash = password_hash($apiKey, PASSWORD_BCRYPT, ['cost' => 12]);

        $scopesJson = json_encode(array_values(array_unique(array_filter($scopes, 'is_string'))));
        if ($scopesJson === false) {
            $scopesJson = '[]';
        }

        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '`
            (`id_employee`, `label`, `api_key_hash`, `scopes`, `active`, `revoked_at`, `date_add`)
            VALUES ('
            . (int) $idEmployee . ', '
            . '"' . pSQL($label) . '", '
            . '"' . pSQL($hash) . '", '
            . '"' . pSQL($scopesJson) . '", '
            . '1, '
            . 'NULL, '
            . '"' . date('Y-m-d H:i:s') . '"'
            . ')'
        );

        $idUser = (int) Db::getInstance()->Insert_ID();

        return [
            'id_user' => $idUser,
            'api_key' => $apiKey,
        ];
    }

    /**
     * Recherche un utilisateur actif par clé API (vérifie via password_verify).
     * Itère uniquement sur les users actifs et non révoqués.
     *
     * @return array<string, mixed>|null
     */
    public function findByApiKey(string $apiKey): ?array
    {
        $query = new DbQuery();
        $query->select('*');
        $query->from(self::TABLE);
        $query->where('active = 1');
        $query->where('(revoked_at IS NULL OR revoked_at = "0000-00-00 00:00:00")');
        $query->orderBy('id_user ASC');

        /** @var array<int, array<string, mixed>> $rows */
        $rows = Db::getInstance()->executeS($query) ?: [];

        foreach ($rows as $row) {
            if (isset($row['api_key_hash']) && is_string($row['api_key_hash'])) {
                if (password_verify($apiKey, $row['api_key_hash'])) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUserById(int $idUser): ?array
    {
        $query = new DbQuery();
        $query->select('*');
        $query->from(self::TABLE);
        $query->where('id_user = ' . (int) $idUser);

        /** @var array<string, mixed>|false $row */
        $row = Db::getInstance()->getRow($query);

        return is_array($row) ? $row : null;
    }

    public function setActive(int $idUser, bool $active): void
    {
        if ($active) {
            Db::getInstance()->execute(
                'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
                SET `active` = 1, `revoked_at` = NULL
                WHERE `id_user` = ' . (int) $idUser
            );
        } else {
            Db::getInstance()->execute(
                'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
                SET `active` = 0, `revoked_at` = "' . date('Y-m-d H:i:s') . '"
                WHERE `id_user` = ' . (int) $idUser
            );
        }
    }

    /**
     * Liste tous les utilisateurs avec jointure sur ps_employee pour nom/prénom/email.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listUsers(): array
    {
        $query = new DbQuery();
        $query->select('u.id_user, u.id_employee, u.label, u.scopes, u.active, u.revoked_at, u.date_add');
        $query->select('e.firstname AS employee_firstname, e.lastname AS employee_lastname, e.email AS employee_email');
        $query->from(self::TABLE, 'u');
        $query->leftJoin('employee', 'e', 'e.id_employee = u.id_employee');
        $query->orderBy('u.date_add DESC');

        /** @var array<int, array<string, mixed>> $rows */
        $rows = Db::getInstance()->executeS($query) ?: [];

        return $rows;
    }

    /**
     * Met à jour les scopes d'un utilisateur.
     *
     * @param array<int, string> $scopes
     */
    public function updateScopes(int $idUser, array $scopes): void
    {
        $scopesJson = json_encode(array_values(array_unique(array_filter($scopes, 'is_string'))));
        if ($scopesJson === false) {
            $scopesJson = '[]';
        }

        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
            SET `scopes` = "' . pSQL($scopesJson) . '"
            WHERE `id_user` = ' . (int) $idUser
        );
    }

    /**
     * Régénère la clé API d'un utilisateur existant.
     * Met à jour api_key_hash en base et retourne la nouvelle clé en clair.
     */
    public function regenerateApiKey(int $idUser): string
    {
        $apiKey = bin2hex(random_bytes(20));
        $hash = password_hash($apiKey, PASSWORD_BCRYPT, ['cost' => 12]);

        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '`
            SET `api_key_hash` = "' . pSQL($hash) . '"
            WHERE `id_user` = ' . (int) $idUser
        );

        return $apiKey;
    }

    /**
     * @return array<int, string>
     */
    public function getAllScopes(): array
    {
        return self::AVAILABLE_SCOPES;
    }

    /**
     * Retourne les presets de rôles prédéfinis.
     *
     * @return array<string, array{label: string, scopes: array<int, string>}>
     */
    public static function getRolePresets(): array
    {
        return [
            'admin' => [
                'label' => 'Admin (accès complet)',
                'scopes' => self::AVAILABLE_SCOPES,
            ],
            'preparateur' => [
                'label' => 'Préparateur',
                'scopes' => ['orders.read', 'orders.write', 'dashboard.read'],
            ],
            'lecture' => [
                'label' => 'Lecture seule',
                'scopes' => ['orders.read', 'products.read', 'customers.read', 'dashboard.read', 'baskets.read', 'reports.read'],
            ],
        ];
    }
}
