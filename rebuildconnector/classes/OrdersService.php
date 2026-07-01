<?php

defined('_PS_VERSION_') || exit;

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/ShippingLabelService.php';

class OrdersService
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;

    /**
     * Whitelist des valeurs acceptées pour le paramètre `sort`.
     * Mappe chaque valeur vers la clause ORDER BY sûre à injecter dans DbQuery.
     *
     * @var array<string, string>
     */
    private const SORT_WHITELIST = [
        'date_desc'  => 'o.date_add DESC',
        'date_asc'   => 'o.date_add ASC',
        'total_desc' => 'o.total_paid_tax_incl DESC',
        'total_asc'  => 'o.total_paid_tax_incl ASC',
        'status'     => 'o.current_state ASC',
        'reference'  => 'o.reference ASC',
    ];

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getOrders(array $filters = []): array
    {
        $langId = $this->getLanguageId();
        // Plafond défensif : évite qu'un limit énorme (ex. 999999) ne charge toute la base (OOM/timeout).
        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : self::DEFAULT_LIMIT;
        $limit = min($limit, self::MAX_LIMIT);
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $query = new DbQuery();
        $query->select('o.id_order, o.reference, o.current_state, o.id_currency, o.id_customer, o.invoice_number');
        $query->select('o.total_paid_tax_incl AS total_paid_tax_incl, o.total_paid_tax_excl AS total_paid_tax_excl');
        $query->select('o.date_add, o.date_upd, c.firstname, c.lastname, c.email');
        $query->select('osl.name AS status_name, cur.iso_code AS currency_iso, os.color AS status_color');
        $query->from('orders', 'o');
        $query->innerJoin('customer', 'c', 'c.id_customer = o.id_customer');
        $query->leftJoin(
            'order_state_lang',
            'osl',
            'osl.id_order_state = o.current_state AND osl.id_lang = ' . (int) $langId
        );
        $query->leftJoin('currency', 'cur', 'cur.id_currency = o.id_currency');
        $query->leftJoin('order_state', 'os', 'os.id_order_state = o.current_state');

        if (!empty($filters['customer_id'])) {
            $query->where('o.id_customer = ' . (int) $filters['customer_id']);
        }

        // Filtre multi-statuts `statuses` (liste CSV ou tableau d'IDs) — prime sur `status` unique.
        $statusesFilter = $this->parseStatusesFilter($filters['statuses'] ?? null);
        if ($statusesFilter !== []) {
            $inList = implode(',', $statusesFilter);
            $query->where('o.current_state IN (' . $inList . ')');
        } elseif (!empty($filters['status'])) {
            // Rétrocompat : filtre `status` unique (id numérique ou nom LIKE).
            if (is_numeric($filters['status'])) {
                $query->where('o.current_state = ' . (int) $filters['status']);
            } else {
                $status = pSQL((string) $filters['status']);
                $query->where('osl.name LIKE "%' . $status . '%"');
            }
        }

        if (!empty($filters['date_from'])) {
            $this->validateDate((string) $filters['date_from']);
            $query->where('o.date_add >= "' . pSQL($filters['date_from']) . '"');
        }

        if (!empty($filters['date_to'])) {
            $this->validateDate((string) $filters['date_to']);
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

        // Tri : résolution via whitelist — aucune interpolation directe du paramètre dans le SQL.
        $sortParam = isset($filters['sort']) ? (string) $filters['sort'] : '';
        $orderClause = self::SORT_WHITELIST[$sortParam] ?? self::SORT_WHITELIST['date_desc'];
        $query->orderBy($orderClause);
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
     * Retourne la liste de tous les statuts de commande disponibles dans la boutique.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOrderStatuses(): array
    {
        $langId = $this->getLanguageId();

        $query = new DbQuery();
        $query->select('os.id_order_state, osl.name, os.color');
        $query->from('order_state', 'os');
        $query->innerJoin(
            'order_state_lang',
            'osl',
            'osl.id_order_state = os.id_order_state AND osl.id_lang = ' . (int) $langId
        );
        $query->where('os.deleted = 0');
        $query->orderBy('os.id_order_state ASC');

        $rows = (array) Db::getInstance()->executeS($query);

        $statuses = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $statuses[] = [
                'id' => isset($row['id_order_state']) ? (int) $row['id_order_state'] : 0,
                'name' => isset($row['name']) ? (string) $row['name'] : '',
                'color' => isset($row['color']) ? (string) $row['color'] : '',
            ];
        }

        return $statuses;
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

        // Protection IDOR : la commande doit appartenir à la boutique courante
        $currentShopId = (int) Context::getContext()->shop->id;
        if ($currentShopId > 0 && (int) $order->id_shop !== $currentShopId) {
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

        if (!class_exists('ProductsService')) {
            require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/ProductsService.php';
        }
        $productsService = new ProductsService();
        $items = [];
        foreach ($order->getProducts() as $product) {
            $productId = isset($product['id_product']) ? (int) $product['id_product'] : 0;
            $items[] = [
                'product_id' => $productId,
                'name' => isset($product['product_name']) ? (string) $product['product_name'] : '',
                'reference' => isset($product['product_reference']) ? (string) $product['product_reference'] : '',
                'quantity' => isset($product['product_quantity']) ? (int) $product['product_quantity'] : 0,
                'price_tax_incl' => isset($product['total_price_tax_incl']) ? (float) $product['total_price_tax_incl'] : 0.0,
                'price_tax_excl' => isset($product['total_price_tax_excl']) ? (float) $product['total_price_tax_excl'] : 0.0,
                'image_url' => $productsService->getCoverImageUrl($productId),
            ];
        }

        $orderCarrierId = (int) $order->getIdOrderCarrier();
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
            $carrier = new Carrier($carrierId, $langId);
            if (Validate::isLoadedObject($carrier)) {
                $carrierName = (string) $carrier->name;
            }
        }

        $history = (array) $order->getHistory($langId);
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
            'has_invoice' => (int) $order->invoice_number > 0,
            'shipping_label' => (new ShippingLabelService())->getShippingLabelMeta($orderId),
        ];
    }

    /**
     * Génère le PDF de la/les facture(s) d'une commande via la classe native PrestaShop.
     * Retourne le contenu binaire, ou null si la commande n'a pas de facture.
     */
    public function getInvoicePdf(int $orderId): ?string
    {
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return null;
        }

        // Protection IDOR : la commande doit appartenir à la boutique courante
        $currentShopId = (int) Context::getContext()->shop->id;
        if ($currentShopId > 0 && (int) $order->id_shop !== $currentShopId) {
            return null;
        }

        $invoices = $order->getInvoicesCollection();
        if (!count($invoices)) {
            return null;
        }

        $context = Context::getContext();
        $pdf = new PDF($invoices, PDF::TEMPLATE_INVOICE, $context->smarty);
        $content = $pdf->render(false);

        return is_string($content) && $content !== '' ? $content : null;
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

    /**
     * Indique si la référence de statut (id numérique ou nom) correspond à un état existant.
     * Permet au contrôleur de répondre 400 (statut invalide) plutôt que de laisser
     * OrderHistory::changeIdOrderState planter en 500 sur un id inexistant.
     */
    public function statusExists(string $statusReference): bool
    {
        return $this->resolveOrderStateId(trim($statusReference)) > 0;
    }

    public function updateShipping(int $orderId, string $trackingNumber, ?int $carrierId = null): bool
    {
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return false;
        }

        $trackingNumber = trim($trackingNumber);
        $orderCarrierId = (int) $order->getIdOrderCarrier();

        if ($orderCarrierId > 0) {
            $orderCarrier = new OrderCarrier($orderCarrierId);
            if (Validate::isLoadedObject($orderCarrier)) {
                $orderCarrier->tracking_number = $trackingNumber;
                if ($carrierId !== null && $carrierId > 0) {
                    $orderCarrier->id_carrier = $carrierId;
                }
                $orderCarrier->update();
            }
        } elseif ($carrierId !== null && $carrierId > 0) {
            $orderCarrier = new OrderCarrier();
            $orderCarrier->id_order = (int) $order->id;
            $orderCarrier->id_carrier = $carrierId;
            $orderCarrier->tracking_number = $trackingNumber;
            $orderCarrier->add();
        }

        $order->shipping_number = $trackingNumber;
        if ($carrierId !== null && $carrierId > 0) {
            $order->id_carrier = $carrierId;
        }

        return (bool) $order->update();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatOrderRow(array $row): array
    {
        $statusName = '';
        if (!empty($row['status_name'])) {
            $statusName = (string) $row['status_name'];
        } elseif (isset($row['current_state'])) {
            $statusName = (string) $row['current_state'];
        }

        return [
            'id' => isset($row['id_order']) ? (int) $row['id_order'] : 0,
            'reference' => isset($row['reference']) ? (string) $row['reference'] : '',
            'status' => $statusName,
            'status_color' => isset($row['status_color']) ? (string) $row['status_color'] : '',
            'total_paid' => isset($row['total_paid_tax_incl']) ? (float) $row['total_paid_tax_incl'] : 0.0,
            'currency' => isset($row['currency_iso']) ? (string) $row['currency_iso'] : '',
            'date_add' => isset($row['date_add']) ? (string) $row['date_add'] : null,
            'date_upd' => isset($row['date_upd']) ? (string) $row['date_upd'] : null,
            'has_invoice' => isset($row['invoice_number']) && (int) $row['invoice_number'] > 0,
            'customer' => [
                'id' => isset($row['id_customer']) ? (int) $row['id_customer'] : 0,
                'firstname' => isset($row['firstname']) ? (string) $row['firstname'] : '',
                'lastname' => isset($row['lastname']) ? (string) $row['lastname'] : '',
            ],
        ];
    }

    /**
     * Normalise et valide la valeur du paramètre `statuses` (filtre multi-statuts).
     *
     * Accepte :
     *  - une chaîne CSV : "2,3,4,5"
     *  - un tableau d'IDs (int ou string) : [2, 3, 4, 5]
     *  - null / chaîne vide → tableau vide (filtre ignoré)
     *
     * Seules les valeurs castées en int > 0 sont conservées (défense contre l'injection).
     * Le résultat est un tableau d'ints prêts à être interpolés dans un IN(...).
     *
     * @param mixed $raw
     * @return int[]
     */
    private function parseStatusesFilter($raw): array
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return [];
        }

        if (is_string($raw)) {
            $parts = explode(',', $raw);
        } elseif (is_array($raw)) {
            $parts = $raw;
        } else {
            return [];
        }

        $ids = [];
        foreach ($parts as $part) {
            $id = (int) $part;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Valide qu'une chaîne de date est au format Y-m-d ou Y-m-d H:i:s.
     *
     * @throws \InvalidArgumentException si le format est invalide.
     */
    private function validateDate(string $date): void
    {
        $date = trim($date);

        // Format Y-m-d H:i:s
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date);
        if ($dt !== false && $dt->format('Y-m-d H:i:s') === $date) {
            return;
        }

        // Format Y-m-d
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($dt !== false && $dt->format('Y-m-d') === $date) {
            return;
        }

        throw new \InvalidArgumentException(
            sprintf('Format de date invalide : "%s". Formats acceptés : Y-m-d ou Y-m-d H:i:s.', $date)
        );
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
            // Vérifier que l'état existe réellement : un id inexistant (ex. 999) ferait planter
            // OrderHistory::changeIdOrderState (500). On renvoie 0 → traité comme statut invalide.
            $id = (int) $reference;
            $exists = (int) Db::getInstance()->getValue(
                'SELECT id_order_state FROM ' . _DB_PREFIX_ . 'order_state WHERE id_order_state = ' . $id
            );

            return $exists > 0 ? $id : 0;
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
