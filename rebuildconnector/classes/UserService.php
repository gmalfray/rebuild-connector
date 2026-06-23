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

        Db::getInstance()->insert(self::TABLE, [
            'id_employee' => (int) $idEmployee,
            'label'       => pSQL($label),
            'api_key_hash' => pSQL($hash),
            'scopes'      => pSQL($scopesJson),
            'active'      => 1,
            'revoked_at'  => null,
            'date_add'    => date('Y-m-d H:i:s'),
        ]);

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
        $query->where('revoked_at IS NULL');
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
        $data = ['active' => (int) $active];

        if (!$active) {
            $data['revoked_at'] = date('Y-m-d H:i:s');
        }

        Db::getInstance()->update(
            self::TABLE,
            $data,
            'id_user = ' . (int) $idUser
        );
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
     * @return array<int, string>
     */
    public function getAllScopes(): array
    {
        return self::AVAILABLE_SCOPES;
    }
}
