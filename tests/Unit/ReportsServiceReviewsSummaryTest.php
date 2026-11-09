<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * @see ReportsService::getReviewsSummary()
 * @see RbReviewsBridge::getSummary()
 * @see SavService::getResponseStats()
 */
final class ReportsServiceReviewsSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStubs();
        Module::$testInstalledModules = ['rbreviews' => true];
        Module::$testEnabledModules = ['rbreviews' => true];
    }

    protected function tearDown(): void
    {
        $this->resetStubs();
        parent::tearDown();
    }

    private function resetStubs(): void
    {
        Db::$testExecuteSResult = [];
        Db::$testGetValueResult = 0;
        Db::$testGetValueMap = [];
        DbQuery::$testSelectLog = [];
        DbQuery::$testWhereLog = [];
    }

    /**
     * @param array{total_reviews: int, average_rating: float|null, pending_reviews: int} $summary
     */
    private function fakeBridge(bool $available, array $summary): ReviewsBridgeInterface
    {
        return new class ($available, $summary) implements ReviewsBridgeInterface {
            /** @var array<int, mixed> */
            public array $calls = [];

            private bool $available;

            /** @var array{total_reviews: int, average_rating: float|null, pending_reviews: int} */
            private array $summary;

            /** @param array{total_reviews: int, average_rating: float|null, pending_reviews: int} $summary */
            public function __construct(bool $available, array $summary)
            {
                $this->available = $available;
                $this->summary = $summary;
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function getSummary(int $idShop, ?string $dateFrom, ?string $dateTo): array
            {
                $this->calls[] = [$idShop, $dateFrom, $dateTo];

                return $this->summary;
            }

            public function getPendingReviews(int $limit, int $offset, int $idShop): array
            {
                throw new ReviewsUnavailableException();
            }

            public function publish(int $idReview, int $idShop): ?array
            {
                throw new ReviewsUnavailableException();
            }

            public function trash(int $idReview, int $idShop, string $reason): ?array
            {
                throw new ReviewsUnavailableException();
            }

            public function reply(int $idReview, int $idShop, string $reply): ?array
            {
                throw new ReviewsUnavailableException();
            }
        };
    }

    public function testStructureAndReviewRate(): void
    {
        Db::$testGetValueResult = 200; // vraies ventes (et fils ouverts)
        $bridge = $this->fakeBridge(true, ['total_reviews' => 50, 'average_rating' => 4.62, 'pending_reviews' => 3]);

        $result = (new ReportsService())->getReviewsSummary($bridge);

        $this->assertSame(50, $result['total_reviews']);
        $this->assertSame(4.62, $result['average_rating']);
        $this->assertSame(3, $result['pending_reviews']);
        $this->assertSame(200, $result['orders']);
        $this->assertSame(25.0, $result['review_rate']);
        $this->assertSame(['from' => null, 'to' => null], $result['period']);
        $this->assertArrayHasKey('sav', $result);
    }

    public function testReviewRateIsNullWithoutOrders(): void
    {
        Db::$testGetValueResult = 0;
        $bridge = $this->fakeBridge(true, ['total_reviews' => 5, 'average_rating' => null, 'pending_reviews' => 0]);

        $result = (new ReportsService())->getReviewsSummary($bridge);

        $this->assertNull($result['review_rate']);
        $this->assertNull($result['average_rating']);
    }

    public function testUnavailableModuleThrowsBeforeAnyQuery(): void
    {
        $bridge = $this->fakeBridge(false, ['total_reviews' => 1, 'average_rating' => 5.0, 'pending_reviews' => 0]);

        try {
            (new ReportsService())->getReviewsSummary($bridge);
            $this->fail('ReviewsUnavailableException attendue.');
        } catch (ReviewsUnavailableException $e) {
            $this->assertSame([], DbQuery::$testWhereLog, 'Aucune requête ne doit partir.');
        }
    }

    public function testNullBridgeSignalsUnavailability(): void
    {
        $this->expectException(ReviewsUnavailableException::class);
        (new ReportsService())->getReviewsSummary(new NullReviewsBridge());
    }

    public function testDateBoundsAreNormalisedAndPassedToBridgeAndOrders(): void
    {
        $bridge = $this->fakeBridge(true, ['total_reviews' => 0, 'average_rating' => null, 'pending_reviews' => 0]);

        $result = (new ReportsService())->getReviewsSummary($bridge, ['date_from' => '2026-01-01', 'date_to' => '2026-03-31']);

        $this->assertSame(['from' => '2026-01-01 00:00:00', 'to' => '2026-03-31 23:59:59'], $result['period']);
        $this->assertSame('2026-01-01 00:00:00', $bridge->calls[0][1]); // @phpstan-ignore-line
        $this->assertSame('2026-03-31 23:59:59', $bridge->calls[0][2]); // @phpstan-ignore-line
        $this->assertContains('o.date_add >= "2026-01-01 00:00:00"', DbQuery::$testWhereLog);
        $this->assertContains('o.date_add <= "2026-03-31 23:59:59"', DbQuery::$testWhereLog);
    }

    public function testOrdersDenominatorUsesRealSalesPivotAndShop(): void
    {
        $bridge = $this->fakeBridge(true, ['total_reviews' => 0, 'average_rating' => null, 'pending_reviews' => 0]);
        (new ReportsService())->getReviewsSummary($bridge);

        $this->assertContains('o.valid = 1', DbQuery::$testWhereLog);
        $this->assertContains('o.total_paid_tax_incl > 0', DbQuery::$testWhereLog);
        $shop = array_filter(DbQuery::$testWhereLog, static fn (string $w): bool => strpos($w, 'o.id_shop = ') === 0);
        $this->assertNotEmpty($shop);
    }

    public function testInvalidOrInconsistentDatesThrow(): void
    {
        $bridge = $this->fakeBridge(true, ['total_reviews' => 0, 'average_rating' => null, 'pending_reviews' => 0]);

        try {
            (new ReportsService())->getReviewsSummary($bridge, ['date_from' => 'not-a-date']);
            $this->fail('InvalidArgumentException attendue.');
        } catch (\InvalidArgumentException $e) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(\InvalidArgumentException::class);
        (new ReportsService())->getReviewsSummary($bridge, ['date_from' => '2026-05-01', 'date_to' => '2026-04-01']);
    }

    public function testRbBridgeSummaryFiltersPublishedShopAndPeriod(): void
    {
        Db::$testExecuteSResult = [['total_reviews' => '7', 'average_rating' => '4.285714']];
        Db::$testGetValueResult = 2;

        $summary = (new RbReviewsBridge())->getSummary(1, '2026-01-01 00:00:00', '2026-12-31 23:59:59');

        $this->assertSame(7, $summary['total_reviews']);
        $this->assertSame(4.29, $summary['average_rating']);
        $this->assertSame(2, $summary['pending_reviews']);
        $this->assertContains('r.validated = 1 AND r.deleted = 0', DbQuery::$testWhereLog);
        $this->assertContains('r.validated = 0 AND r.deleted = 0', DbQuery::$testWhereLog);
        $this->assertContains('r.id_shop = 1', DbQuery::$testWhereLog);
        $this->assertContains('r.date_add >= "2026-01-01 00:00:00"', DbQuery::$testWhereLog);
    }

    public function testRbBridgeSummaryAverageIsNullWithoutReviews(): void
    {
        Db::$testExecuteSResult = [['total_reviews' => '0', 'average_rating' => null]];

        $summary = (new RbReviewsBridge())->getSummary(1, null, null);

        $this->assertSame(0, $summary['total_reviews']);
        $this->assertNull($summary['average_rating']);
    }

    public function testRbBridgeSummaryThrowsWhenModuleDisabled(): void
    {
        Module::$testEnabledModules = ['rbreviews' => false];

        $this->expectException(ReviewsUnavailableException::class);
        (new RbReviewsBridge())->getSummary(1, null, null);
    }

    public function testMedianOddEvenAndEmpty(): void
    {
        $this->assertNull(SavService::median([]));
        $this->assertSame(3.0, SavService::median([9, 1, 3]));
        $this->assertSame(2.5, SavService::median([4, 1, 2, 3]));
        $this->assertSame(1.3, SavService::median([1.25])); // arrondi à 1 décimale
    }

    public function testSavResponseStatsComputesMedianAndSkipsInvalidThreads(): void
    {
        Db::$testGetValueResult = 6; // fils ouverts
        Db::$testExecuteSResult = [
            // 2 h
            ['id_customer_thread' => 1, 'first_customer_at' => '2026-01-01 10:00:00', 'first_employee_at' => '2026-01-01 12:00:00'],
            // 24 h
            ['id_customer_thread' => 2, 'first_customer_at' => '2026-01-02 10:00:00', 'first_employee_at' => '2026-01-03 10:00:00'],
            // 6 h
            ['id_customer_thread' => 3, 'first_customer_at' => '2026-01-04 10:00:00', 'first_employee_at' => '2026-01-04 16:00:00'],
            // fil ouvert par le marchand (employé avant cliente) : exclu
            ['id_customer_thread' => 4, 'first_customer_at' => '2026-01-05 10:00:00', 'first_employee_at' => '2026-01-05 08:00:00'],
            // ligne incomplète : exclue
            ['id_customer_thread' => 5, 'first_customer_at' => '2026-01-06 10:00:00', 'first_employee_at' => null],
        ];

        $stats = (new SavService())->getResponseStats('2026-01-01 00:00:00', null);

        $this->assertSame(6, $stats['open_threads']);
        $this->assertSame(3, $stats['answered_threads']);
        $this->assertSame(6.0, $stats['median_first_response_hours']);
        $this->assertContains('ct.status <> "closed"', DbQuery::$testWhereLog);
        $this->assertContains('ct.date_add >= "2026-01-01 00:00:00"', DbQuery::$testWhereLog);
    }

    public function testSavMedianIsNullWithoutSample(): void
    {
        $stats = (new SavService())->getResponseStats();

        $this->assertNull($stats['median_first_response_hours']);
        $this->assertSame(0, $stats['answered_threads']);
    }
}
