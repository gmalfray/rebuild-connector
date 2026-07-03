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
    }

    protected function tearDown(): void
    {
        Db::$testLoggedSelectQueries = [];
        Db::$testGetValueResult = 0;
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
