<?php

defined('_PS_VERSION_') || exit;

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
     * @param array<string, mixed> $filters
     */
    private function applyDateFilters(DbQuery $query, string $column, array $filters): void
    {
        if (!empty($filters['date_from'])) {
            $query->where($column . ' >= "' . pSQL((string) $filters['date_from']) . '"');
        }

        if (!empty($filters['date_to'])) {
            $query->where($column . ' <= "' . pSQL((string) $filters['date_to']) . '"');
        }
    }

    private function getLanguageId(): int
    {
        $context = Context::getContext();
        if ($context->language instanceof Language) {
            return (int) $context->language->id;
        }

        return (int) Configuration::get('PS_LANG_DEFAULT');
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
