<?php

defined('_PS_VERSION_') || exit;

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/LanguageResolver.php';

class DashboardService
{
    /** Seuil de stock bas en dessous duquel un produit apparaît en alerte. */
    public const LOW_STOCK_THRESHOLD = 5;

    /**
     * @param string $period          Preset temporel (today/week/month/quarter/year). Ignoré si $customFrom/$customTo sont fournis.
     * @param int    $lowStockThreshold Seuil de stock bas (défaut : 5)
     * @param \DateTimeImmutable|null $customFrom Début de la plage libre (inclus, heure déjà setée). Doit être fourni avec $customTo.
     * @param \DateTimeImmutable|null $customTo   Fin de la plage libre (inclus, heure déjà setée). Doit être fourni avec $customFrom.
     * @return array<string, mixed>
     */
    public function getMetrics(
        string $period = 'month',
        int $lowStockThreshold = self::LOW_STOCK_THRESHOLD,
        ?\DateTimeImmutable $customFrom = null,
        ?\DateTimeImmutable $customTo = null
    ): array {
        if ($customFrom !== null && $customTo !== null) {
            $from = $customFrom;
            $to = $customTo;
        } else {
            $range = $this->resolvePeriodRange($period);
            $from = $range['from'];
            $to = $range['to'];
        }
        $fromSql = pSQL($from->format('Y-m-d H:i:s'));
        $toSql = pSQL($to->format('Y-m-d H:i:s'));

        $db = Db::getInstance();
        $shopId = $this->getShopId();
        // Protection IDOR/multiboutique : `orders` n'a pas d'alias dans ces requêtes brutes,
        // d'où une clause id_shop concaténée directement plutôt qu'un DbQuery::where('o.id_shop = ...').
        $shopFilterSql = $shopId > 0 ? ' AND id_shop = ' . $shopId : '';

        $ordersCount = (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'orders WHERE date_add BETWEEN "' . $fromSql . '" AND "' . $toSql . '"'
            . $shopFilterSql
        );

        // CA = revenu produits uniquement, HORS frais de port (total_paid_tax_incl inclut le
        // port ; on retranche total_shipping_tax_incl pour obtenir le CA "marchandise").
        // Même base fiscale conservée qu'avant (TTC pour revenueTaxIncl, HT pour revenueTaxExcl) :
        // seul le retrait du port change, pas la conversion TTC/HT.
        $revenueTaxIncl = (float) $db->getValue(
            'SELECT SUM(total_paid_tax_incl - total_shipping_tax_incl) FROM ' . _DB_PREFIX_ . 'orders WHERE date_add BETWEEN "'
            . $fromSql . '" AND "' . $toSql . '"' . $shopFilterSql
        );

        $revenueTaxExcl = (float) $db->getValue(
            'SELECT SUM(total_paid_tax_excl - total_shipping_tax_excl) FROM ' . _DB_PREFIX_ . 'orders WHERE date_add BETWEEN "'
            . $fromSql . '" AND "' . $toSql . '"' . $shopFilterSql
        );

        $taxCollected = max(0.0, $revenueTaxIncl - $revenueTaxExcl);

        $customersCount = (int) $db->getValue(
            'SELECT COUNT(DISTINCT id_customer) FROM ' . _DB_PREFIX_ . 'orders WHERE date_add BETWEEN "'
            . $fromSql . '" AND "' . $toSql . '"' . $shopFilterSql
        );

        // order_return n'a pas de colonne id_shop : on filtre via une jointure sur orders.
        $returnsShopJoin = $shopId > 0
            ? ' INNER JOIN ' . _DB_PREFIX_ . 'orders o ON o.id_order = order_return.id_order AND o.id_shop = ' . $shopId
            : '';
        $returns = (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'order_return' . $returnsShopJoin
            . ' WHERE order_return.date_add BETWEEN "' . $fromSql . '" AND "' . $toSql . '"'
        );

        $isCustomRange = $customFrom !== null && $customTo !== null;
        if ($isCustomRange) {
            // Plage libre (from/to explicites) : comparatif = fenêtre équivalente précédente de
            // même durée, décalée en arrière (comportement historique inchangé, cf. docs/api.md).
            $duration = $to->getTimestamp() - $from->getTimestamp();
            $prevTo = $from->modify('-1 second');
            // setTimestamp() sur $prevTo préserve le fuseau boutique (shopTimeZone) de $from/$to.
            $prevFrom = $prevTo->setTimestamp($prevTo->getTimestamp() - $duration);
        } else {
            // Preset civil (today/week/month/quarter/year) : comparatif N-1 "à date comparable"
            // (même période civile l'an dernier, tronquée à la durée déjà écoulée depuis le début
            // de la période courante) — cf. resolveYoyComparisonRange().
            $comparisonRange = $this->resolveYoyComparisonRange($from, $to, $period);
            $prevFrom = $comparisonRange['from'];
            $prevTo = $comparisonRange['to'];
        }
        $prevFromSql = pSQL($prevFrom->format('Y-m-d H:i:s'));
        $prevToSql = pSQL($prevTo->format('Y-m-d H:i:s'));
        // Même correction hors-port sur la période comparative (previous_turnover), sinon le
        // comparatif CA serait faussé en sens inverse (port inclus d'un côté, exclu de l'autre).
        $previousTurnover = (float) $db->getValue(
            'SELECT SUM(total_paid_tax_incl - total_shipping_tax_incl) FROM ' . _DB_PREFIX_ . 'orders WHERE date_add BETWEEN "'
            . $prevFromSql . '" AND "' . $prevToSql . '"' . $shopFilterSql
        );

        $currency = $this->resolveCurrencyIso();
        $averageBasket = $ordersCount > 0 ? $revenueTaxIncl / $ordersCount : 0.0;

        // --- Nouvelles métriques ---
        $pendingOrdersCount = $this->countPendingOrders();
        $lowStockProducts = $this->getLowStockProducts(max(0, $lowStockThreshold));
        $conversionRate = $this->computeConversionRate($ordersCount, $fromSql, $toSql);

        return [
            'period' => [
                'label' => $period,
                'from' => $from->format(DATE_ATOM),
                'to' => $to->format(DATE_ATOM),
            ],
            // Champ additif (v1.15.0) : bornes de la période de comparaison réellement utilisées
            // pour previous_turnover. Optionnel — ne pas modifier la lecture existante de
            // previous_turnover, qui reste au même endroit avec la même sémantique (un nombre).
            'comparison_period' => [
                'from' => $prevFrom->format(DATE_ATOM),
                'to' => $prevTo->format(DATE_ATOM),
            ],
            'turnover' => $revenueTaxIncl,
            'previous_turnover' => $previousTurnover,
            'orders_count' => $ordersCount,
            'customers_count' => $customersCount,
            'products_count' => $this->countActiveProducts(),
            'revenue' => $revenueTaxIncl,
            'revenue_tax_incl' => $revenueTaxIncl,
            'revenue_tax_excl' => $revenueTaxExcl,
            'tax_collected' => $taxCollected,
            'average_basket' => $averageBasket,
            'average_order_value' => $averageBasket,
            'returns' => $returns,
            'currency' => $currency,
            // Nouvelles clés
            'pending_orders_count' => $pendingOrdersCount,
            'conversion_rate' => $conversionRate,
            'low_stock_alerts' => $lowStockProducts,
            'chart' => $this->buildChart($from, $to, $period, $customFrom !== null && $customTo !== null),
        ];
    }

    /**
     * Nombre de commandes en attente de paiement.
     *
     * PrestaShop stocke les états d'attente de paiement dans la table order_state,
     * identifiés par le flag logable = 0 (commandes non validées financièrement) et
     * le flag paid = 0. La configuration PS_OS_PAYMENT pointe l'état "payé", donc
     * on cible l'état par défaut "En attente de virement" (PS_OS_BANKWIRE) et
     * "En attente de paiement" (PS_OS_CHEQUE, PS_OS_PREPARATION, etc.).
     *
     * En pratique, on sélectionne les commandes dont l'état courant est associé
     * à un order_state dont paid = 0 ET logable = 0 (= commande non encore confirmée).
     */
    protected function countPendingOrders(): int
    {
        $shopId = $this->getShopId();
        $shopFilterSql = $shopId > 0 ? ' AND o.id_shop = ' . $shopId : '';

        $result = Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'orders o'
            . ' INNER JOIN ' . _DB_PREFIX_ . 'order_state os ON os.id_order_state = o.current_state'
            . ' WHERE os.paid = 0 AND os.logable = 0' . $shopFilterSql
        );

        return $result !== false ? (int) $result : 0;
    }

    /**
     * Calcule un taux de conversion approximatif sur la période.
     *
     * PrestaShop ne stocke pas les "visites" dans une table accessible sans module
     * de statistiques (requiert statvisits / statsvisits). En l'absence de cette
     * donnée fiable, on utilise le nombre de paniers créés sur la période comme
     * proxy des sessions d'achat.
     *
     * Taux = commandes valides / paniers créés × 100
     *
     * Si la table ps_connections (StatsPro) est disponible, elle serait préférable.
     * La valeur null est retournée si aucune donnée de visite n'est disponible.
     *
     * @return array{rate: float|null, orders: int, sessions_proxy: int, note: string}
     */
    protected function computeConversionRate(int $ordersCount, string $fromSql, string $toSql): array
    {
        // Proxy : paniers créés sur la période (même intervalle que les commandes).
        $shopId = $this->getShopId();
        $shopFilterSql = $shopId > 0 ? ' AND id_shop = ' . $shopId : '';
        $cartsCount = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'cart'
            . ' WHERE date_add BETWEEN "' . $fromSql . '" AND "' . $toSql . '"' . $shopFilterSql
        );

        $rate = null;
        if ($cartsCount > 0) {
            $rate = round(($ordersCount / $cartsCount) * 100, 2);
        }

        return [
            'rate' => $rate,
            'orders' => $ordersCount,
            'sessions_proxy' => $cartsCount,
            'note' => 'Proxy basé sur les paniers créés (ps_cart). Pour un taux de conversion fiable basé sur les visites, activez le module statsvisits de PrestaShop.',
        ];
    }

    /**
     * Retourne la liste des produits actifs dont le stock est inférieur au seuil.
     *
     * @param int $threshold Seuil (inclus)
     * @return array<int, array<string, mixed>>
     */
    protected function getLowStockProducts(int $threshold = self::LOW_STOCK_THRESHOLD): array
    {
        $langId = $this->getLanguageId();
        $shopId = $this->getShopId();

        $query = new DbQuery();
        $query->select('p.id_product, pl.name, sa.quantity');
        $query->select('i.id_image');
        $query->from('product', 'p');
        $query->innerJoin(
            'stock_available',
            'sa',
            'sa.id_product = p.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . (int) $shopId
        );
        $query->leftJoin(
            'product_lang',
            'pl',
            'pl.id_product = p.id_product AND pl.id_lang = ' . (int) $langId . ' AND pl.id_shop = ' . (int) $shopId
        );
        $query->leftJoin(
            'image',
            'i',
            'i.id_product = p.id_product AND i.cover = 1'
        );
        $query->where('p.active = 1');
        $query->where('sa.quantity <= ' . (int) $threshold);
        $query->orderBy('sa.quantity ASC');
        $query->limit(50);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = Db::getInstance()->executeS($query) ?: [];

        $baseUrl = $this->resolveImgBaseUrl();
        $results = [];

        foreach ($rows as $row) {
            $productId = isset($row['id_product']) ? (int) $row['id_product'] : 0;
            $imageId = isset($row['id_image']) ? (int) $row['id_image'] : 0;

            $imageUrl = null;
            if ($imageId > 0 && $baseUrl !== '') {
                // Format PS : /img/p/1/2/12.jpg
                $digits = str_split((string) $imageId);
                $imageUrl = $baseUrl . '/img/p/' . implode('/', $digits) . '/' . $imageId . '.jpg';
            }

            $results[] = [
                'product_id' => $productId,
                'name' => isset($row['name']) ? (string) $row['name'] : null,
                'quantity' => isset($row['quantity']) ? (int) $row['quantity'] : 0,
                'image_url' => $imageUrl,
            ];
        }

        return $results;
    }

    /**
     * Fuseau de la boutique (PS_TIMEZONE), avec repli sur le fuseau PHP puis UTC.
     */
    private static function shopTimeZone(): \DateTimeZone
    {
        $tz = Configuration::get('PS_TIMEZONE');
        if (is_string($tz) && $tz !== '') {
            try {
                return new \DateTimeZone($tz);
            } catch (\Exception $e) {
                // PS_TIMEZONE invalide → repli ci-dessous
            }
        }

        return new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
    }

    /**
     * Bornes CIVILES (depuis v1.15.0) : chaque preset part du début de la période civile en cours
     * (fuseau boutique) et court jusqu'à `now` — la période courante est donc potentiellement
     * PARTIELLE (ex : « month » un 13 du mois = du 1er au 13, pas les 30 derniers jours glissants).
     * C'est voulu : ces presets servent désormais à piloter un objectif sur la période civile,
     * comparé à la même période civile l'an dernier (cf. resolveYoyComparisonRange()).
     */
    private function resolvePeriodRange(string $period): array
    {
        // Heure BOUTIQUE (PS_TIMEZONE), pas le fuseau PHP ambiant : sinon, sur une instance où
        // PHP tourne en UTC, « aujourd'hui » vise la veille tôt le matin (les commandes passées
        // après minuit heure boutique ne sont pas comptées). date_add est stocké en heure boutique.
        $now = new \DateTimeImmutable('now', self::shopTimeZone());

        // Valeurs envoyées par l'app : today / week / month / quarter / year (contrat de requête
        // inchangé), mais désormais résolues en périodes CIVILES plutôt que glissantes.
        switch (Tools::strtolower($period)) {
            case 'today':
            case 'day':
                $from = $now->setTime(0, 0, 0);
                break;
            case 'week':
                // Semaine civile ISO : lundi 00:00 de la semaine en cours.
                $isoDayOfWeek = (int) $now->format('N'); // 1 (lundi) .. 7 (dimanche)
                $from = $now->modify('-' . ($isoDayOfWeek - 1) . ' days')->setTime(0, 0, 0);
                break;
            case 'quarter':
                // Trimestre civil en cours : Q1 janv-mars, Q2 avr-juin, Q3 juil-sept, Q4 oct-déc.
                $quarterStartMonth = ((int) floor(((int) $now->format('n') - 1) / 3)) * 3 + 1;
                $from = $now->setDate((int) $now->format('Y'), $quarterStartMonth, 1)->setTime(0, 0, 0);
                break;
            case 'year':
                $from = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0, 0);
                break;
            case 'month':
            default:
                $from = $now->setDate((int) $now->format('Y'), (int) $now->format('n'), 1)->setTime(0, 0, 0);
                break;
        }

        return [
            'from' => $from,
            // `to` = maintenant (pas fin de journée) : la période civile courante peut être partielle.
            'to' => $now,
        ];
    }

    /**
     * Comparaison N-1 « à date comparable » pour les presets civils : même période civile l'an
     * dernier, tronquée à la même durée déjà écoulée (D = $to - $from) que la période courante.
     *
     * Ex. : « month » consulté le 13 juillet → comparaison du 1er au 13 juillet N-1 (pas le mois
     * N-1 entier), pour comparer une durée écoulée identique (pratique standard de contrôle de
     * gestion « à date »/« like-for-like »).
     *
     * @return array{from: \DateTimeImmutable, to: \DateTimeImmutable}
     */
    private function resolveYoyComparisonRange(\DateTimeImmutable $from, \DateTimeImmutable $to, string $period): array
    {
        $prevFrom = $this->resolvePreviousYearPeriodStart($from, Tools::strtolower($period));
        $elapsedSeconds = $to->getTimestamp() - $from->getTimestamp();
        // setTimestamp() préserve le fuseau boutique (shopTimeZone) porté par $prevFrom.
        $prevTo = $prevFrom->setTimestamp($prevFrom->getTimestamp() + $elapsedSeconds);

        return [
            'from' => $prevFrom,
            'to' => $prevTo,
        ];
    }

    /**
     * Début de la même période civile un an plus tôt.
     *
     * Cas particulier `week` : la même date civile l'an dernier ne tombe pas forcément un lundi
     * (le calendrier se décale d'1 jour, 2 les années à cheval sur un 29 février) → on reprend le
     * lundi de la semaine qui contient cette date, pour rester sur une semaine civile ISO complète.
     */
    private function resolvePreviousYearPeriodStart(\DateTimeImmutable $periodStart, string $periodLower): \DateTimeImmutable
    {
        $sameDateLastYear = $this->sameCivilDateOneYearEarlier($periodStart);

        if ($periodLower === 'week') {
            $isoDayOfWeek = (int) $sameDateLastYear->format('N');
            return $isoDayOfWeek > 1 ? $sameDateLastYear->modify('-' . ($isoDayOfWeek - 1) . ' days') : $sameDateLastYear;
        }

        return $sameDateLastYear;
    }

    /**
     * Reproduit le même mois/jour un an plus tôt (heure et fuseau préservés).
     *
     * Cas limite 29 février : une année N-1 non bissextile n'a pas de 29 février → on retombe sur
     * le 28 février N-1 (convention standard, cf. contrôle de gestion « à date »).
     */
    private function sameCivilDateOneYearEarlier(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $year = (int) $date->format('Y') - 1;
        $month = (int) $date->format('n');
        $day = (int) $date->format('j');

        if ($month === 2 && $day === 29 && !self::isGregorianLeapYear($year)) {
            $day = 28;
        }

        return $date->setDate($year, $month, $day);
    }

    private static function isGregorianLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    /**
     * Résout la granularité du graphique selon la période et la plage réelle.
     *
     * - today / day → horaire (24 points)
     * - year (preset civil) → mensuel (jusqu'à 12 points, cf. resolvePeriodRange())
     * - plage libre d'exactement 1 jour → horaire
     * - tout le reste (week/month/quarter, plage libre) → journalier
     *
     * @param bool $isCustomRange La plage est-elle libre (from/to explicites) ?
     */
    private function resolveGranularity(\DateTimeImmutable $from, \DateTimeImmutable $to, string $period, bool $isCustomRange): string
    {
        $periodLower = Tools::strtolower($period);
        if ($periodLower === 'today' || $periodLower === 'day') {
            return 'hour';
        }

        if (!$isCustomRange && $periodLower === 'year') {
            return 'month';
        }

        if ($isCustomRange) {
            $days = (int) $from->diff($to)->days;
            return $days === 0 ? 'hour' : 'day';
        }

        return 'day';
    }

    /**
     * Construit un index de nouveaux clients par bucket temporel.
     *
     * Nouveaux clients = clients dont la date d'inscription (date_add dans ps_customer)
     * tombe dans le bucket. Distinct de `customers` (qui compte les clients ayant commandé).
     *
     * @param string $granularity 'hour', 'day' ou 'month'
     * @return array<string, int> Index clé = format du bucket ('Y-m-d H:00:00', 'Y-m-d' ou 'Y-m-01')
     */
    private function buildNewCustomersIndex(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $granularity
    ): array {
        $fromSql = pSQL($from->format('Y-m-d H:i:s'));
        $toSql = pSQL($to->format('Y-m-d H:i:s'));
        $shopId = (int) $this->getShopId();

        $query = new DbQuery();
        $query->from('customer', 'c');
        $query->where('c.date_add BETWEEN "' . $fromSql . '" AND "' . $toSql . '"');
        $query->where('c.deleted = 0');
        $query->where('c.id_shop = ' . $shopId);

        if ($granularity === 'hour') {
            $query->select('DATE_FORMAT(c.date_add, \'%Y-%m-%d %H:00:00\') AS bucket');
        } elseif ($granularity === 'month') {
            $query->select('DATE_FORMAT(c.date_add, \'%Y-%m-01\') AS bucket');
        } else {
            $query->select('DATE(c.date_add) AS bucket');
        }

        $query->select('COUNT(*) AS new_customers');
        $query->groupBy('bucket');

        $rows = (array) Db::getInstance()->executeS($query);
        $index = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            if (isset($row['bucket'])) {
                $index[(string) $row['bucket']] = isset($row['new_customers']) ? (int) $row['new_customers'] : 0;
            }
        }

        return $index;
    }

    /**
     * @param bool $isCustomRange La plage provient-elle de from/to explicites ?
     * @return array<int, array<string, mixed>>
     */
    private function buildChart(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $period = 'month',
        bool $isCustomRange = false
    ): array {
        $fromSql = pSQL($from->format('Y-m-d H:i:s'));
        $toSql = pSQL($to->format('Y-m-d H:i:s'));
        $shopId = $this->getShopId();

        $granularity = $this->resolveGranularity($from, $to, $period, $isCustomRange);
        $isHourly = $granularity === 'hour';

        // Index des nouveaux clients par bucket.
        $newCustomersIndex = $this->buildNewCustomersIndex($from, $to, $granularity);

        if ($isHourly) {
            // Granularité horaire : la vue journalière ne produit qu'1 point en granularité
            // journalière → la courbe est vide côté app (guard size < 2). On groupe par heure.
            $query = new DbQuery();
            $query->select('DATE_FORMAT(o.date_add, \'%Y-%m-%d %H:00:00\') AS hour');
            // CA hors frais de port (cf. getMetrics()) : même correction sur la série horaire.
            $query->select('SUM(o.total_paid_tax_incl - o.total_shipping_tax_incl) AS revenue');
            $query->select('COUNT(*) AS orders');
            $query->select('COUNT(DISTINCT o.id_customer) AS customers');
            $query->from('orders', 'o');
            $query->where('o.date_add BETWEEN "' . $fromSql . '" AND "' . $toSql . '"');
            if ($shopId > 0) {
                $query->where('o.id_shop = ' . $shopId);
            }
            $query->groupBy('hour');
            $query->orderBy('hour ASC');

            $rows = (array) Db::getInstance()->executeS($query);
            $indexed = [];
            foreach ($rows as $row) {
                /** @var array<string, mixed> $row */
                if (!isset($row['hour'])) {
                    continue;
                }

                $hour = (string) $row['hour'];
                $indexed[$hour] = [
                    'revenue' => isset($row['revenue']) ? (float) $row['revenue'] : 0.0,
                    'orders' => isset($row['orders']) ? (int) $row['orders'] : 0,
                    'customers' => isset($row['customers']) ? (int) $row['customers'] : 0,
                ];
            }

            $chart = [];
            // Itération heure par heure de 00:00 à 23:00 (24 points).
            $hourPeriod = new \DatePeriod(
                $from->setTime(0, 0, 0),
                new \DateInterval('PT1H'),
                $to->setTime(23, 59, 59)->modify('+1 second')
            );

            foreach ($hourPeriod as $dt) {
                $key = $dt->format('Y-m-d H:00:00');
                $data = $indexed[$key] ?? ['revenue' => 0.0, 'orders' => 0, 'customers' => 0];
                $chart[] = [
                    'label' => $key,
                    'turnover' => (float) $data['revenue'],
                    'orders' => (int) $data['orders'],
                    'customers' => (int) $data['customers'],
                    'new_customers' => $newCustomersIndex[$key] ?? 0,
                ];
            }

            return $chart;
        }

        if ($granularity === 'month') {
            // Granularité mensuelle (preset `year`, cf. resolveGranularity()) : 1 point par mois
            // civil, du mois de $from jusqu'au mois de $to inclus (12 points max sur une année).
            $query = new DbQuery();
            $query->select('DATE_FORMAT(o.date_add, \'%Y-%m-01\') AS month');
            $query->select('SUM(o.total_paid_tax_incl - o.total_shipping_tax_incl) AS revenue');
            $query->select('COUNT(*) AS orders');
            $query->select('COUNT(DISTINCT o.id_customer) AS customers');
            $query->from('orders', 'o');
            $query->where('o.date_add BETWEEN "' . $fromSql . '" AND "' . $toSql . '"');
            if ($shopId > 0) {
                $query->where('o.id_shop = ' . $shopId);
            }
            $query->groupBy('month');
            $query->orderBy('month ASC');

            $rows = (array) Db::getInstance()->executeS($query);
            $indexed = [];
            foreach ($rows as $row) {
                /** @var array<string, mixed> $row */
                if (!isset($row['month'])) {
                    continue;
                }

                $month = (string) $row['month'];
                $indexed[$month] = [
                    'revenue' => isset($row['revenue']) ? (float) $row['revenue'] : 0.0,
                    'orders' => isset($row['orders']) ? (int) $row['orders'] : 0,
                    'customers' => isset($row['customers']) ? (int) $row['customers'] : 0,
                ];
            }

            $chart = [];
            $cursor = $from->setDate((int) $from->format('Y'), (int) $from->format('n'), 1)->setTime(0, 0, 0);
            $lastMonth = $to->setDate((int) $to->format('Y'), (int) $to->format('n'), 1)->setTime(0, 0, 0);

            while ($cursor <= $lastMonth) {
                $key = $cursor->format('Y-m-01');
                $data = $indexed[$key] ?? ['revenue' => 0.0, 'orders' => 0, 'customers' => 0];
                $chart[] = [
                    'label' => $key,
                    'turnover' => (float) $data['revenue'],
                    'orders' => (int) $data['orders'],
                    'customers' => (int) $data['customers'],
                    'new_customers' => $newCustomersIndex[$key] ?? 0,
                ];
                $cursor = $cursor->modify('+1 month');
            }

            return $chart;
        }

        // Granularité journalière (toutes les autres périodes).
        $query = new DbQuery();
        $query->select('DATE(o.date_add) AS day');
        // CA hors frais de port (cf. getMetrics()) : même correction sur la série journalière.
        $query->select('SUM(o.total_paid_tax_incl - o.total_shipping_tax_incl) AS revenue');
        $query->select('COUNT(*) AS orders');
        $query->select('COUNT(DISTINCT o.id_customer) AS customers');
        $query->from('orders', 'o');
        $query->where('o.date_add BETWEEN "' . $fromSql . '" AND "' . $toSql . '"');
        if ($shopId > 0) {
            $query->where('o.id_shop = ' . $shopId);
        }
        $query->groupBy('day');
        $query->orderBy('day ASC');

        $rows = (array) Db::getInstance()->executeS($query);
        $indexed = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            if (!isset($row['day'])) {
                continue;
            }

            $day = (string) $row['day'];
            $indexed[$day] = [
                'revenue' => isset($row['revenue']) ? (float) $row['revenue'] : 0.0,
                'orders' => isset($row['orders']) ? (int) $row['orders'] : 0,
                'customers' => isset($row['customers']) ? (int) $row['customers'] : 0,
            ];
        }

        $chart = [];
        $datePeriod = new \DatePeriod(
            $from->setTime(0, 0, 0),
            new \DateInterval('P1D'),
            $to->setTime(23, 59, 59)->modify('+1 second')
        );

        foreach ($datePeriod as $date) {
            $key = $date->format('Y-m-d');
            $data = $indexed[$key] ?? ['revenue' => 0.0, 'orders' => 0, 'customers' => 0];
            $chart[] = [
                'label' => $key,
                'turnover' => (float) $data['revenue'],
                'orders' => (int) $data['orders'],
                'customers' => (int) $data['customers'],
                'new_customers' => $newCustomersIndex[$key] ?? 0,
            ];
        }

        return $chart;
    }

    private function countActiveProducts(): int
    {
        $shopId = $this->getShopId();
        // Protection multiboutique : ne compter que les produits associés à la boutique courante
        // (product_shop), pas le catalogue entier partagé entre boutiques.
        $shopJoin = $shopId > 0
            ? ' INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $shopId
            : '';
        $result = Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'product p' . $shopJoin . ' WHERE p.active = 1'
        );

        return $result !== false ? (int) $result : 0;
    }

    private function resolveCurrencyIso(): ?string
    {
        $defaultCurrencyId = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        if ($defaultCurrencyId <= 0) {
            return null;
        }

        $currency = new Currency($defaultCurrencyId);
        return Validate::isLoadedObject($currency) ? (string) $currency->iso_code : null;
    }

    /**
     * Résout l'id_lang selon l'en-tête `Accept-Language` envoyé par l'app (fallback langue par
     * défaut boutique si absent/non installée/inactive) — voir LanguageResolver.
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

    private function resolveImgBaseUrl(): string
    {
        $domain = Tools::getShopDomainSsl(true);
        if (!is_string($domain) || $domain === '') {
            return '';
        }

        $domain = rtrim($domain, '/');
        $baseUri = defined('__PS_BASE_URI__') ? trim(constant('__PS_BASE_URI__'), '/') : '';

        return $baseUri !== '' ? $domain . '/' . $baseUri : $domain;
    }
}
