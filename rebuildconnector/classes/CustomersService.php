<?php

defined('_PS_VERSION_') || exit;

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/OrdersService.php';

class CustomersService
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;
    private const SEGMENT_VIP_MIN_SPENT = 500.0;
    private const SEGMENT_INACTIVE_DAYS = 90;

    private OrdersService $ordersService;

    public function __construct(?OrdersService $ordersService = null)
    {
        $this->ordersService = $ordersService ?: new OrdersService();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     pagination: array<string, mixed>
     * }
     */
    public function getCustomers(array $filters = []): array
    {
        $limit = $this->sanitizeLimit($filters['limit'] ?? self::DEFAULT_LIMIT);
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $query = new DbQuery();
        $query->select('c.id_customer, c.firstname, c.lastname, c.email, c.date_add');
        $query->select($this->ordersCountExpression() . ' AS orders_count');
        $query->select($this->totalSpentExpression() . ' AS total_spent');
        $query->select($this->lastOrderDateExpression() . ' AS last_order_date');
        $query->from('customer', 'c');
        $query->where('c.deleted = 0');

        if (!empty($filters['ids'])) {
            $ids = is_array($filters['ids']) ? $filters['ids'] : explode(',', (string) $filters['ids']);
            $ids = array_filter(array_map('intval', $ids));
            if (!empty($ids)) {
                $query->where('c.id_customer IN (' . implode(',', $ids) . ')');
            }
        }

        if (!empty($filters['email'])) {
            $query->where('c.email = "' . pSQL((string) $filters['email'], true) . '"');
        }

        if (!empty($filters['created_from'])) {
            $query->where('c.date_add >= "' . pSQL((string) $filters['created_from'], true) . '"');
        }

        if (!empty($filters['created_to'])) {
            $query->where('c.date_add <= "' . pSQL((string) $filters['created_to'], true) . '"');
        }

        if (!empty($filters['search'])) {
            $term = pSQL((string) $filters['search'], true);
            $like = '"%' . $term . '%"';
            $query->where('(c.firstname LIKE ' . $like . ' OR c.lastname LIKE ' . $like . ' OR c.email LIKE ' . $like . ')');
        }

        if (isset($filters['orders_min'])) {
            $query->where($this->ordersCountExpression() . ' >= ' . (int) $filters['orders_min']);
        }

        if (isset($filters['orders_max'])) {
            $query->where($this->ordersCountExpression() . ' <= ' . (int) $filters['orders_max']);
        }

        if (isset($filters['spent_min'])) {
            $query->where($this->totalSpentExpression() . ' >= ' . (float) $filters['spent_min']);
        }

        if (isset($filters['spent_max'])) {
            $query->where($this->totalSpentExpression() . ' <= ' . (float) $filters['spent_max']);
        }

        if (isset($filters['segment']) && $filters['segment'] !== '') {
            $this->applySegmentFilter($query, (string) $filters['segment']);
        }

        $orderBy = $this->resolveOrderBy(isset($filters['sort']) ? (string) $filters['sort'] : '');
        $query->orderBy($orderBy);
        $query->limit($limit + 1, $offset);

        $rows = $this->executeCustomerQuery($query);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $customers = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $customers[] = $this->formatCustomerRow($row);
        }

        return [
            'items' => $customers,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($customers),
                'has_next' => $hasMore,
                'next_offset' => $hasMore ? $offset + $limit : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustomerById(int $customerId): array
    {
        $result = $this->getCustomers([
            'ids' => [$customerId],
            'limit' => 1,
            'offset' => 0,
        ]);

        if ($result['items'] === []) {
            return [];
        }

        $customer = $result['items'][0];
        $customer['orders'] = $this->ordersService->getOrders([
            'customer_id' => $customerId,
            'limit' => 10,
            'offset' => 0,
        ]);

        return $customer;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatCustomerRow(array $row): array
    {
        return [
            'id' => isset($row['id_customer']) ? (int) $row['id_customer'] : 0,
            'firstname' => isset($row['firstname']) ? (string) $row['firstname'] : '',
            'lastname' => isset($row['lastname']) ? (string) $row['lastname'] : '',
            'email' => isset($row['email']) ? (string) $row['email'] : '',
            'orders_count' => isset($row['orders_count']) ? (int) $row['orders_count'] : 0,
            'total_spent' => isset($row['total_spent']) ? (float) $row['total_spent'] : 0.0,
            'last_order_at' => isset($row['last_order_date']) ? (string) $row['last_order_date'] : null,
        ];
    }

    /**
     * @param mixed $limit
     */
    private function sanitizeLimit($limit): int
    {
        $limit = (int) $limit;
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        if ($limit > self::MAX_LIMIT) {
            $limit = self::MAX_LIMIT;
        }

        return $limit;
    }

    private function ordersCountExpression(): string
    {
        return '(
            SELECT COUNT(*)
            FROM ' . _DB_PREFIX_ . 'orders oc
            WHERE oc.id_customer = c.id_customer
        )';
    }

    private function totalSpentExpression(): string
    {
        return '(
            SELECT IFNULL(SUM(o2.total_paid_tax_incl), 0)
            FROM ' . _DB_PREFIX_ . 'orders o2
            WHERE o2.id_customer = c.id_customer
        )';
    }

    private function lastOrderDateExpression(): string
    {
        return '(
            SELECT MAX(o3.date_add)
            FROM ' . _DB_PREFIX_ . 'orders o3
            WHERE o3.id_customer = c.id_customer
        )';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function executeCustomerQuery(DbQuery $query): array
    {
        return (array) Db::getInstance()->executeS($query);
    }

    private function applySegmentFilter(DbQuery $query, string $segment): void
    {
        switch ($segment) {
            case 'new':
                $query->where($this->ordersCountExpression() . ' = 0');
                break;
            case 'repeat':
                $query->where($this->ordersCountExpression() . ' >= 1');
                break;
            case 'vip':
                $query->where($this->totalSpentExpression() . ' >= ' . (float) self::SEGMENT_VIP_MIN_SPENT);
                break;
            case 'inactive':
                $query->where($this->ordersCountExpression() . ' > 0');
                $query->where(
                    '(' . $this->lastOrderDateExpression() . ' IS NULL OR '
                    . $this->lastOrderDateExpression() . ' < DATE_SUB(NOW(), INTERVAL '
                    . (int) self::SEGMENT_INACTIVE_DAYS . ' DAY))'
                );
                break;
        }
    }

    private function resolveOrderBy(string $sort): string
    {
        switch ($sort) {
            case 'date_asc':
                return 'c.date_add ASC';
            case 'orders_desc':
                return 'orders_count DESC, c.id_customer DESC';
            case 'orders_asc':
                return 'orders_count ASC, c.id_customer ASC';
            case 'spent_desc':
                return 'total_spent DESC, c.id_customer DESC';
            case 'spent_asc':
                return 'total_spent ASC, c.id_customer ASC';
            default:
                return 'c.date_add DESC';
        }
    }
}
