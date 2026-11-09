<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * @see DashboardService::getMonthlyTurnover()
 */
final class DashboardServiceMonthlyTurnoverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Db::$testExecuteSResult = [];
        DbQuery::$testSelectLog = [];
        DbQuery::$testWhereLog = [];
    }

    protected function tearDown(): void
    {
        Db::$testExecuteSResult = [];
        DbQuery::$testSelectLog = [];
        DbQuery::$testWhereLog = [];
        parent::tearDown();
    }

    public function testDefaultIsTwelveContinuousAscendingMonthsEndingNow(): void
    {
        $result = (new DashboardService())->getMonthlyTurnover();

        $this->assertArrayHasKey('months', $result);
        $this->assertArrayHasKey('period', $result);
        $this->assertCount(12, $result['months']);

        $current = (new \DateTimeImmutable('now'))->format('Y-m');
        $this->assertSame($current, $result['months'][11]['month']);

        $previous = null;
        foreach ($result['months'] as $point) {
            $this->assertSame(['month', 'turnover', 'orders'], array_keys($point));
            $this->assertSame(0.0, $point['turnover'], 'Sans vente : 0.0');
            $this->assertSame(0, $point['orders']);
            if ($previous !== null) {
                $this->assertSame(
                    (new \DateTimeImmutable($previous . '-01'))->modify('+1 month')->format('Y-m'),
                    $point['month'],
                    'Série continue, un point par mois, ordre croissant.'
                );
            }
            $previous = $point['month'];
        }

        $this->assertSame($result['months'][0]['month'] . '-01', $result['period']['from']);
    }

    public function testMonthsAreClampedBetweenOneAndThirtySix(): void
    {
        $this->assertCount(1, (new DashboardService())->getMonthlyTurnover(0)['months']);
        $this->assertCount(36, (new DashboardService())->getMonthlyTurnover(99)['months']);
        $this->assertCount(1, (new DashboardService())->getMonthlyTurnover(1)['months']);
    }

    public function testRowsAreMappedAndGapsFilledWithZero(): void
    {
        $now = new \DateTimeImmutable('now');
        $current = $now->format('Y-m');
        $twoAgo = $now->setDate((int) $now->format('Y'), (int) $now->format('n'), 1)->modify('-2 months')->format('Y-m');
        Db::$testExecuteSResult = [
            ['month' => $twoAgo, 'turnover' => '1234.5600', 'orders' => '42'],
            ['month' => $current, 'turnover' => '10', 'orders' => '1'],
            ['month' => '1999-01', 'turnover' => '999', 'orders' => '9'], // hors fenêtre : ignoré
        ];

        $result = (new DashboardService())->getMonthlyTurnover(4);

        $this->assertCount(4, $result['months']);
        $byMonth = [];
        foreach ($result['months'] as $p) {
            $byMonth[$p['month']] = $p;
        }
        $this->assertSame(1234.56, $byMonth[$twoAgo]['turnover']);
        $this->assertSame(42, $byMonth[$twoAgo]['orders']);
        $this->assertSame(10.0, $byMonth[$current]['turnover']);
        $this->assertSame(1, $byMonth[$current]['orders']);
        $this->assertArrayNotHasKey('1999-01', $byMonth);
        $zeroMonths = array_filter($result['months'], static fn (array $p): bool => $p['orders'] === 0);
        $this->assertCount(2, $zeroMonths);
    }

    public function testQueryUsesRealSalesPivotShopAndMonthGrouping(): void
    {
        (new DashboardService())->getMonthlyTurnover(3);

        $this->assertContains('o.valid = 1', DbQuery::$testWhereLog);
        $this->assertContains('o.total_paid_tax_incl > 0', DbQuery::$testWhereLog);
        $shopClauses = array_filter(DbQuery::$testWhereLog, static fn (string $w): bool => strpos($w, 'o.id_shop = ') === 0);
        $this->assertNotEmpty($shopClauses, 'Filtre id_shop attendu.');

        $select = implode(' | ', DbQuery::$testSelectLog);
        $this->assertStringContainsString("DATE_FORMAT(o.date_add, '%Y-%m') AS month", $select);
        $this->assertStringContainsString('SUM(o.total_paid_tax_incl - o.total_shipping_tax_incl) AS turnover', $select);
        $this->assertStringContainsString('COUNT(*) AS orders', $select);
    }
}
