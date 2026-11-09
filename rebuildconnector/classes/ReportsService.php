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

        // Vraies ventes uniquement : commandes valides, payantes (pivot offerte/payante identique
        // aux KPI du dashboard : total_paid_tax_incl = 0 / > 0), de la boutique courante.
        $query->where('o.valid = 1');
        $query->where('o.total_paid_tax_incl > 0');
        $query->where('o.id_shop = ' . (int) $shopId);

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
     * Acquisition par canal : chaque commande payante est rattachée à UN canal (indicatif).
     *
     * Pour chaque commande payante (valide, total TTC > 0, boutique courante) on cherche le DERNIER
     * contact non direct du même guest (orders.id_cart -> cart.id_guest -> connections.id_guest ->
     * connections_source) dans la fenêtre [date commande - window_days ; date commande]. Un contact est
     * non direct s'il porte un referer non vide ou un utm_source dans request_uri. Sans contact non
     * direct, la commande est comptée en « direct ». La somme des canaux égale donc le nombre de
     * commandes payantes de la période.
     *
     * Lecture seule. Coût : une sous-requête corrélée par commande payante de la période, bornée par
     * la fenêtre de dates et par id_guest/date_add ; pas de balayage non borné de connections_source
     * tant que date_from/date_to sont fournis (sans bornes, toutes les commandes payantes sont lues).
     *
     * @param array<string, mixed> $filters date_from, date_to, window_days (1-365, défaut 30)
     * @return array<string, mixed>
     * @throws \InvalidArgumentException si dates ou window_days sont invalides.
     */
    public function getAcquisitionChannels(array $filters = []): array
    {
        $from = $this->normalizeBound($filters['date_from'] ?? null, false);
        $to = $this->normalizeBound($filters['date_to'] ?? null, true);
        if ($from !== null && $to !== null && $from > $to) {
            throw new \InvalidArgumentException('"date_from" doit être antérieure ou égale à "date_to".');
        }
        $windowDays = $this->normalizeWindowDays($filters['window_days'] ?? null);

        $shopId = $this->getShopId();
        $where = 'o.valid = 1 AND o.total_paid_tax_incl > 0' . ($shopId > 0 ? ' AND o.id_shop = ' . $shopId : '');
        if ($from !== null) {
            $where .= ' AND o.date_add >= "' . pSQL($from) . '"';
        }
        if ($to !== null) {
            $where .= ' AND o.date_add <= "' . pSQL($to) . '"';
        }

        $lastContact = 'SELECT cs.id_connections_source FROM ' . _DB_PREFIX_ . 'connections c'
            . ' INNER JOIN ' . _DB_PREFIX_ . 'connections_source cs ON cs.id_connections = c.id_connections'
            . ' WHERE c.id_guest = ca.id_guest AND ca.id_guest > 0'
            . ' AND cs.date_add <= o.date_add'
            . ' AND cs.date_add >= DATE_SUB(o.date_add, INTERVAL ' . $windowDays . ' DAY)'
            . ' AND (cs.http_referer <> "" OR cs.request_uri LIKE "%utm\_source=%")'
            . ' ORDER BY cs.date_add DESC, cs.id_connections_source DESC LIMIT 1';

        $sql = 'SELECT x.id_order, t.http_referer, t.request_uri FROM ('
            . 'SELECT o.id_order, (' . $lastContact . ') AS id_cs FROM ' . _DB_PREFIX_ . 'orders o'
            . ' LEFT JOIN ' . _DB_PREFIX_ . 'cart ca ON ca.id_cart = o.id_cart'
            . ' WHERE ' . $where
            . ') x LEFT JOIN ' . _DB_PREFIX_ . 'connections_source t ON t.id_connections_source = x.id_cs';

        /** @var array<int, array<string, mixed>> $rows */
        $rows = Db::getInstance()->executeS($sql) ?: [];

        $counts = ['direct' => 0];
        foreach ($rows as $row) {
            $referer = $row['http_referer'] ?? null;
            $uri = $row['request_uri'] ?? null;
            $key = $referer === null && $uri === null
                ? 'direct'
                : self::deriveChannel((string) $referer, (string) $uri);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $channels = [];
        foreach ($counts as $key => $orders) {
            $channels[] = ['key' => (string) $key, 'orders' => $orders];
        }
        usort($channels, static function (array $a, array $b): int {
            return $b['orders'] <=> $a['orders'] ?: strcmp($a['key'], $b['key']);
        });

        return [
            'channels' => $channels,
            'window_days' => $windowDays,
            'period' => ['date_from' => $from, 'date_to' => $to],
        ];
    }

    /**
     * Canal d'un contact non direct : utm_source d'abord, puis referer, sinon « autre ».
     */
    public static function deriveChannel(string $referer, string $requestUri): string
    {
        if (stripos($requestUri, 'utm_source=') !== false
            && preg_match('/[?&]utm_source=([^&#]*)/i', $requestUri, $m) === 1) {
            $utm = strtolower(trim(urldecode($m[1])));
            $utm = (string) preg_replace('/[^a-z0-9_. -]/', '', $utm);
            $utm = substr($utm, 0, 40);
            if ($utm !== '') {
                if ($utm === 'ig' || $utm === 'instagram') {
                    return 'instagram';
                }

                return $utm;
            }
        }

        $referer = strtolower(trim($referer));
        if ($referer === '') {
            return 'autre';
        }
        $host = parse_url($referer, PHP_URL_HOST);
        $haystack = is_string($host) && $host !== '' ? $host : $referer;

        $map = [
            'instagram' => ['instagram', 'linktr.ee'],
            'recherche' => ['google', 'bing', 'duckduckgo', 'yahoo', 'brave', 'qwant'],
            'facebook' => ['facebook'],
            'pinterest' => ['pinterest'],
            'email' => ['brevo', 'sendinblue'],
        ];
        foreach ($map as $channel => $needles) {
            foreach ($needles as $needle) {
                if (strpos($haystack, $needle) !== false) {
                    return $channel;
                }
            }
        }

        return 'autre';
    }

    /**
     * @param mixed $value
     * @throws \InvalidArgumentException hors 1-365 ou non entier.
     */
    private function normalizeWindowDays($value): int
    {
        if ($value === null || $value === false || trim((string) $value) === '') {
            return 30;
        }
        if (!is_numeric($value) || (string) (int) $value !== trim((string) $value)) {
            throw new \InvalidArgumentException('"window_days" doit être un entier entre 1 et 365.');
        }
        $days = (int) $value;
        if ($days < 1 || $days > 365) {
            throw new \InvalidArgumentException('"window_days" doit être un entier entre 1 et 365.');
        }

        return $days;
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
     * Synthèse avis (+ SAV en best-effort).
     *
     * - `total_reviews` / `average_rating` : TOUS les avis publiés (`validated=1, deleted=0`, importés
     *   compris) dont `date_add` est dans la période. `average_rating` est `null` sans avis.
     * - `native_reviews` : sous-ensemble des avis publiés déposés sur le site (`source = 'native'`),
     *   hors avis importés (Etsy).
     * - `pending_reviews` : file de modération actuelle, non bornée par la période.
     * - `review_rate` : `native_reviews / orders * 100` (1 décimale), où `orders` = nombre de vraies
     *   ventes (`o.valid = 1 AND o.total_paid_tax_incl > 0`, boutique courante) dont `o.date_add`
     *   est dans la même période. `null` si `orders = 0`.
     * - `sav` : voir SavService::getThreadStats() ; bloc OMIS si le calcul échoue.
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
        $native = (int) $reviews['native_reviews'];

        $result = [
            'total_reviews' => $total,
            'native_reviews' => $native,
            'average_rating' => $reviews['average_rating'],
            'pending_reviews' => (int) $reviews['pending_reviews'],
            'orders' => $orders,
            'review_rate' => $orders > 0 ? round($native / $orders * 100, 1) : null,
            'period' => ['from' => $from, 'to' => $to],
        ];

        try {
            $result['sav'] = ($savService ?? new SavService())->getThreadStats($from, $to);
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
