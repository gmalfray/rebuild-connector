<?php

defined('_PS_VERSION_') || exit;

class BasketsService
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getBaskets(array $filters = []): array
    {
        $limit = $this->sanitizeLimit($filters['limit'] ?? self::DEFAULT_LIMIT);
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $query = new DbQuery();
        $query->select('c.id_cart, c.id_customer, c.id_currency, c.date_add, c.date_upd');
        $query->select('cu.firstname, cu.lastname, cu.email');
        $query->select('cur.iso_code AS currency_iso');
        $query->from('cart', 'c');
        $query->leftJoin('customer', 'cu', 'cu.id_customer = c.id_customer');
        $query->leftJoin('currency', 'cur', 'cur.id_currency = c.id_currency');

        if (!empty($filters['ids']) && is_array($filters['ids'])) {
            $ids = $this->sanitizeIds($filters['ids']);
            if ($ids !== []) {
                $query->where('c.id_cart IN (' . implode(',', $ids) . ')');
            } else {
                return [];
            }
        }

        if (!empty($filters['customer_id'])) {
            $query->where('c.id_customer = ' . (int) $filters['customer_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('c.date_add >= "' . pSQL((string) $filters['date_from']) . '"');
        }

        if (!empty($filters['date_to'])) {
            $query->where('c.date_add <= "' . pSQL((string) $filters['date_to']) . '"');
        }

        $query->orderBy('c.date_upd DESC');
        $query->limit($limit, $offset);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = Db::getInstance()->executeS($query) ?: [];
        if ($rows === []) {
            return [];
        }

        $cartIds = [];
        foreach ($rows as $row) {
            if (isset($row['id_cart'])) {
                $cartIds[] = (int) $row['id_cart'];
            }
        }

        $ordersMap = $this->mapOrdersByCartId($cartIds);

        $baskets = [];
        foreach ($rows as $row) {
            $formatted = $this->formatBasketRow($row, $ordersMap);
            if ($formatted !== null) {
                $baskets[] = $formatted;
            }
        }

        return $baskets;
    }

    /**
     * @return array<string, mixed>
     */
    public function getBasketById(int $cartId): array
    {
        if ($cartId <= 0) {
            return [];
        }

        $baskets = $this->getBaskets([
            'ids' => [$cartId],
            'limit' => 1,
            'offset' => 0,
        ]);

        if ($baskets === []) {
            return [];
        }

        $basket = $baskets[0];
        $products = $this->getBasketProducts($cartId);
        $basket['products'] = $products;

        return $basket;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, bool> $ordersMap
     * @return array<string, mixed>|null
     */
    private function formatBasketRow(array $row, array $ordersMap): ?array
    {
        if (!isset($row['id_cart'])) {
            return null;
        }

        $cartId = (int) $row['id_cart'];
        if ($cartId <= 0) {
            return null;
        }

        $summary = $this->buildCartSummary($cartId);

        return [
            'id' => $cartId,
            'customer' => [
                'id' => isset($row['id_customer']) ? (int) $row['id_customer'] : null,
                'firstname' => isset($row['firstname']) ? (string) $row['firstname'] : null,
                'lastname' => isset($row['lastname']) ? (string) $row['lastname'] : null,
                'email' => isset($row['email']) ? (string) $row['email'] : null,
            ],
            'currency' => [
                'id' => isset($row['id_currency']) ? (int) $row['id_currency'] : null,
                'iso' => isset($row['currency_iso']) ? (string) $row['currency_iso'] : null,
            ],
            'totals' => [
                'tax_excl' => $summary['total_excl'],
                'tax_incl' => $summary['total_incl'],
            ],
            'items_count' => $summary['items_count'],
            'has_order' => isset($ordersMap[$cartId]),
            'dates' => [
                'created_at' => isset($row['date_add']) ? (string) $row['date_add'] : null,
                'updated_at' => isset($row['date_upd']) ? (string) $row['date_upd'] : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCartSummary(int $cartId): array
    {
        $totalIncl = 0.0;
        $totalExcl = 0.0;
        $itemsCount = 0;

        if (!class_exists('Cart')) {
            return [
                'total_incl' => $totalIncl,
                'total_excl' => $totalExcl,
                'items_count' => $itemsCount,
            ];
        }

        try {
            $cart = new Cart($cartId);
        } catch (\Throwable $exception) {
            return [
                'total_incl' => $totalIncl,
                'total_excl' => $totalExcl,
                'items_count' => $itemsCount,
            ];
        }

        if (!Validate::isLoadedObject($cart)) {
            return [
                'total_incl' => $totalIncl,
                'total_excl' => $totalExcl,
                'items_count' => $itemsCount,
            ];
        }

        try {
            $totalIncl = (float) $cart->getOrderTotal(true);
            $totalExcl = (float) $cart->getOrderTotal(false);
            $itemsCount = (int) $cart->getNbProducts();
        } catch (\Throwable $exception) {
            // ignore, keep defaults
        }

        return [
            'total_incl' => $totalIncl,
            'total_excl' => $totalExcl,
            'items_count' => $itemsCount,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getBasketProducts(int $cartId): array
    {
        if (!class_exists('Cart')) {
            return [];
        }

        try {
            $cart = new Cart($cartId);
        } catch (\Throwable $exception) {
            return [];
        }

        if (!Validate::isLoadedObject($cart)) {
            return [];
        }

        try {
            $products = $cart->getProducts();
        } catch (\Throwable $exception) {
            return [];
        }

        if (!is_array($products)) {
            return [];
        }

        $formatted = [];

        foreach ($products as $product) {
            if (!is_array($product) || !isset($product['id_product'])) {
                continue;
            }

            $quantity = isset($product['cart_quantity']) ? (int) $product['cart_quantity'] : 0;
            $priceIncl = isset($product['total_wt']) ? (float) $product['total_wt'] : 0.0;
            $priceExcl = isset($product['total']) ? (float) $product['total'] : 0.0;

            $productLinkRewrite = '';
            if (isset($product['link_rewrite'])) {
                if (is_string($product['link_rewrite'])) {
                    $productLinkRewrite = $product['link_rewrite'];
                } elseif (is_array($product['link_rewrite'])) {
                    $value = reset($product['link_rewrite']);
                    if (is_string($value)) {
                        $productLinkRewrite = $value;
                    }
                }
            }

            $imageUrl = null;
            if (isset($product['id_image'])) {
                $imageUrl = $this->resolveProductImageUrl(
                    $productLinkRewrite,
                    (int) $product['id_product'],
                    (int) $product['id_image']
                );
            }

            $formatted[] = [
                'product_id' => (int) $product['id_product'],
                'product_attribute_id' => isset($product['id_product_attribute']) ? (int) $product['id_product_attribute'] : null,
                'name' => isset($product['name']) ? (string) $product['name'] : null,
                'reference' => isset($product['reference']) ? (string) $product['reference'] : null,
                'quantity' => $quantity,
                'total_tax_incl' => $priceIncl,
                'total_tax_excl' => $priceExcl,
                'image' => $imageUrl,
            ];
        }

        return $formatted;
    }

    private function resolveProductImageUrl(string $linkRewrite, int $productId, int $imageId): ?string
    {
        if ($linkRewrite === '' || $imageId <= 0) {
            return null;
        }

        $link = $this->getLink();
        if ($link === null) {
            return null;
        }

        try {
            return $link->getImageLink($linkRewrite, $productId . '-' . $imageId, 'home_default');
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * @return Link|null
     */
    private function getLink()
    {
        if (class_exists('Context')) {
            $context = Context::getContext();
            if (isset($context->link) && $context->link instanceof Link) {
                return $context->link;
            }
        }

        if (class_exists('Link')) {
            return new Link();
        }

        return null;
    }

    /**
     * @param array<int> $ids
     * @return array<int, bool>
     */
    private function mapOrdersByCartId(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static function (int $value): bool {
            return $value > 0;
        }));

        if ($ids === []) {
            return [];
        }

        $query = new DbQuery();
        $query->select('id_cart');
        $query->from('orders');
        $query->where('id_cart IN (' . implode(',', $ids) . ')');

        /** @var array<int, array<string, mixed>> $rows */
        $rows = Db::getInstance()->executeS($query) ?: [];

        $map = [];
        foreach ($rows as $row) {
            if (isset($row['id_cart'])) {
                $map[(int) $row['id_cart']] = true;
            }
        }

        return $map;
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, int>
     */
    private function sanitizeIds(array $ids): array
    {
        $sanitized = [];
        foreach ($ids as $id) {
            $value = (int) $id;
            if ($value > 0) {
                $sanitized[] = $value;
            }
        }

        return array_values(array_unique($sanitized));
    }

    private function sanitizeLimit($value): int
    {
        $limit = (int) $value;
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        if ($limit > self::MAX_LIMIT) {
            $limit = self::MAX_LIMIT;
        }

        return $limit;
    }
}
