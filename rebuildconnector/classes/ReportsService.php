<?php

defined('_PS_VERSION_') || exit;

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/LanguageResolver.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/SavService.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/Reviews/ReviewsBridgeInterface.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/Reviews/ReviewsUnavailableException.php';

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
     * Synthèse avis (+ SAV en best-effort).
     *
     * - `total_reviews` / `average_rating` : avis publiés (`validated=1, deleted=0`) dont `date_add`
     *   est dans la période (tout l'historique sans bornes). `average_rating` est `null` sans avis.
     * - `pending_reviews` : file de modération actuelle, non bornée par la période.
     * - `review_rate` : `total_reviews / orders * 100` (1 décimale), où `orders` = nombre de vraies
     *   ventes (`o.valid = 1 AND o.total_paid_tax_incl > 0`, boutique courante) dont `o.date_add`
     *   est dans la même période. `null` si `orders = 0`.
     * - `sav` : voir SavService::getResponseStats() ; bloc OMIS si le calcul échoue.
     *
     * @param array<string, mixed> $filters {date_from?: string, date_to?: string}
     * @return array<string, mixed>
     * @throws ReviewsUnavailableException si rbreviews n'est pas disponible.
     * @throws \InvalidArgumentException si les dates sont invalides ou incohérentes.
     */
    public function getReviewsSummary(ReviewsBridgeInterface $bridge, array $filters = [], ?SavService $savService = null): array
    {
        if (!$bridge->isAvailable()) {
            throw new ReviewsUnavailableException();
        }

        [$from, $to] = $this->resolveDateBounds($filters);
        $shopId = $this->getShopId();

        $reviews = $bridge->getSummary($shopId, $from, $to);
        $orders = $this->countRealSales($shopId, $from, $to);
        $total = (int) $reviews['total_reviews'];

        $result = [
            'total_reviews' => $total,
            'average_rating' => $reviews['average_rating'],
            'pending_reviews' => (int) $reviews['pending_reviews'],
            'orders' => $orders,
            'review_rate' => $orders > 0 ? round($total / $orders * 100, 1) : null,
            'period' => ['from' => $from, 'to' => $to],
        ];

        try {
            $result['sav'] = ($savService ?? new SavService())->getResponseStats($from, $to);
        } catch (\Throwable $exception) {
            // Best-effort : un échec SAV ne doit pas masquer la synthèse avis. Bloc omis.
        }

        return $result;
    }

    private function countRealSales(int $shopId, ?string $from, ?string $to): int
    {
        $query = new DbQuery();
        $query->select('COUNT(*)');
        $query->from('orders', 'o');
        $query->where('o.valid = 1');
        $query->where('o.total_paid_tax_incl > 0');
        if ($shopId > 0) {
            $query->where('o.id_shop = ' . $shopId);
        }
        if ($from !== null) {
            $query->where('o.date_add >= "' . pSQL($from) . '"');
        }
        if ($to !== null) {
            $query->where('o.date_add <= "' . pSQL($to) . '"');
        }

        $count = Db::getInstance()->getValue($query);

        return $count !== false ? (int) $count : 0;
    }

    /**
     * Normalise date_from/date_to en `Y-m-d H:i:s` (date seule : from à 00:00:00, to à 23:59:59,
     * le jour de `date_to` est donc inclus).
     *
     * @param array<string, mixed> $filters
     * @return array{0: string|null, 1: string|null}
     * @throws \InvalidArgumentException
     */
    private function resolveDateBounds(array $filters): array
    {
        $bounds = [];
        foreach (['date_from' => ' 00:00:00', 'date_to' => ' 23:59:59'] as $key => $suffix) {
            $value = isset($filters[$key]) && $filters[$key] !== false ? trim((string) $filters[$key]) : '';
            if ($value === '') {
                $bounds[] = null;
                continue;
            }
            $this->validateDate($value);
            $bounds[] = strlen($value) === 10 ? $value . $suffix : $value;
        }

        if ($bounds[0] !== null && $bounds[1] !== null && $bounds[0] > $bounds[1]) {
            throw new \InvalidArgumentException('"date_from" must be earlier than or equal to "date_to".');
        }

        return [$bounds[0], $bounds[1]];
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
