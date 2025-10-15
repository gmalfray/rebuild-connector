<?php

defined('_PS_VERSION_') || exit;

class OrdersService
{
    private const DEFAULT_LIMIT = 20;

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getOrders(array $filters = []): array
    {
        $langId = $this->getLanguageId();
        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : self::DEFAULT_LIMIT;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $query = new DbQuery();
        $query->select('o.id_order, o.reference, o.current_state, o.id_currency, o.id_customer');
        $query->select('o.total_paid_tax_incl AS total_paid_tax_incl, o.total_paid_tax_excl AS total_paid_tax_excl');
        $query->select('o.date_add, o.date_upd, c.firstname, c.lastname, c.email');
        $query->select('osl.name AS status_name, cur.iso_code AS currency_iso');
        $query->from('orders', 'o');
        $query->innerJoin('customer', 'c', 'c.id_customer = o.id_customer');
        $query->leftJoin(
            'order_state_lang',
            'osl',
            'osl.id_order_state = o.current_state AND osl.id_lang = ' . (int) $langId
        );
        $query->leftJoin('currency', 'cur', 'cur.id_currency = o.id_currency');

        if (!empty($filters['customer_id'])) {
            $query->where('o.id_customer = ' . (int) $filters['customer_id']);
        }

        if (!empty($filters['status'])) {
            if (is_numeric($filters['status'])) {
                $query->where('o.current_state = ' . (int) $filters['status']);
            } else {
                $status = pSQL((string) $filters['status']);
                $query->where('osl.name LIKE "%' . $status . '%"');
            }
        }

        if (!empty($filters['date_from'])) {
            $query->where('o.date_add >= "' . pSQL($filters['date_from']) . '"');
        }

        if (!empty($filters['date_to'])) {
            $query->where('o.date_add <= "' . pSQL($filters['date_to']) . '"');
        }

        if (!empty($filters['search'])) {
            $term = pSQL((string) $filters['search'], true);
            $like = '"%' . $term . '%"';
            $query->where(
                '(o.reference LIKE ' . $like
                . ' OR c.firstname LIKE ' . $like
                . ' OR c.lastname LIKE ' . $like
                . ' OR c.email LIKE ' . $like . ')'
            );
        }

        $query->orderBy('o.date_add DESC');
        $query->limit($limit, $offset);

        $rows = (array) Db::getInstance()->executeS($query);

        $orders = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $orders[] = $this->formatOrderRow($row);
        }

        return $orders;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrderById(int $orderId): array
    {
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return [];
        }

        $langId = $this->getLanguageId();
        $currency = $order->id_currency ? new Currency((int) $order->id_currency) : null;
        $customer = $order->id_customer ? new Customer((int) $order->id_customer) : null;

        $statusName = null;
        if ($order->current_state) {
            $state = new OrderState((int) $order->current_state, $langId);
            if (Validate::isLoadedObject($state)) {
                $name = $state->name;
                if (is_array($name)) {
                    $statusName = $name[$langId] ?? reset($name);
                } else {
                    $statusName = $name;
                }
            }
        }

        $items = [];
        foreach ($order->getProducts() as $product) {
            $items[] = [
                'product_id' => isset($product['id_product']) ? (int) $product['id_product'] : 0,
                'name' => isset($product['product_name']) ? (string) $product['product_name'] : '',
                'reference' => isset($product['product_reference']) ? (string) $product['product_reference'] : '',
                'quantity' => isset($product['product_quantity']) ? (int) $product['product_quantity'] : 0,
                'price_tax_incl' => isset($product['total_price_tax_incl']) ? (float) $product['total_price_tax_incl'] : 0.0,
                'price_tax_excl' => isset($product['total_price_tax_excl']) ? (float) $product['total_price_tax_excl'] : 0.0,
            ];
        }

        $orderCarrierId = (int) OrderCarrier::getIdByOrderId((int) $order->id);
        $trackingNumber = (string) $order->shipping_number;
        $carrierName = '';
        $carrierId = (int) $order->id_carrier;

        if ($orderCarrierId > 0) {
            $orderCarrier = new OrderCarrier($orderCarrierId);
            if (Validate::isLoadedObject($orderCarrier)) {
                $trackingNumber = $orderCarrier->tracking_number ?: $trackingNumber;
                $carrierId = (int) $orderCarrier->id_carrier ?: $carrierId;
            }
        }

        if ($carrierId > 0) {
            $carrierName = Carrier::getCarrierNameFromShopName($carrierId) ?: '';
        }

        $history = (array) OrderHistory::getHistory($langId, (int) $order->id);
        $formattedHistory = [];
        foreach ($history as $entry) {
            /** @var array<string, mixed> $entry */
            $formattedHistory[] = [
                'order_state_id' => isset($entry['id_order_state']) ? (int) $entry['id_order_state'] : 0,
                'status' => isset($entry['ostate_name']) ? (string) $entry['ostate_name'] : '',
                'date_add' => isset($entry['date_add']) ? (string) $entry['date_add'] : null,
            ];
        }

        return [
            'id' => (int) $order->id,
            'reference' => (string) $order->reference,
            'status' => [
                'id' => (int) $order->current_state,
                'name' => $statusName,
            ],
            'totals' => [
                'paid_tax_incl' => (float) $order->total_paid_tax_incl,
                'paid_tax_excl' => (float) $order->total_paid_tax_excl,
                'currency' => $currency instanceof Currency ? (string) $currency->iso_code : null,
            ],
            'customer' => [
                'id' => (int) $order->id_customer,
                'firstname' => $customer instanceof Customer ? (string) $customer->firstname : '',
                'lastname' => $customer instanceof Customer ? (string) $customer->lastname : '',
                'email' => $customer instanceof Customer ? (string) $customer->email : '',
            ],
            'shipping' => [
                'carrier_id' => $carrierId,
                'carrier_name' => $carrierName,
                'tracking_number' => $trackingNumber,
            ],
            'dates' => [
                'created_at' => (string) $order->date_add,
                'updated_at' => (string) $order->date_upd,
            ],
            'items' => $items,
            'history' => $formattedHistory,
        ];
    }

    public function updateStatus(int $orderId, string $statusReference): bool
    {
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return false;
        }

        $stateId = $this->resolveOrderStateId($statusReference);
        if ($stateId <= 0) {
            return false;
        }

        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $context = Context::getContext();
        $employee = $context->employee instanceof Employee ? $context->employee : null;
        $history->id_employee = $employee instanceof Employee ? (int) $employee->id : 0;

        $history->changeIdOrderState($stateId, (int) $order->id);

        return (bool) $history->addWithemail(false);
    }

    public function updateShipping(int $orderId, string $trackingNumber): bool
    {
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return false;
        }

        $trackingNumber = trim($trackingNumber);
        $orderCarrierId = (int) OrderCarrier::getIdByOrderId((int) $order->id);

        if ($orderCarrierId > 0) {
            $orderCarrier = new OrderCarrier($orderCarrierId);
            if (Validate::isLoadedObject($orderCarrier)) {
                $orderCarrier->tracking_number = $trackingNumber;
                $orderCarrier->update();
            }
        }

        $order->shipping_number = $trackingNumber;

        return (bool) $order->update();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatOrderRow(array $row): array
    {
        return [
            'id' => isset($row['id_order']) ? (int) $row['id_order'] : 0,
            'reference' => isset($row['reference']) ? (string) $row['reference'] : '',
            'status' => [
                'id' => isset($row['current_state']) ? (int) $row['current_state'] : 0,
                'name' => isset($row['status_name']) ? (string) $row['status_name'] : null,
            ],
            'customer' => [
                'id' => isset($row['id_customer']) ? (int) $row['id_customer'] : 0,
                'firstname' => isset($row['firstname']) ? (string) $row['firstname'] : '',
                'lastname' => isset($row['lastname']) ? (string) $row['lastname'] : '',
                'email' => isset($row['email']) ? (string) $row['email'] : '',
            ],
            'totals' => [
                'paid_tax_incl' => isset($row['total_paid_tax_incl']) ? (float) $row['total_paid_tax_incl'] : 0.0,
                'paid_tax_excl' => isset($row['total_paid_tax_excl']) ? (float) $row['total_paid_tax_excl'] : 0.0,
                'currency' => isset($row['currency_iso']) ? (string) $row['currency_iso'] : null,
            ],
            'dates' => [
                'created_at' => isset($row['date_add']) ? (string) $row['date_add'] : null,
                'updated_at' => isset($row['date_upd']) ? (string) $row['date_upd'] : null,
            ],
        ];
    }

    private function getLanguageId(): int
    {
        $context = Context::getContext();
        if ($context->language instanceof Language) {
            return (int) $context->language->id;
        }

        return (int) Configuration::get('PS_LANG_DEFAULT');
    }

    private function resolveOrderStateId(string $reference): int
    {
        if ($reference === '') {
            return 0;
        }

        if (ctype_digit($reference)) {
            return (int) $reference;
        }

        $langId = $this->getLanguageId();
        $idState = (int) OrderState::getIdByName($reference, $langId);

        if ($idState > 0) {
            return $idState;
        }

        $query = new DbQuery();
        $query->select('id_order_state');
        $query->from('order_state_lang');
        $query->where('LOWER(name) = "' . pSQL(Tools::strtolower($reference)) . '"');
        $query->where('id_lang = ' . (int) $langId);

        $result = Db::getInstance()->getValue($query);

        return $result ? (int) $result : 0;
    }
}
