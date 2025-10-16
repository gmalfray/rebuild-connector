<?php

defined('_PS_VERSION_') || exit;

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/OrdersService.php';

class CustomersService
{
    private const DEFAULT_LIMIT = 20;

    private OrdersService $ordersService;

    public function __construct(?OrdersService $ordersService = null)
    {
        $this->ordersService = $ordersService ?: new OrdersService();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getCustomers(array $filters = []): array
    {
        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : self::DEFAULT_LIMIT;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $query = new DbQuery();
        $query->select('c.id_customer, c.firstname, c.lastname, c.email, c.date_add');
        $query->select('(
            SELECT COUNT(*)
            FROM ' . _DB_PREFIX_ . 'orders o
            WHERE o.id_customer = c.id_customer
        ) AS orders_count');
        $query->select('(
            SELECT IFNULL(SUM(o2.total_paid_tax_incl), 0)
            FROM ' . _DB_PREFIX_ . 'orders o2
            WHERE o2.id_customer = c.id_customer
        ) AS total_spent');
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

        if (!empty($filters['search'])) {
            $term = pSQL((string) $filters['search'], true);
            $like = '"%' . $term . '%"';
            $query->where('(c.firstname LIKE ' . $like . ' OR c.lastname LIKE ' . $like . ' OR c.email LIKE ' . $like . ')');
        }

        $query->orderBy('c.date_add DESC');
        $query->limit($limit, $offset);

        $rows = (array) Db::getInstance()->executeS($query);
        $customers = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $customers[] = $this->formatCustomerRow($row);
        }

        return $customers;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustomerById(int $customerId): array
    {
        $customers = $this->getCustomers([
            'ids' => [$customerId],
            'limit' => 1,
            'offset' => 0,
        ]);

        if ($customers === []) {
            return [];
        }

        $customer = $customers[0];
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
            'date_add' => isset($row['date_add']) ? (string) $row['date_add'] : null,
            'orders_count' => isset($row['orders_count']) ? (int) $row['orders_count'] : 0,
            'total_spent' => isset($row['total_spent']) ? (float) $row['total_spent'] : 0.0,
        ];
    }
}
