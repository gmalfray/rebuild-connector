<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class ReportsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Db::$testLoggedSelectQueries = [];
        Db::$testExecuteSResult = [];
    }

    protected function tearDown(): void
    {
        Db::$testLoggedSelectQueries = [];
        Db::$testExecuteSResult = [];
        parent::tearDown();
    }

    public function testMedianOddEvenAndEmpty(): void
    {
        $this->assertNull(ReportsService::median([]));
        $this->assertSame(5.0, ReportsService::median([30, 5, 2]));
        $this->assertSame(25.0, ReportsService::median([40, 5, 30, 20]));
        $this->assertSame(12.5, ReportsService::median([10, 40, 5, 15]));
    }

    public function testComputeGapsInDaysIsPerCustomer(): void
    {
        $rows = [
            ['id_customer' => 1, 'date_add' => '2026-01-01 10:00:00'],
            ['id_customer' => 1, 'date_add' => '2026-01-11 10:00:00'],
            ['id_customer' => 1, 'date_add' => '2026-02-10 22:00:00'],
            ['id_customer' => 2, 'date_add' => '2026-03-01 00:00:00'],
            ['id_customer' => 2, 'date_add' => '2026-03-06 12:00:00'],
        ];

        $gaps = ReportsService::computeGapsInDays($rows);

        $this->assertSame([10.0, 30.5, 5.5], $gaps);
        $this->assertSame(10.0, ReportsService::median($gaps));
    }

    public function testCustomerRetentionStructureWithoutWindow(): void
    {
        Db::$testExecuteSResult = [
            ['id_customer' => 7, 'paid_orders' => 3, 'first_paid' => '2026-01-01 00:00:00', 'date_add' => '2026-01-01 00:00:00'],
        ];

        $result = (new ReportsService())->getCustomerRetention();

        foreach ([
            'paying_customers', 'repeat_customers', 'repeat_rate', 'median_days_between_orders',
            'new_customers', 'recurring_customers', 'period',
        ] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(1, $result['paying_customers']);
        $this->assertSame(1, $result['repeat_customers']);
        $this->assertSame(100.0, $result['repeat_rate']);
        $this->assertSame(0, $result['new_customers']);
        $this->assertSame(1, $result['recurring_customers']);
        $this->assertNull($result['median_days_between_orders']);
        $this->assertSame(['date_from' => null, 'date_to' => null], $result['period']);
    }

    public function testCustomerRetentionEmptyHasZeroRate(): void
    {
        $result = (new ReportsService())->getCustomerRetention();

        $this->assertSame(0, $result['paying_customers']);
        $this->assertSame(0.0, $result['repeat_rate']);
        $this->assertNull($result['median_days_between_orders']);
    }

    public function testCustomerRetentionSplitsNewAndRecurringInWindow(): void
    {
        Db::$testExecuteSResult = [
            ['id_customer' => 1, 'paid_orders' => 1, 'first_paid' => '2026-05-10 12:00:00'],
            ['id_customer' => 2, 'paid_orders' => 2, 'first_paid' => '2025-11-01 12:00:00'],
        ];

        $result = (new ReportsService())->getCustomerRetention(['date_from' => '2026-05-01', 'date_to' => '2026-05-31']);

        $this->assertSame(2, $result['paying_customers']);
        $this->assertSame(1, $result['new_customers']);
        $this->assertSame(1, $result['recurring_customers']);
        $this->assertSame('2026-05-01 00:00:00', $result['period']['date_from']);
        $this->assertSame('2026-05-31 23:59:59', $result['period']['date_to']);
    }

    public function testCustomerRetentionFiltersValidPivotShopAndWindow(): void
    {
        Db::$testExecuteSResult = [
            ['id_customer' => 1, 'paid_orders' => 2, 'first_paid' => '2026-05-10 12:00:00', 'date_add' => '2026-05-10 12:00:00'],
        ];

        (new ReportsService())->getCustomerRetention(['date_from' => '2026-05-01', 'date_to' => '2026-05-31']);

        $this->assertCount(2, Db::$testLoggedSelectQueries);
        foreach (Db::$testLoggedSelectQueries as $sql) {
            $this->assertStringContainsString('o.valid = 1', $sql);
            $this->assertStringContainsString('o.total_paid_tax_incl > 0', $sql);
            $this->assertStringContainsString('o.id_shop = 1', $sql);
            $this->assertStringContainsString('o.date_add >= "2026-05-01 00:00:00"', $sql);
            $this->assertStringContainsString('o.date_add <= "2026-05-31 23:59:59"', $sql);
        }
        $this->assertStringContainsString('GROUP BY o.id_customer', Db::$testLoggedSelectQueries[0]);
    }

    public function testCustomerRetentionRejectsInvalidDates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ReportsService())->getCustomerRetention(['date_from' => 'hier']);
    }

    public function testCustomerRetentionRejectsInvertedRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ReportsService())->getCustomerRetention(['date_from' => '2026-06-01', 'date_to' => '2026-05-01']);
    }

    public function testAcquisitionSqlHasFiltersAndWindow(): void
    {
        (new ReportsService())->getAcquisitionChannels([
            'date_from' => '2026-05-01', 'date_to' => '2026-05-31', 'window_days' => '14',
        ]);

        $this->assertCount(1, Db::$testLoggedSelectQueries);
        $sql = Db::$testLoggedSelectQueries[0];
        $this->assertStringContainsString('o.valid = 1', $sql);
        $this->assertStringContainsString('o.total_paid_tax_incl > 0', $sql);
        $this->assertStringContainsString('o.id_shop = 1', $sql);
        $this->assertStringContainsString('o.date_add >= "2026-05-01 00:00:00"', $sql);
        $this->assertStringContainsString('o.date_add <= "2026-05-31 23:59:59"', $sql);
        $this->assertStringContainsString('ca.id_guest', $sql);
        $this->assertStringContainsString('connections_source', $sql);
        $this->assertStringContainsString('cs.date_add <= o.date_add', $sql);
        $this->assertStringContainsString('INTERVAL 14 DAY', $sql);
        $this->assertStringContainsString('ORDER BY cs.date_add DESC', $sql);
        $this->assertStringContainsString('LIMIT 1', $sql);
    }

    public function testAcquisitionDefaultWindowAndEmptyKeepsDirect(): void
    {
        $result = (new ReportsService())->getAcquisitionChannels();

        $this->assertSame(30, $result['window_days']);
        $this->assertSame([['key' => 'direct', 'orders' => 0]], $result['channels']);
        $this->assertNull($result['period']['date_from']);
        $this->assertStringContainsString('INTERVAL 30 DAY', Db::$testLoggedSelectQueries[0]);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public function channelProvider(): array
    {
        return [
            'utm ig' => ['', '/?utm_source=ig&utm_medium=bio', 'instagram'],
            'utm instagram majuscules' => ['', '/p?x=1&utm_source=Instagram', 'instagram'],
            'utm social' => ['', '/?utm_source=social', 'social'],
            'utm libre' => ['', '/?utm_source=Newsletter', 'newsletter'],
            'utm prioritaire sur referer' => ['https://www.google.com/', '/?utm_source=ig', 'instagram'],
            'google' => ['https://www.google.com/', '/', 'recherche'],
            'bing' => ['https://www.bing.com/search?q=x', '/', 'recherche'],
            'qwant' => ['https://www.qwant.com/', '/', 'recherche'],
            'instagram referer' => ['https://l.instagram.com/?u=x', '/', 'instagram'],
            'linktree' => ['https://linktr.ee/pensebonheur', '/', 'instagram'],
            'facebook' => ['https://m.facebook.com/', '/', 'facebook'],
            'pinterest' => ['https://fr.pinterest.com/', '/', 'pinterest'],
            'brevo' => ['https://app.brevo.com/x', '/', 'email'],
            'sendinblue' => ['https://sendibt3.sendinblue.com/x', '/', 'email'],
            'autre' => ['https://exemple.org/', '/', 'autre'],
        ];
    }

    /**
     * @dataProvider channelProvider
     */
    public function testDeriveChannel(string $referer, string $uri, string $expected): void
    {
        $this->assertSame($expected, ReportsService::deriveChannel($referer, $uri));
    }

    public function testAcquisitionAggregatesFallsBackToDirectAndSorts(): void
    {
        Db::$testExecuteSResult = [
            ['id_order' => 1, 'http_referer' => 'https://www.google.com/', 'request_uri' => '/'],
            ['id_order' => 2, 'http_referer' => 'https://www.google.com/', 'request_uri' => '/'],
            ['id_order' => 3, 'http_referer' => '', 'request_uri' => '/?utm_source=ig'],
            ['id_order' => 4, 'http_referer' => null, 'request_uri' => null],
            ['id_order' => 5, 'http_referer' => null, 'request_uri' => null],
            ['id_order' => 6, 'http_referer' => null, 'request_uri' => null],
        ];

        $result = (new ReportsService())->getAcquisitionChannels();

        $this->assertSame([
            ['key' => 'direct', 'orders' => 3],
            ['key' => 'recherche', 'orders' => 2],
            ['key' => 'instagram', 'orders' => 1],
        ], $result['channels']);
        $this->assertSame(6, array_sum(array_column($result['channels'], 'orders')));
    }

    /**
     * @dataProvider badWindowProvider
     * @param mixed $value
     */
    public function testAcquisitionRejectsInvalidWindow($value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ReportsService())->getAcquisitionChannels(['window_days' => $value]);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public function badWindowProvider(): array
    {
        return ['zero' => ['0'], 'trop grand' => ['366'], 'negatif' => ['-5'], 'texte' => ['abc'], 'decimal' => ['2.5']];
    }

    public function testAcquisitionAcceptsWindowBounds(): void
    {
        $this->assertSame(1, (new ReportsService())->getAcquisitionChannels(['window_days' => '1'])['window_days']);
        $this->assertSame(365, (new ReportsService())->getAcquisitionChannels(['window_days' => 365])['window_days']);
    }

    public function testAcquisitionRejectsInvalidDates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ReportsService())->getAcquisitionChannels(['date_from' => '2026-06-01', 'date_to' => '2026-05-01']);
    }
}
