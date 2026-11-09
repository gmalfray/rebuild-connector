<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * @see ReportsService::getReviewsSummary()
 * @see RbReviewsBridge::getSummary()
 * @see SavService::getThreadStats()
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
     * @param array{total_reviews: int, native_reviews: int, average_rating: float|null, pending_reviews: int} $summary
     */
    private function fakeBridge(bool $available, array $summary): ReviewsBridgeInterface
    {
        return new class ($available, $summary) implements ReviewsBridgeInterface {
            /** @var array<int, mixed> */
            public array $calls = [];

            private bool $available;

            /** @var array{total_reviews: int, native_reviews: int, average_rating: float|null, pending_reviews: int} */
            private array $summary;

            /** @param array{total_reviews: int, native_reviews: int, average_rating: float|null, pending_reviews: int} $summary */
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
        $bridge = $this->fakeBridge(true, ['total_reviews' => 50, 'native_reviews' => 10, 'average_rating' => 4.62, 'pending_reviews' => 3]);

        $result = (new ReportsService())->getReviewsSummary($bridge);

        $this->assertSame(50, $result['total_reviews']);
        $this->assertSame(10, $result['native_reviews']);
        $this->assertSame(4.62, $result['average_rating']);
        $this->assertSame(3, $result['pending_reviews']);
        $this->assertSame(200, $result['orders']);
        $this->assertSame(5.0, $result['review_rate'], 'review_rate = native_reviews / orders, pas total_reviews.');
        $this->assertSame(['from' => null, 'to' => null], $result['period']);
        $this->assertArrayHasKey('sav', $result);
        $this->assertSame(['open_threads', 'answered_threads'], array_keys($result['sav']));
    }

    public function testReviewRateIsNullWithoutOrders(): void
    {
        Db::$testGetValueResult = 0;
        $bridge = $this->fakeBridge(true, ['total_reviews' => 5, 'native_reviews' => 2, 'average_rating' => null, 'pending_reviews' => 0]);

        $result = (new ReportsService())->getReviewsSummary($bridge);

        $this->assertNull($result['review_rate']);
        $this->assertNull($result['average_rating']);
    }

    public function testUnavailableModuleThrowsBeforeAnyQuery(): void
    {
        $bridge = $this->fakeBridge(false, ['total_reviews' => 1, 'native_reviews' => 0, 'average_rating' => 5.0, 'pending_reviews' => 0]);

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
        $bridge = $this->fakeBridge(true, ['total_reviews' => 0, 'native_reviews' => 0, 'average_rating' => null, 'pending_reviews' => 0]);

        $result = (new ReportsService())->getReviewsSummary($bridge, ['date_from' => '2026-01-01', 'date_to' => '2026-03-31']);

        $this->assertSame(['from' => '2026-01-01 00:00:00', 'to' => '2026-03-31 23:59:59'], $result['period']);
        $this->assertSame('2026-01-01 00:00:00', $bridge->calls[0][1]); // @phpstan-ignore-line
        $this->assertSame('2026-03-31 23:59:59', $bridge->calls[0][2]); // @phpstan-ignore-line
        $this->assertContains('o.date_add >= "2026-01-01 00:00:00"', DbQuery::$testWhereLog);
        $this->assertContains('o.date_add <= "2026-03-31 23:59:59"', DbQuery::$testWhereLog);
    }

    public function testOrdersDenominatorUsesRealSalesPivotAndShop(): void
    {
        $bridge = $this->fakeBridge(true, ['total_reviews' => 0, 'native_reviews' => 0, 'average_rating' => null, 'pending_reviews' => 0]);
        (new ReportsService())->getReviewsSummary($bridge);

        $this->assertContains('o.valid = 1', DbQuery::$testWhereLog);
        $this->assertContains('o.total_paid_tax_incl > 0', DbQuery::$testWhereLog);
        $shop = array_filter(DbQuery::$testWhereLog, static fn (string $w): bool => strpos($w, 'o.id_shop = ') === 0);
        $this->assertNotEmpty($shop);
    }

    public function testInvalidOrInconsistentDatesThrow(): void
    {
        $bridge = $this->fakeBridge(true, ['total_reviews' => 0, 'native_reviews' => 0, 'average_rating' => null, 'pending_reviews' => 0]);

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
        Db::$testExecuteSResult = [['total_reviews' => '7', 'native_reviews' => '3', 'average_rating' => '4.285714']];
        Db::$testGetValueResult = 2;

        $summary = (new RbReviewsBridge())->getSummary(1, '2026-01-01 00:00:00', '2026-12-31 23:59:59');

        $this->assertSame(7, $summary['total_reviews']);
        $this->assertSame(3, $summary['native_reviews']);
        $this->assertContains('SUM(CASE WHEN r.source = "native" THEN 1 ELSE 0 END) AS native_reviews', DbQuery::$testSelectLog);
        $this->assertSame(4.29, $summary['average_rating']);
        $this->assertSame(2, $summary['pending_reviews']);
        $this->assertContains('r.validated = 1 AND r.deleted = 0', DbQuery::$testWhereLog);
        $this->assertContains('r.validated = 0 AND r.deleted = 0', DbQuery::$testWhereLog);
        $this->assertContains('r.id_shop = 1', DbQuery::$testWhereLog);
        $this->assertContains('r.date_add >= "2026-01-01 00:00:00"', DbQuery::$testWhereLog);
    }

    public function testRbBridgeSummaryAverageIsNullWithoutReviews(): void
    {
        Db::$testExecuteSResult = [['total_reviews' => '0', 'native_reviews' => null, 'average_rating' => null]];

        $summary = (new RbReviewsBridge())->getSummary(1, null, null);

        $this->assertSame(0, $summary['total_reviews']);
        $this->assertSame(0, $summary['native_reviews']);
        $this->assertNull($summary['average_rating']);
    }

    public function testRbBridgeSummaryThrowsWhenModuleDisabled(): void
    {
        Module::$testEnabledModules = ['rbreviews' => false];

        $this->expectException(ReviewsUnavailableException::class);
        (new RbReviewsBridge())->getSummary(1, null, null);
    }

    public function testSavThreadStatsHaveNoMedianAndFilterShopAndPeriod(): void
    {
        Db::$testGetValueResult = 5;

        $stats = (new SavService())->getThreadStats('2026-01-01 00:00:00', null);

        $this->assertSame(['open_threads' => 5, 'answered_threads' => 5], $stats);
        $this->assertArrayNotHasKey('median_first_response_hours', $stats);
        $this->assertContains('ct.status <> "closed"', DbQuery::$testWhereLog);
        $this->assertContains('ct.date_add >= "2026-01-01 00:00:00"', DbQuery::$testWhereLog);
        $this->assertContains('COUNT(DISTINCT ct.id_customer_thread)', DbQuery::$testSelectLog);
    }
}
