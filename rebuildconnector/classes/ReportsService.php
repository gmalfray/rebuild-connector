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
     * Univers de produit exposés, dans l'ordre stable de la réponse. Valeur = produit numérique ?
     * (le « poids du numérique » côté app = patrons_pdf + ebooks).
     */
    private const UNIVERSES = [
        'kits' => false,
        'patrons_pdf' => true,
        'ebooks' => true,
        'livres' => false,
        'fils' => false,
        'mercerie' => false,
        'autre' => false,
    ];

    private const UNIVERSE_FREE_PATTERNS = 'patrons_gratuits';
    private const UNIVERSE_EXCLUDED = 'composant_kit';

    /**
     * Ventes par univers de produit (vraies ventes : commandes valides payantes de la boutique
     * courante) + compteur d'acquisition des patrons gratuits (commandes valides, 0 EUR inclus).
     *
     * @param array<string, mixed> $filters date_from / date_to
     * @return array<string, mixed>
     * @throws \InvalidArgumentException si les dates sont dans un format invalide.
     */
    public function getSalesByUnivers(array $filters = []): array
    {
        $shopId = $this->getShopId();
        $caseSql = $this->universeCaseSql();

        // CA : le CASE est évalué une seule fois par ligne order_detail (EXISTS corrélés, aucun
        // JOIN sur category_product), donc une ligne de commande n'est jamais comptée deux fois.
        $query = new DbQuery();
        $query->select($caseSql . ' AS universe');
        $query->select('SUM(od.total_price_tax_incl) AS ca_tax_incl');
        $query->select('SUM(od.total_price_tax_excl) AS ca_tax_excl');
        $query->select('SUM(od.product_quantity) AS quantity');
        $query->select('COUNT(*) AS lines_count');
        $query->from('order_detail', 'od');
        $query->innerJoin('orders', 'o', 'o.id_order = od.id_order');
        $query->leftJoin('product', 'p', 'p.id_product = od.product_id');
        $query->where('o.valid = 1');
        $query->where('o.total_paid_tax_incl > 0');
        $query->where('o.id_shop = ' . (int) $shopId);
        $this->applyDateFilters($query, 'o.date_add', $filters);
        $query->groupBy('universe');

        /** @var array<int, array<string, mixed>> $rows */
        $rows = Db::getInstance()->executeS($query) ?: [];

        $byKey = [];
        foreach ($rows as $row) {
            $key = isset($row['universe']) ? (string) $row['universe'] : '';
            // Patrons gratuits (0 EUR) : jamais dans le CA ; composants de kit : exclus.
            if (!isset(self::UNIVERSES[$key])) {
                continue;
            }
            $byKey[$key] = $row;
        }

        $universes = [];
        foreach (self::UNIVERSES as $key => $isDigital) {
            $row = $byKey[$key] ?? [];
            $universes[] = [
                'key' => $key,
                'is_digital' => $isDigital,
                'ca_tax_incl' => round((float) ($row['ca_tax_incl'] ?? 0), 2),
                'ca_tax_excl' => round((float) ($row['ca_tax_excl'] ?? 0), 2),
                'quantity' => (int) ($row['quantity'] ?? 0),
                'lines' => (int) ($row['lines_count'] ?? 0),
            ];
        }

        // Acquisition : patrons gratuits, o.valid = 1 mais sans contrainte total_paid_tax_incl > 0.
        $free = new DbQuery();
        $free->select('SUM(od.product_quantity) AS quantity');
        $free->select('COUNT(*) AS lines_count');
        $free->from('order_detail', 'od');
        $free->innerJoin('orders', 'o', 'o.id_order = od.id_order');
        $free->leftJoin('product', 'p', 'p.id_product = od.product_id');
        $free->where('o.valid = 1');
        $free->where('o.id_shop = ' . (int) $shopId);
        $free->where($caseSql . " = '" . self::UNIVERSE_FREE_PATTERNS . "'");
        $this->applyDateFilters($free, 'o.date_add', $filters);

        /** @var array<int, array<string, mixed>> $freeRows */
        $freeRows = Db::getInstance()->executeS($free) ?: [];
        $freeRow = $freeRows[0] ?? [];

        return [
            'universes' => $universes,
            'free_patterns' => [
                'quantity' => (int) ($freeRow['quantity'] ?? 0),
                'lines' => (int) ($freeRow['lines_count'] ?? 0),
            ],
            'period' => [
                'date_from' => !empty($filters['date_from']) ? trim((string) $filters['date_from']) : null,
                'date_to' => !empty($filters['date_to']) ? trim((string) $filters['date_to']) : null,
            ],
        ];
    }

    /**
     * Expression CASE de classement par univers (1re condition vraie gagne), évaluée par ligne
     * order_detail (alias `od`) avec le produit joint (alias `p`). Appartenance à une catégorie via
     * EXISTS corrélé : un produit multi-catégories ne multiplie jamais les lignes.
     */
    private function universeCaseSql(): string
    {
        $cp = _DB_PREFIX_ . 'category_product';
        $cat = _DB_PREFIX_ . 'category';

        $inCat = static function (int $id) use ($cp): string {
            return 'EXISTS (SELECT 1 FROM ' . $cp . ' cp WHERE cp.id_product = od.product_id AND cp.id_category = ' . $id . ')';
        };
        // Sous-arbre (nested set) : toute catégorie du produit incluse dans [nleft, nright] de la racine.
        $inTree = static function (int $rootId) use ($cp, $cat): string {
            return 'EXISTS (SELECT 1 FROM ' . $cp . ' cps'
                . ' INNER JOIN ' . $cat . ' cc ON cc.id_category = cps.id_category'
                . ' INNER JOIN ' . $cat . ' cr ON cr.id_category = ' . $rootId
                . ' WHERE cps.id_product = od.product_id AND cc.nleft >= cr.nleft AND cc.nright <= cr.nright)';
        };

        return 'CASE'
            . ' WHEN (p.cache_is_pack = 1 OR ' . $inCat(13) . ") THEN 'kits'"
            . ' WHEN ' . $inCat(20) . " THEN '" . self::UNIVERSE_EXCLUDED . "'"
            . ' WHEN (p.is_virtual = 1 AND ' . $inCat(64) . ") THEN '" . self::UNIVERSE_FREE_PATTERNS . "'"
            . ' WHEN (p.is_virtual = 1 AND (' . $inCat(68) . " OR p.reference LIKE 'EBOOK%')) THEN 'ebooks'"
            . " WHEN p.is_virtual = 1 THEN 'patrons_pdf'"
            . ' WHEN ' . $inCat(25) . " THEN 'livres'"
            . ' WHEN ' . $inTree(16) . " THEN 'fils'"
            . ' WHEN ' . $inTree(10) . " THEN 'mercerie'"
            . " ELSE 'autre' END";
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
