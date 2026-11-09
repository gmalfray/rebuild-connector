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

        $ordersFreeCount = (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'orders WHERE total_paid_tax_incl = 0 AND date_add BETWEEN "'
            . $fromSql . '" AND "' . $toSql . '"' . $shopFilterSql
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
        // Piège : l'alias `orr` est obligatoire, sans lui la table s'appelle `<prefixe>order_return`
        // et tout qualificatif `order_return.` casse la requête (1054 Unknown column).
        $returnsShopJoin = $shopId > 0
            ? ' INNER JOIN ' . _DB_PREFIX_ . 'orders o ON o.id_order = orr.id_order AND o.id_shop = ' . $shopId
            : '';
        $returns = (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'order_return orr' . $returnsShopJoin
            . ' WHERE orr.date_add BETWEEN "' . $fromSql . '" AND "' . $toSql . '"'
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
            // de la période courante). Cf. resolveYoyComparisonRange().
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
        // Panier moyen calculé sur les commandes PAYANTES (total_paid_tax_incl > 0) ; orders_count
        // reste le total (gratuites incluses).
        $paidOrdersCount = max(0, $ordersCount - $ordersFreeCount);
        $averageBasket = $paidOrdersCount > 0 ? $revenueTaxIncl / $paidOrdersCount : 0.0;

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
            // pour previous_turnover. Optionnel : ne pas modifier la lecture existante de
            // previous_turnover, qui reste au même endroit avec la même sémantique (un nombre).
            'comparison_period' => [
                'from' => $prevFrom->format(DATE_ATOM),
                'to' => $prevTo->format(DATE_ATOM),
            ],
            'turnover' => $revenueTaxIncl,
            'previous_turnover' => $previousTurnover,
            'orders_count' => $ordersCount,
            'orders_free_count' => $ordersFreeCount,
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
            'chart' => $this->buildChart($from, $to, $period, $isCustomRange, $prevFrom, $prevTo),
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
     * (fuseau boutique) et court jusqu'à `now`. La période courante est donc potentiellement
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
     * Série du graphe. Chaque point : label, turnover, orders (total, gratuites incluses),
     * orders_free, customers, new_customers, turnover_previous (CA du bucket de même rang sur la
     * période de comparaison N-1, alignement positionnel ; 0.0 si N-1 a moins de buckets).
     *
     * @param bool $isCustomRange La plage provient-elle de from/to explicites ?
     * @param \DateTimeImmutable|null $prevFrom Début de la plage de comparaison (avec $prevTo)
     * @param \DateTimeImmutable|null $prevTo   Fin de la plage de comparaison
     * @return array<int, array<string, mixed>>
     */
    private function buildChart(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $period = 'month',
        bool $isCustomRange = false,
        ?\DateTimeImmutable $prevFrom = null,
        ?\DateTimeImmutable $prevTo = null
    ): array {
        $granularity = $this->resolveGranularity($from, $to, $period, $isCustomRange);

        $newCustomersIndex = $this->buildNewCustomersIndex($from, $to, $granularity);
        $indexed = $this->fetchChartBuckets($from, $to, $granularity);
        $keys = $this->listBucketKeys($from, $to, $granularity);

        $previousKeys = [];
        $previousIndexed = [];
        if ($prevFrom !== null && $prevTo !== null) {
            $previousKeys = $this->listBucketKeys($prevFrom, $prevTo, $granularity);
            $previousIndexed = $this->fetchChartBuckets($prevFrom, $prevTo, $granularity);
        }

        $empty = ['revenue' => 0.0, 'orders' => 0, 'orders_free' => 0, 'customers' => 0];
        $chart = [];
        foreach ($keys as $i => $key) {
            $data = $indexed[$key] ?? $empty;
            $previousKey = $previousKeys[$i] ?? null;
            $chart[] = [
                'label' => $key,
                'turnover' => (float) $data['revenue'],
                'orders' => (int) $data['orders'],
                'orders_free' => (int) $data['orders_free'],
                'customers' => (int) $data['customers'],
                'new_customers' => $newCustomersIndex[$key] ?? 0,
                'turnover_previous' => $previousKey !== null && isset($previousIndexed[$previousKey])
                    ? (float) $previousIndexed[$previousKey]['revenue']
                    : 0.0,
            ];
        }

        return $chart;
    }

    /**
     * Agrégats par bucket (CA hors port, commandes, commandes gratuites, clients) sur une plage.
     *
     * @param string $granularity 'hour', 'day' ou 'month'
     * @return array<string, array{revenue: float, orders: int, orders_free: int, customers: int}>
     */
    private function fetchChartBuckets(\DateTimeImmutable $from, \DateTimeImmutable $to, string $granularity): array
    {
        $fromSql = pSQL($from->format('Y-m-d H:i:s'));
        $toSql = pSQL($to->format('Y-m-d H:i:s'));
        $shopId = $this->getShopId();

        $query = new DbQuery();
        if ($granularity === 'hour') {
            $query->select('DATE_FORMAT(o.date_add, \'%Y-%m-%d %H:00:00\') AS bucket');
        } elseif ($granularity === 'month') {
            $query->select('DATE_FORMAT(o.date_add, \'%Y-%m-01\') AS bucket');
        } else {
            $query->select('DATE(o.date_add) AS bucket');
        }
        // CA hors frais de port (cf. getMetrics()).
        $query->select('SUM(o.total_paid_tax_incl - o.total_shipping_tax_incl) AS revenue');
        $query->select('COUNT(*) AS orders');
        $query->select('SUM(CASE WHEN o.total_paid_tax_incl = 0 THEN 1 ELSE 0 END) AS orders_free');
        $query->select('COUNT(DISTINCT o.id_customer) AS customers');
        $query->from('orders', 'o');
        $query->where('o.date_add BETWEEN "' . $fromSql . '" AND "' . $toSql . '"');
        if ($shopId > 0) {
            $query->where('o.id_shop = ' . $shopId);
        }
        $query->groupBy('bucket');
        $query->orderBy('bucket ASC');

        $indexed = [];
        foreach ((array) Db::getInstance()->executeS($query) as $row) {
            /** @var array<string, mixed> $row */
            if (!isset($row['bucket'])) {
                continue;
            }

            $indexed[(string) $row['bucket']] = [
                'revenue' => isset($row['revenue']) ? (float) $row['revenue'] : 0.0,
                'orders' => isset($row['orders']) ? (int) $row['orders'] : 0,
                'orders_free' => isset($row['orders_free']) ? (int) $row['orders_free'] : 0,
                'customers' => isset($row['customers']) ? (int) $row['customers'] : 0,
            ];
        }

        return $indexed;
    }

    /**
     * Clés ordonnées des buckets couvrant la plage (y compris sans activité).
     *
     * @return array<int, string>
     */
    private function listBucketKeys(\DateTimeImmutable $from, \DateTimeImmutable $to, string $granularity): array
    {
        $keys = [];
        if ($granularity === 'month') {
            $cursor = $from->setDate((int) $from->format('Y'), (int) $from->format('n'), 1)->setTime(0, 0, 0);
            $lastMonth = $to->setDate((int) $to->format('Y'), (int) $to->format('n'), 1)->setTime(0, 0, 0);
            while ($cursor <= $lastMonth) {
                $keys[] = $cursor->format('Y-m-01');
                $cursor = $cursor->modify('+1 month');
            }

            return $keys;
        }

        $isHourly = $granularity === 'hour';
        $datePeriod = new \DatePeriod(
            $from->setTime(0, 0, 0),
            new \DateInterval($isHourly ? 'PT1H' : 'P1D'),
            $to->setTime(23, 59, 59)->modify('+1 second')
        );
        foreach ($datePeriod as $dt) {
            $keys[] = $dt->format($isHourly ? 'Y-m-d H:00:00' : 'Y-m-d');
        }

        return $keys;
    }

    /**
     * Taux de conversion offert -> payant.
     *
     * Parmi les clients dont la TOUTE PREMIERE commande (MIN date_add) est gratuite
     * (total_paid_tax_incl = 0), part ayant passé ensuite au moins une commande payante
     * (total_paid_tax_incl > 0, date_add postérieure). La fenêtre from/to porte sur la date de la
     * première commande ; l'historique complet sert à qualifier la conversion.
     *
     * @return array{free_first_customers: int, paid_first_customers: int, converted: int, rate: float}
     */
    public function getFreeConversion(?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $db = Db::getInstance();
        $shopId = $this->getShopId();
        $shopSql = $shopId > 0 ? ' AND id_shop = ' . $shopId : '';
        $shopSqlO = $shopId > 0 ? ' AND o.id_shop = ' . $shopId : '';

        $windowSql = '';
        if ($from !== null) {
            $windowSql .= ' AND f.first_date >= "' . pSQL($from->format('Y-m-d H:i:s')) . '"';
        }
        if ($to !== null) {
            $windowSql .= ' AND f.first_date <= "' . pSQL($to->format('Y-m-d H:i:s')) . '"';
        }

        // Une ligne par client : date de 1ère commande, et total de cette 1ère commande (la plus
        // ancienne ; à date égale, l'id_order le plus petit).
        $firstOrders = 'SELECT o.id_customer, o.date_add AS first_date, o.total_paid_tax_incl AS first_total'
            . ' FROM ' . _DB_PREFIX_ . 'orders o'
            . ' WHERE o.id_order = ('
            . 'SELECT o2.id_order FROM ' . _DB_PREFIX_ . 'orders o2'
            . ' WHERE o2.id_customer = o.id_customer' . str_replace('id_shop', 'o2.id_shop', $shopSql)
            . ' ORDER BY o2.date_add ASC, o2.id_order ASC LIMIT 1)' . $shopSqlO;

        $base = ' FROM (' . $firstOrders . ') f WHERE 1' . $windowSql;

        $freeFirst = (int) $db->getValue('SELECT COUNT(*)' . $base . ' AND f.first_total = 0');
        $paidFirst = (int) $db->getValue('SELECT COUNT(*)' . $base . ' AND f.first_total > 0');
        $converted = (int) $db->getValue(
            'SELECT COUNT(*)' . $base . ' AND f.first_total = 0 AND EXISTS ('
            . 'SELECT 1 FROM ' . _DB_PREFIX_ . 'orders op'
            . ' WHERE op.id_customer = f.id_customer AND op.total_paid_tax_incl > 0'
            . ' AND op.date_add > f.first_date' . str_replace('id_shop', 'op.id_shop', $shopSql) . ')'
        );

        return [
            'free_first_customers' => $freeFirst,
            'paid_first_customers' => $paidFirst,
            'converted' => $converted,
            'rate' => $freeFirst > 0 ? round($converted / $freeFirst * 100, 1) : 0.0,
        ];
    }

    /**
     * Cohortes offert -> payant par mois de 1ère commande.
     *
     * Un client « offert à la 1re commande » est un client dont la plus ancienne commande valide
     * (o.valid = 1) est offerte (total_paid_tax_incl = 0). Son mois de cohorte est celui de cette
     * commande. Il est « converti à J+X » s'il a une commande valide payante (> 0) dont la date est
     * postérieure à la 1re commande offerte et au plus X jours après (X = 30, 60, 90 ; cumulatif).
     *
     * Une seule requête : agrégation par client (MIN conditionnels), puis par mois de cohorte.
     * Les mois sans client offert dans la fenêtre sont renvoyés à zéro.
     *
     * @return array{cohorts: array<int, array{month: string, first_free_customers: int, converted_30d: int, converted_60d: int, converted_90d: int}>, period: array{from: string, to: string}}
     */
    public function getFreeConversionCohorts(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $db = Db::getInstance();
        $shopId = $this->getShopId();
        $shopSql = $shopId > 0 ? ' AND o.id_shop = ' . $shopId : '';

        $perCustomer = 'SELECT o.id_customer,'
            . ' MIN(CASE WHEN o.total_paid_tax_incl = 0 THEN o.date_add END) AS first_free,'
            . ' MIN(CASE WHEN o.total_paid_tax_incl > 0 THEN o.date_add END) AS first_paid'
            . ' FROM ' . _DB_PREFIX_ . 'orders o'
            . ' WHERE o.valid = 1 AND o.id_customer > 0' . $shopSql
            . ' GROUP BY o.id_customer';

        $sql = 'SELECT DATE_FORMAT(c.first_free, "%Y-%m") AS month,'
            . ' COUNT(*) AS first_free_customers,'
            . ' SUM(c.first_paid IS NOT NULL AND c.first_paid <= DATE_ADD(c.first_free, INTERVAL 30 DAY)) AS converted_30d,'
            . ' SUM(c.first_paid IS NOT NULL AND c.first_paid <= DATE_ADD(c.first_free, INTERVAL 60 DAY)) AS converted_60d,'
            . ' SUM(c.first_paid IS NOT NULL AND c.first_paid <= DATE_ADD(c.first_free, INTERVAL 90 DAY)) AS converted_90d'
            . ' FROM (' . $perCustomer . ') c'
            . ' WHERE c.first_free IS NOT NULL AND (c.first_paid IS NULL OR c.first_free < c.first_paid)'
            . ' AND c.first_free >= "' . pSQL($from->format('Y-m-d H:i:s')) . '"'
            . ' AND c.first_free <= "' . pSQL($to->format('Y-m-d H:i:s')) . '"'
            . ' GROUP BY month ORDER BY month ASC';

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $db->executeS($sql) ?: [];

        $byMonth = [];
        foreach ($rows as $row) {
            $month = (string) ($row['month'] ?? '');
            if ($month === '') {
                continue;
            }
            $byMonth[$month] = [
                'month' => $month,
                'first_free_customers' => (int) ($row['first_free_customers'] ?? 0),
                'converted_30d' => (int) ($row['converted_30d'] ?? 0),
                'converted_60d' => (int) ($row['converted_60d'] ?? 0),
                'converted_90d' => (int) ($row['converted_90d'] ?? 0),
            ];
        }

        $cohorts = [];
        $cursor = $from->setDate((int) $from->format('Y'), (int) $from->format('n'), 1)->setTime(0, 0, 0);
        $last = $to->setDate((int) $to->format('Y'), (int) $to->format('n'), 1)->setTime(0, 0, 0);
        while ($cursor <= $last) {
            $key = $cursor->format('Y-m');
            $cohorts[] = $byMonth[$key] ?? [
                'month' => $key,
                'first_free_customers' => 0,
                'converted_30d' => 0,
                'converted_60d' => 0,
                'converted_90d' => 0,
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return [
            'cohorts' => $cohorts,
            'period' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
        ];
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
