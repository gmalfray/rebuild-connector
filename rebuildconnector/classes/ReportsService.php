<?php

defined('_PS_VERSION_') || exit;

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/LanguageResolver.php';

class ReportsService
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 50;

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getBestSellers(array $filters = []): array
    {
        $limit = $this->sanitizeLimit($filters['limit'] ?? self::DEFAULT_LIMIT);
        $langId = $this->getLanguageId();
        $shopId = $this->getShopId();

        $query = new DbQuery();
        $query->select('od.product_id, od.product_attribute_id, SUM(od.product_quantity) AS quantity');
        $query->select('SUM(od.total_price_tax_incl) AS total_tax_incl');
        $query->select('SUM(od.total_price_tax_excl) AS total_tax_excl');
        $query->select('pl.name, p.reference');
        $query->from('order_detail', 'od');
        $query->innerJoin('orders', 'o', 'o.id_order = od.id_order');
        $query->leftJoin('product_lang', 'pl', 'pl.id_product = od.product_id AND pl.id_lang = ' . (int) $langId . ' AND pl.id_shop = ' . (int) $shopId);
        $query->leftJoin('product', 'p', 'p.id_product = od.product_id');

        $this->applyDateFilters($query, 'o.date_add', $filters);

        $query->groupBy('od.product_id, od.product_attribute_id');
        $query->orderBy('quantity DESC');
        $query->limit($limit);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = Db::getInstance()->executeS($query) ?: [];

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'product_id' => isset($row['product_id']) ? (int) $row['product_id'] : 0,
                'product_attribute_id' => isset($row['product_attribute_id']) ? (int) $row['product_attribute_id'] : null,
                'name' => isset($row['name']) ? (string) $row['name'] : null,
                'reference' => isset($row['reference']) ? (string) $row['reference'] : null,
                'quantity' => isset($row['quantity']) ? (int) $row['quantity'] : 0,
                'total_tax_incl' => isset($row['total_tax_incl']) ? (float) $row['total_tax_incl'] : 0.0,
                'total_tax_excl' => isset($row['total_tax_excl']) ? (float) $row['total_tax_excl'] : 0.0,
            ];
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getBestCustomers(array $filters = []): array
    {
        $limit = $this->sanitizeLimit($filters['limit'] ?? self::DEFAULT_LIMIT);

        $query = new DbQuery();
        $query->select('o.id_customer, cu.firstname, cu.lastname, cu.email');
        $query->select('COUNT(o.id_order) AS orders_count');
        $query->select('SUM(o.total_paid_tax_incl) AS total_tax_incl');
        $query->select('SUM(o.total_paid_tax_excl) AS total_tax_excl');
        $query->select('MAX(o.date_add) AS last_order_at');
        $query->from('orders', 'o');
        $query->leftJoin('customer', 'cu', 'cu.id_customer = o.id_customer');

        $this->applyDateFilters($query, 'o.date_add', $filters);

        $query->groupBy('o.id_customer');
        $query->orderBy('total_tax_incl DESC');
        $query->limit($limit);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = Db::getInstance()->executeS($query) ?: [];

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'id' => isset($row['id_customer']) ? (int) $row['id_customer'] : 0,
                'firstname' => isset($row['firstname']) ? (string) $row['firstname'] : null,
                'lastname' => isset($row['lastname']) ? (string) $row['lastname'] : null,
                'email' => isset($row['email']) ? (string) $row['email'] : null,
                'orders_count' => isset($row['orders_count']) ? (int) $row['orders_count'] : 0,
                'total_spent' => isset($row['total_tax_incl']) ? (float) $row['total_tax_incl'] : 0.0,
                'last_order_at' => isset($row['last_order_at']) ? (string) $row['last_order_at'] : null,
            ];
        }

        return $results;
    }

    /**
     * Fidélité / réachat des clients payants.
     *
     * Périmètre : clients ayant au moins une commande valide payante (o.valid = 1 ET
     * total_paid_tax_incl > 0) sur la boutique courante ; avec fenêtre date_from/date_to, ceux qui
     * ont une commande payante dans la fenêtre. Les comptes de commandes et les dates de 1re commande
     * sont « à vie » (toutes les commandes payantes valides du client, hors fenêtre).
     *
     * Deux requêtes : agrégat par client, puis dates des commandes des clients à >= 2 commandes
     * (écarts calculés en PHP, médiane en PHP).
     *
     * @param array<string, mixed> $filters date_from, date_to (Y-m-d ou Y-m-d H:i:s)
     * @return array<string, mixed>
     * @throws \InvalidArgumentException si les dates sont invalides ou si date_from > date_to.
     */
    public function getCustomerRetention(array $filters = []): array
    {
        $from = $this->normalizeBound($filters['date_from'] ?? null, false);
        $to = $this->normalizeBound($filters['date_to'] ?? null, true);
        if ($from !== null && $to !== null && $from > $to) {
            throw new \InvalidArgumentException('"date_from" doit être antérieure ou égale à "date_to".');
        }

        $shopId = $this->getShopId();
        $shopSql = $shopId > 0 ? ' AND o.id_shop = ' . $shopId : '';
        $paidWhere = 'o.valid = 1 AND o.total_paid_tax_incl > 0 AND o.id_customer > 0' . $shopSql;

        $inWindow = '1';
        if ($from !== null || $to !== null) {
            $parts = [];
            if ($from !== null) {
                $parts[] = 'o.date_add >= "' . pSQL($from) . '"';
            }
            if ($to !== null) {
                $parts[] = 'o.date_add <= "' . pSQL($to) . '"';
            }
            $inWindow = implode(' AND ', $parts);
        }
        $hasWindow = $from !== null || $to !== null;
        $windowHaving = $hasWindow ? ' HAVING SUM(' . $inWindow . ') > 0' : '';

        $customersSql = 'SELECT o.id_customer, COUNT(*) AS paid_orders, MIN(o.date_add) AS first_paid'
            . ' FROM ' . _DB_PREFIX_ . 'orders o WHERE ' . $paidWhere
            . ' GROUP BY o.id_customer' . $windowHaving;

        /** @var array<int, array<string, mixed>> $customers */
        $customers = Db::getInstance()->executeS($customersSql) ?: [];

        $paying = 0;
        $repeat = 0;
        $new = 0;
        $recurring = 0;
        foreach ($customers as $row) {
            ++$paying;
            $orders = (int) ($row['paid_orders'] ?? 0);
            if ($orders >= 2) {
                ++$repeat;
            }
            if ($hasWindow) {
                $firstPaid = (string) ($row['first_paid'] ?? '');
                $isNew = ($from === null || $firstPaid >= $from) && ($to === null || $firstPaid <= $to);
                if ($isNew) {
                    ++$new;
                } else {
                    ++$recurring;
                }
            } elseif ($orders >= 2) {
                ++$recurring;
            } else {
                ++$new;
            }
        }

        // Dates des commandes payantes des clients du périmètre ayant >= 2 commandes payantes.
        $datesSql = 'SELECT o.id_customer, o.date_add FROM ' . _DB_PREFIX_ . 'orders o'
            . ' INNER JOIN (SELECT o.id_customer FROM ' . _DB_PREFIX_ . 'orders o WHERE ' . $paidWhere
            . ' GROUP BY o.id_customer HAVING COUNT(*) >= 2' . ($hasWindow ? ' AND SUM(' . $inWindow . ') > 0' : '') . ') r'
            . ' ON r.id_customer = o.id_customer'
            . ' WHERE ' . $paidWhere
            . ' ORDER BY o.id_customer ASC, o.date_add ASC';

        /** @var array<int, array<string, mixed>> $dateRows */
        $dateRows = $repeat > 0 ? (Db::getInstance()->executeS($datesSql) ?: []) : [];

        $gaps = self::computeGapsInDays($dateRows);
        $median = self::median($gaps);

        return [
            'paying_customers' => $paying,
            'repeat_customers' => $repeat,
            'repeat_rate' => $paying > 0 ? round($repeat / $paying * 100, 1) : 0.0,
            'median_days_between_orders' => $median !== null ? round($median, 1) : null,
            'new_customers' => $new,
            'recurring_customers' => $recurring,
            'period' => [
                'date_from' => $from,
                'date_to' => $to,
            ],
        ];
    }

    /**
     * Écarts (en jours, fractionnaires) entre commandes consécutives d'un même client. Les lignes
     * doivent être triées par id_customer puis date_add croissants.
     *
     * @param array<int, array<string, mixed>> $rows lignes {id_customer, date_add}
     * @return array<int, float>
     */
    public static function computeGapsInDays(array $rows): array
    {
        $utc = new \DateTimeZone('UTC');
        $gaps = [];
        $prevCustomer = null;
        $prevTs = null;
        foreach ($rows as $row) {
            $customer = (int) ($row['id_customer'] ?? 0);
            $ts = (new \DateTimeImmutable((string) ($row['date_add'] ?? 'now'), $utc))->getTimestamp();
            if ($prevCustomer === $customer && $prevTs !== null) {
                $gaps[] = ($ts - $prevTs) / 86400.0;
            }
            $prevCustomer = $customer;
            $prevTs = $ts;
        }

        return $gaps;
    }

    /**
     * Médiane d'une liste de valeurs (moyenne des deux valeurs centrales si effectif pair).
     *
     * @param array<int, float|int> $values
     */
    public static function median(array $values): ?float
    {
        $count = count($values);
        if ($count === 0) {
            return null;
        }
        $values = array_values($values);
        sort($values);
        $mid = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $values[$mid]
            : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
    }

    /**
     * Valide une borne de date et la normalise en Y-m-d H:i:s (une borne haute sans heure couvre
     * toute la journée : 23:59:59).
     *
     * @param mixed $value
     * @throws \InvalidArgumentException si le format est invalide.
     */
    private function normalizeBound($value, bool $isUpper): ?string
    {
        if ($value === null || $value === false || trim((string) $value) === '') {
            return null;
        }
        $date = trim((string) $value);
        $this->validateDate($date);
        if (strlen($date) === 10) {
            return $date . ($isUpper ? ' 23:59:59' : ' 00:00:00');
        }

        return $date;
    }

    /**
     * @param array<string, mixed> $filters
     * @throws \InvalidArgumentException si les dates sont dans un format invalide.
     */
    private function applyDateFilters(DbQuery $query, string $column, array $filters): void
    {
        if (!empty($filters['date_from'])) {
            $this->validateDate((string) $filters['date_from']);
            $query->where($column . ' >= "' . pSQL((string) $filters['date_from']) . '"');
        }

        if (!empty($filters['date_to'])) {
            $this->validateDate((string) $filters['date_to']);
            $query->where($column . ' <= "' . pSQL((string) $filters['date_to']) . '"');
        }
    }

    /**
     * Valide qu'une chaîne de date est au format Y-m-d ou Y-m-d H:i:s.
     *
     * @throws \InvalidArgumentException si le format est invalide.
     */
    private function validateDate(string $date): void
    {
        $date = trim($date);

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date);
        if ($dt !== false && $dt->format('Y-m-d H:i:s') === $date) {
            return;
        }

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($dt !== false && $dt->format('Y-m-d') === $date) {
            return;
        }

        throw new \InvalidArgumentException(
            sprintf('Format de date invalide : "%s". Formats acceptés : Y-m-d ou Y-m-d H:i:s.', $date)
        );
    }

    /**
     * Résout l'id_lang selon l'en-tête `Accept-Language` envoyé par l'app (fallback langue par
     * défaut boutique si absent/non installée/inactive), voir LanguageResolver.
     */
    private function getLanguageId(): int
    {
        return LanguageResolver::resolveIdLang();
    }

    private function getShopId(): int
    {
        $context = Context::getContext();
        if ($context->shop instanceof Shop) {
            return (int) $context->shop->id;
        }

        return (int) Configuration::get('PS_SHOP_DEFAULT');
    }

    private function sanitizeLimit(?int $value): int
    {
        if ($value === null || $value <= 0) {
            return self::DEFAULT_LIMIT;
        }

        if ($value > self::MAX_LIMIT) {
            return self::MAX_LIMIT;
        }

        return $value;
    }
}
