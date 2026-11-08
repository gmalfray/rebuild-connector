<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class DashboardServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Db::$testLoggedSelectQueries = [];
        DbQuery::$testSelectLog = [];
    }

    protected function tearDown(): void
    {
        Db::$testLoggedSelectQueries = [];
        Db::$testGetValueResult = 0;
        Db::$testGetValueMap = [];
        Db::$testExecuteSResult = [];
        DbQuery::$testSelectLog = [];
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Protection IDOR multiboutique (m1) : les requêtes brutes (concaténées, pas DbQuery) lisant
    // `orders`/`cart`/`product` ne doivent porter QUE sur la boutique courante (shop id 1, stub
    // Shop) — sinon un client authentifié sur une boutique verrait le CA/les commandes d'une autre.
    // Utilise le vrai DashboardService (pas StubDashboardService) pour exercer le SQL réel, capturé
    // par le journal de test Db::$testLoggedSelectQueries (cf. phpstan-bootstrap.php).
    // -----------------------------------------------------------------------

    public function testGetMetricsOrdersAggregateQueriesFilterByCurrentShopId(): void
    {
        $service = new DashboardService();
        $service->getMetrics('today');

        // ordersCount / revenueTaxIncl / revenueTaxExcl / customersCount / previousTurnover :
        // toutes lisent `orders` par concaténation directe (pas de DbQuery ici).
        $ordersQueries = array_values(array_filter(
            Db::$testLoggedSelectQueries,
            static function (string $sql): bool {
                return strpos($sql, _DB_PREFIX_ . 'orders') !== false;
            }
        ));

        $this->assertNotEmpty($ordersQueries, 'Aucune requête `orders` capturée par getMetrics().');
        foreach ($ordersQueries as $sql) {
            $this->assertStringContainsString('id_shop = 1', $sql, 'Requête orders sans filtre id_shop : ' . $sql);
        }
    }

    public function testCountPendingOrdersFiltersByCurrentShopId(): void
    {
        $service = new DashboardService();
        $method = new \ReflectionMethod(DashboardService::class, 'countPendingOrders');
        $method->setAccessible(true);
        $method->invoke($service);

        $this->assertNotEmpty(Db::$testLoggedSelectQueries);
        $this->assertStringContainsString('o.id_shop = 1', Db::$testLoggedSelectQueries[0]);
    }

    public function testComputeConversionRateCartQueryFiltersByCurrentShopId(): void
    {
        $service = new DashboardService();
        $method = new \ReflectionMethod(DashboardService::class, 'computeConversionRate');
        $method->setAccessible(true);
        $method->invoke($service, 5, '2025-01-01 00:00:00', '2025-01-31 23:59:59');

        $cartQueries = array_values(array_filter(
            Db::$testLoggedSelectQueries,
            static function (string $sql): bool {
                return strpos($sql, _DB_PREFIX_ . 'cart') !== false;
            }
        ));

        $this->assertNotEmpty($cartQueries, 'Aucune requête `cart` capturée par computeConversionRate().');
        $this->assertStringContainsString('id_shop = 1', $cartQueries[0]);
    }

    public function testCountActiveProductsFiltersByCurrentShopId(): void
    {
        $service = new DashboardService();
        $method = new \ReflectionMethod(DashboardService::class, 'countActiveProducts');
        $method->setAccessible(true);
        $method->invoke($service);

        $this->assertNotEmpty(Db::$testLoggedSelectQueries);
        $this->assertStringContainsString('ps.id_shop = 1', Db::$testLoggedSelectQueries[0]);
    }

    // -----------------------------------------------------------------------
    // Bug client : le CA dashboard incluait les frais de port (total_paid_tax_incl).
    // Le CA doit être le revenu produits uniquement (montant payé - frais de port).
    //
    // Note méthodologique : le stub Db::getValue() ne fait qu'écho d'une valeur de test fixe
    // (il ne peut pas exécuter l'arithmétique SQL réelle SUM(a - b)). On verrouille donc le
    // comportement au niveau du texte SQL envoyé à la base (comme le fait déjà le test IDOR
    // multiboutique ci-dessus pour la clause id_shop) : la requête doit bien retrancher
    // total_shipping_tax_incl / total_shipping_tax_excl, pas seulement lire total_paid_*.
    // -----------------------------------------------------------------------

    public function testTurnoverQueryExcludesShippingTaxIncl(): void
    {
        $service = new DashboardService();
        $service->getMetrics('today');

        $revenueQueries = array_values(array_filter(
            Db::$testLoggedSelectQueries,
            static function (string $sql): bool {
                return strpos($sql, 'SUM(total_paid_tax_incl') !== false;
            }
        ));

        $this->assertNotEmpty($revenueQueries, 'Aucune requête de CA (total_paid_tax_incl) capturée.');
        foreach ($revenueQueries as $sql) {
            $this->assertStringContainsString(
                'SUM(total_paid_tax_incl - total_shipping_tax_incl)',
                $sql,
                'Le CA TTC doit exclure les frais de port : ' . $sql
            );
        }
        // turnover ET previous_turnover doivent tous les deux être corrigés.
        $this->assertGreaterThanOrEqual(2, count($revenueQueries), 'turnover et previous_turnover doivent tous deux exclure le port.');
    }

    public function testRevenueTaxExclQueryExcludesShippingTaxExcl(): void
    {
        $service = new DashboardService();
        $service->getMetrics('today');

        $revenueExclQueries = array_values(array_filter(
            Db::$testLoggedSelectQueries,
            static function (string $sql): bool {
                return strpos($sql, 'SUM(total_paid_tax_excl') !== false;
            }
        ));

        $this->assertNotEmpty($revenueExclQueries, 'Aucune requête de CA HT (total_paid_tax_excl) capturée.');
        foreach ($revenueExclQueries as $sql) {
            $this->assertStringContainsString(
                'SUM(total_paid_tax_excl - total_shipping_tax_excl)',
                $sql,
                'Le CA HT doit exclure les frais de port : ' . $sql
            );
        }
    }

    public function testChartRevenueQueryExcludesShippingDailyGranularity(): void
    {
        $service = new DashboardService();
        // 'month' → granularité journalière (cf. resolveGranularity()).
        $service->getMetrics('month');

        $revenueSelects = array_values(array_filter(
            DbQuery::$testSelectLog,
            static function (string $fields): bool {
                return strpos($fields, 'AS revenue') !== false;
            }
        ));

        $this->assertNotEmpty($revenueSelects, 'Aucun SELECT ... AS revenue capturé pour le chart journalier.');
        foreach ($revenueSelects as $fields) {
            $this->assertStringContainsString(
                'SUM(o.total_paid_tax_incl - o.total_shipping_tax_incl) AS revenue',
                $fields,
                'La série CA du graphique (granularité jour) doit exclure le port : ' . $fields
            );
        }
    }

    public function testChartRevenueQueryExcludesShippingHourlyGranularity(): void
    {
        $service = new DashboardService();
        // 'today' → granularité horaire (cf. resolveGranularity()).
        $service->getMetrics('today');

        $revenueSelects = array_values(array_filter(
            DbQuery::$testSelectLog,
            static function (string $fields): bool {
                return strpos($fields, 'AS revenue') !== false;
            }
        ));

        $this->assertNotEmpty($revenueSelects, 'Aucun SELECT ... AS revenue capturé pour le chart horaire.');
        foreach ($revenueSelects as $fields) {
            $this->assertStringContainsString(
                'SUM(o.total_paid_tax_incl - o.total_shipping_tax_incl) AS revenue',
                $fields,
                'La série CA du graphique (granularité heure) doit exclure le port : ' . $fields
            );
        }
    }

    public function testAverageBasketDerivesFromShippingExcludedTurnover(): void
    {
        // Le panier moyen reste dérivé de `turnover` (déjà hors port), divisé par les commandes
        // PAYANTES (aucune gratuite ici).
        Db::$testGetValueResult = 30;
        Db::$testGetValueMap = ['total_paid_tax_incl = 0 AND' => 0];

        $service = new DashboardService();
        $metrics = $service->getMetrics('today');

        $this->assertSame($metrics['turnover'], $metrics['revenue']);
        $this->assertSame(0, $metrics['orders_free_count']);
        $this->assertEqualsWithDelta(
            $metrics['turnover'] / $metrics['orders_count'],
            $metrics['average_basket'],
            0.0001
        );
    }

    public function testAverageBasketUsesPaidOrdersOnly(): void
    {
        Db::$testGetValueResult = 100;
        Db::$testGetValueMap = ['total_paid_tax_incl = 0 AND' => 20];

        $metrics = (new DashboardService())->getMetrics('today');

        $this->assertSame(100, $metrics['orders_count']);
        $this->assertSame(20, $metrics['orders_free_count']);
        // numérateur 100.0 (stub), dénominateur 100 - 20 = 80
        $this->assertEqualsWithDelta(100.0 / 80, $metrics['average_basket'], 0.0001);
        $this->assertSame($metrics['average_basket'], $metrics['average_order_value']);
    }

    public function testAverageBasketIsZeroWhenAllOrdersAreFree(): void
    {
        Db::$testGetValueResult = 10;
        Db::$testGetValueMap = ['total_paid_tax_incl = 0 AND' => 10];

        $metrics = (new DashboardService())->getMetrics('today');

        $this->assertSame(10, $metrics['orders_count']);
        $this->assertSame(0.0, $metrics['average_basket']);
    }

    public function testChartPointsExposeFreeOrdersAndPreviousTurnover(): void
    {
        Db::$testExecuteSResult = [];
        $metrics = (new DashboardService())->getMetrics('month');

        $this->assertNotEmpty($metrics['chart']);
        foreach ($metrics['chart'] as $point) {
            $this->assertArrayHasKey('orders_free', $point);
            $this->assertArrayHasKey('turnover_previous', $point);
            $this->assertIsInt($point['orders_free']);
            $this->assertIsFloat($point['turnover_previous']);
        }
    }

    public function testChartPreviousTurnoverAlignsPositionally(): void
    {
        $service = new DashboardService();
        $method = new \ReflectionMethod(DashboardService::class, 'buildChart');
        $method->setAccessible(true);

        // Le stub renvoie les mêmes lignes pour les deux plages : on les indexe sur des clés
        // différentes en pilotant les deux appels via des lignes couvrant les deux années.
        Db::$testExecuteSResult = [
            ['bucket' => '2025-03-01', 'revenue' => '10', 'orders' => '2', 'orders_free' => '1', 'customers' => '2'],
            ['bucket' => '2024-03-01', 'revenue' => '7.5', 'orders' => '1', 'orders_free' => '0', 'customers' => '1'],
        ];
        $from = new \DateTimeImmutable('2025-03-01 00:00:00');
        $to = new \DateTimeImmutable('2025-03-03 23:59:59');
        $prevFrom = new \DateTimeImmutable('2024-03-01 00:00:00');
        $prevTo = new \DateTimeImmutable('2024-03-02 23:59:59'); // N-1 plus court

        /** @var array<int, array<string, mixed>> $chart */
        $chart = $method->invoke($service, $from, $to, 'custom', true, $prevFrom, $prevTo);

        $this->assertCount(3, $chart);
        $this->assertSame('2025-03-01', $chart[0]['label']);
        $this->assertSame(10.0, $chart[0]['turnover']);
        $this->assertSame(1, $chart[0]['orders_free']);
        $this->assertSame(2, $chart[0]['orders']);
        $this->assertSame(7.5, $chart[0]['turnover_previous']);
        $this->assertSame(0.0, $chart[1]['turnover_previous']);
        $this->assertSame(0.0, $chart[2]['turnover_previous'], 'N-1 plus court : complété par 0.');
    }

    public function testFreeConversionComputesRate(): void
    {
        Db::$testGetValueMap = [
            'f.first_total = 0 AND EXISTS' => 3,
            'f.first_total = 0' => 12,
            'f.first_total > 0' => 40,
        ];

        $result = (new DashboardService())->getFreeConversion();

        $this->assertSame(12, $result['free_first_customers']);
        $this->assertSame(3, $result['converted']);
        $this->assertSame(40, $result['paid_first_customers']);
        $this->assertSame(25.0, $result['rate']);
    }

    public function testFreeConversionRateIsZeroWithoutFreeFirstCustomers(): void
    {
        Db::$testGetValueResult = 0;

        $result = (new DashboardService())->getFreeConversion();

        $this->assertSame(0, $result['free_first_customers']);
        $this->assertSame(0.0, $result['rate']);
    }

    public function testFreeConversionFiltersShopAndWindow(): void
    {
        $from = new \DateTimeImmutable('2025-01-01 00:00:00');
        $to = new \DateTimeImmutable('2025-12-31 23:59:59');
        (new DashboardService())->getFreeConversion($from, $to);

        $this->assertNotEmpty(Db::$testLoggedSelectQueries);
        foreach (Db::$testLoggedSelectQueries as $sql) {
            $this->assertStringContainsString('id_shop = 1', $sql);
            $this->assertStringContainsString('f.first_date >= "2025-01-01 00:00:00"', $sql);
            $this->assertStringContainsString('f.first_date <= "2025-12-31 23:59:59"', $sql);
        }
        $this->assertStringContainsString('op.total_paid_tax_incl > 0', Db::$testLoggedSelectQueries[2]);
        $this->assertStringContainsString('op.date_add > f.first_date', Db::$testLoggedSelectQueries[2]);
    }

    // -----------------------------------------------------------------------
    // Métriques de base (données retournées par le service)
    // -----------------------------------------------------------------------

    public function testGetMetricsReturnsExpectedKeys(): void
    {
        $service = new StubDashboardService();
        $metrics = $service->getMetrics('month');

        // Clés historiques
        $this->assertArrayHasKey('period', $metrics);
        $this->assertArrayHasKey('turnover', $metrics);
        $this->assertArrayHasKey('orders_count', $metrics);
        $this->assertArrayHasKey('customers_count', $metrics);
        $this->assertArrayHasKey('products_count', $metrics);
        $this->assertArrayHasKey('revenue_tax_incl', $metrics);
        $this->assertArrayHasKey('revenue_tax_excl', $metrics);
        $this->assertArrayHasKey('tax_collected', $metrics);
        $this->assertArrayHasKey('average_basket', $metrics);
        $this->assertArrayHasKey('currency', $metrics);
        $this->assertArrayHasKey('chart', $metrics);

        // Nouvelles clés
        $this->assertArrayHasKey('pending_orders_count', $metrics);
        $this->assertArrayHasKey('conversion_rate', $metrics);
        $this->assertArrayHasKey('low_stock_alerts', $metrics);
    }

    public function testConversionRateStructure(): void
    {
        $service = new StubDashboardService();
        $metrics = $service->getMetrics('month');

        $cr = $metrics['conversion_rate'];
        $this->assertIsArray($cr);
        $this->assertArrayHasKey('rate', $cr);
        $this->assertArrayHasKey('orders', $cr);
        $this->assertArrayHasKey('sessions_proxy', $cr);
        $this->assertArrayHasKey('note', $cr);
    }

    public function testConversionRateIsNullWhenNoCart(): void
    {
        // Quand pas de panier, rate doit être null
        // PHP 7.4 minimum (pas d'arguments nommés) : positionnel = ordersCount, cartsCount
        $service = new StubDashboardService(5, 0);
        $metrics = $service->getMetrics('month');

        $this->assertNull($metrics['conversion_rate']['rate']);
        $this->assertSame(5, $metrics['conversion_rate']['orders']);
        $this->assertSame(0, $metrics['conversion_rate']['sessions_proxy']);
    }

    public function testConversionRateComputedCorrectly(): void
    {
        // 10 commandes / 100 paniers = 10 %
        // PHP 7.4 minimum (pas d'arguments nommés) : positionnel = ordersCount, cartsCount
        $service = new StubDashboardService(10, 100);
        $metrics = $service->getMetrics('month');

        $this->assertSame(10.0, $metrics['conversion_rate']['rate']);
    }

    public function testPendingOrdersCountIsInteger(): void
    {
        // PHP 7.4 minimum (pas d'arguments nommés) : positionnel = ordersCount, cartsCount, pendingCount
        $service = new StubDashboardService(0, 0, 7);
        $metrics = $service->getMetrics('month');

        $this->assertSame(7, $metrics['pending_orders_count']);
    }

    public function testLowStockAlertsIsArray(): void
    {
        $alerts = [
            ['product_id' => 42, 'name' => 'Patron robe', 'quantity' => 2, 'image_url' => null],
        ];
        // PHP 7.4 minimum (pas d'arguments nommés) : positionnel = ordersCount, cartsCount, pendingCount, lowStockProducts
        $service = new StubDashboardService(0, 0, 0, $alerts);
        $metrics = $service->getMetrics('month');

        $this->assertIsArray($metrics['low_stock_alerts']);
        $this->assertCount(1, $metrics['low_stock_alerts']);
        $this->assertSame(42, $metrics['low_stock_alerts'][0]['product_id']);
        $this->assertSame(2, $metrics['low_stock_alerts'][0]['quantity']);
    }

    public function testLowStockAlertEntryHasRequiredKeys(): void
    {
        $alerts = [
            ['product_id' => 1, 'name' => 'Produit test', 'quantity' => 3, 'image_url' => 'https://example.com/img/1.jpg'],
        ];
        // PHP 7.4 minimum (pas d'arguments nommés) : positionnel = ordersCount, cartsCount, pendingCount, lowStockProducts
        $service = new StubDashboardService(0, 0, 0, $alerts);
        $metrics = $service->getMetrics('month');

        $entry = $metrics['low_stock_alerts'][0];
        $this->assertArrayHasKey('product_id', $entry);
        $this->assertArrayHasKey('name', $entry);
        $this->assertArrayHasKey('quantity', $entry);
        $this->assertArrayHasKey('image_url', $entry);
    }

    // -----------------------------------------------------------------------
    // Nouveau champ new_customers dans chart[]
    // -----------------------------------------------------------------------

    public function testChartPointsHaveNewCustomersKey(): void
    {
        $service = new StubDashboardService();
        $metrics = $service->getMetrics('month');

        $this->assertIsArray($metrics['chart']);
        // Le stub Db renvoie [] pour executeS → 30 points vides générés par DatePeriod.
        // Chaque point doit avoir la clé new_customers.
        foreach ($metrics['chart'] as $point) {
            $this->assertArrayHasKey('new_customers', $point, 'Champ new_customers manquant dans un point du chart.');
            $this->assertIsInt($point['new_customers']);
        }
    }

    public function testChartTodayPointsHaveNewCustomersKey(): void
    {
        $service = new StubDashboardService();
        $metrics = $service->getMetrics('today');

        $this->assertIsArray($metrics['chart']);
        foreach ($metrics['chart'] as $point) {
            $this->assertArrayHasKey('new_customers', $point, 'Champ new_customers manquant dans un point horaire.');
        }
    }

    public function testChartPointsNewCustomersDefaultsToZero(): void
    {
        $service = new StubDashboardService();
        $metrics = $service->getMetrics('week');

        foreach ($metrics['chart'] as $point) {
            // Avec le stub Db (executeS → []), tous les new_customers doivent valoir 0.
            $this->assertSame(0, $point['new_customers']);
        }
    }

    // -----------------------------------------------------------------------
    // Mode plage libre (from / to)
    // -----------------------------------------------------------------------

    public function testCustomRangeUsesProvidedDates(): void
    {
        $from = new \DateTimeImmutable('2025-01-01 00:00:00');
        $to = new \DateTimeImmutable('2025-01-31 23:59:59');
        $service = new StubDashboardService();
        $metrics = $service->getMetrics('custom', DashboardService::LOW_STOCK_THRESHOLD, $from, $to);

        $this->assertSame('custom', $metrics['period']['label']);
        $this->assertStringStartsWith('2025-01-01', $metrics['period']['from']);
        $this->assertStringStartsWith('2025-01-31', $metrics['period']['to']);
    }

    public function testCustomRangeChartHasNewCustomers(): void
    {
        $from = new \DateTimeImmutable('2025-06-01 00:00:00');
        $to = new \DateTimeImmutable('2025-06-07 23:59:59');
        $service = new StubDashboardService();
        $metrics = $service->getMetrics('custom', DashboardService::LOW_STOCK_THRESHOLD, $from, $to);

        $this->assertIsArray($metrics['chart']);
        $this->assertNotEmpty($metrics['chart']);
        foreach ($metrics['chart'] as $point) {
            $this->assertArrayHasKey('new_customers', $point);
        }
    }

    public function testCustomRangeSingleDayIsHourly(): void
    {
        // Plage d'un seul jour → granularité horaire (24 points).
        $from = new \DateTimeImmutable('2025-06-15 00:00:00');
        $to = new \DateTimeImmutable('2025-06-15 23:59:59');
        $service = new StubDashboardService();
        $metrics = $service->getMetrics('custom', DashboardService::LOW_STOCK_THRESHOLD, $from, $to);

        $this->assertCount(24, $metrics['chart'], 'Une plage d\'un seul jour doit générer 24 points horaires.');
    }

    public function testCustomRangeMultiDayIsDaily(): void
    {
        // Régression : la borne de fin du DatePeriod journalier était `->modify('+1 day')`
        // (borne exclusive → 8e point parasite le lendemain de $to). Corrigé en `->modify('+1 second')`
        // dans DashboardService::buildChart(), comme la période horaire.
        // Plage de 7 jours → granularité journalière (7 points).
        $from = new \DateTimeImmutable('2025-06-01 00:00:00');
        $to = new \DateTimeImmutable('2025-06-07 23:59:59');
        $service = new StubDashboardService();
        $metrics = $service->getMetrics('custom', DashboardService::LOW_STOCK_THRESHOLD, $from, $to);

        $this->assertCount(7, $metrics['chart'], 'Une plage de 7 jours doit générer 7 points journaliers.');
    }

    // -----------------------------------------------------------------------
    // Période (rétrocompat)
    // -----------------------------------------------------------------------

    public function testPeriodLabelIsPreserved(): void
    {
        foreach (['day', 'week', 'month', 'year'] as $period) {
            $service = new StubDashboardService();
            $metrics = $service->getMetrics($period);
            $this->assertSame($period, $metrics['period']['label'], "Période '$period' incorrecte.");
        }
    }

    public function testExistingChartFieldsArePreserved(): void
    {
        // Vérifie que les champs historiques du chart ne sont pas cassés par l'ajout de new_customers.
        $service = new StubDashboardService();
        $metrics = $service->getMetrics('week');

        foreach ($metrics['chart'] as $point) {
            $this->assertArrayHasKey('label', $point);
            $this->assertArrayHasKey('turnover', $point);
            $this->assertArrayHasKey('orders', $point);
            $this->assertArrayHasKey('customers', $point);
            $this->assertArrayHasKey('new_customers', $point);
        }
    }
}

// ---------------------------------------------------------------------------
// Stub testable de DashboardService
// ---------------------------------------------------------------------------

final class StubDashboardService extends DashboardService
{
    private int $ordersCount;
    private int $cartsCount;
    private int $pendingCount;
    /** @var array<int, array<string, mixed>> */
    private array $lowStockProducts;

    /**
     * @param array<int, array<string, mixed>> $lowStockProducts
     */
    public function __construct(
        int $ordersCount = 0,
        int $cartsCount = 0,
        int $pendingCount = 0,
        array $lowStockProducts = []
    ) {
        $this->ordersCount = $ordersCount;
        $this->cartsCount = $cartsCount;
        $this->pendingCount = $pendingCount;
        $this->lowStockProducts = $lowStockProducts;
    }

    protected function countPendingOrders(): int
    {
        return $this->pendingCount;
    }

    /**
     * @return array{rate: float|null, orders: int, sessions_proxy: int, note: string}
     */
    protected function computeConversionRate(int $ordersCount, string $fromSql, string $toSql): array
    {
        // Surcharge pour injecter cartsCount sans BDD.
        $rate = null;
        if ($this->cartsCount > 0) {
            $rate = round(($this->ordersCount / $this->cartsCount) * 100, 2);
        }

        return [
            'rate' => $rate,
            'orders' => $this->ordersCount,
            'sessions_proxy' => $this->cartsCount,
            'note' => 'stub',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getLowStockProducts(int $threshold = 5): array
    {
        return $this->lowStockProducts;
    }
}
