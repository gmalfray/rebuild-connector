<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class ReportsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DbQuery::$testWhereLog = [];
        DbQuery::$testSelectLog = [];
        Db::$testLoggedSelectQueries = [];
        Db::$testExecuteSResult = [];
    }

    protected function tearDown(): void
    {
        DbQuery::$testWhereLog = [];
        DbQuery::$testSelectLog = [];
        Db::$testLoggedSelectQueries = [];
        Db::$testExecuteSResult = [];
        parent::tearDown();
    }

    public function testBestSellersOnlyCountValidPaidOrdersOfCurrentShop(): void
    {
        (new ReportsService())->getBestSellers();

        $this->assertContains('o.valid = 1', DbQuery::$testWhereLog);
        $this->assertContains('o.total_paid_tax_incl > 0', DbQuery::$testWhereLog);
        $this->assertContains('o.id_shop = 1', DbQuery::$testWhereLog);
    }

    public function testBestSellersKeepDateFiltersAlongsideSalesFilters(): void
    {
        (new ReportsService())->getBestSellers(['date_from' => '2026-01-01', 'date_to' => '2026-01-31 23:59:59']);

        $this->assertContains('o.valid = 1', DbQuery::$testWhereLog);
        $this->assertContains('o.date_add >= "2026-01-01"', DbQuery::$testWhereLog);
        $this->assertContains('o.date_add <= "2026-01-31 23:59:59"', DbQuery::$testWhereLog);
    }

    public function testBestCustomersAreNotFilteredByBestSellersConditions(): void
    {
        (new ReportsService())->getBestCustomers();

        $this->assertNotContains('o.total_paid_tax_incl > 0', DbQuery::$testWhereLog);
    }

    public function testBestSellersMapsRows(): void
    {
        Db::$testExecuteSResult = [[
            'product_id' => '88',
            'product_attribute_id' => '0',
            'name' => null,
            'reference' => null,
            'quantity' => '3',
            'total_tax_incl' => '30.5',
            'total_tax_excl' => '25.4',
        ]];

        $rows = (new ReportsService())->getBestSellers();

        $this->assertCount(1, $rows);
        $this->assertSame(88, $rows[0]['product_id']);
        $this->assertNull($rows[0]['name']);
        $this->assertSame(3, $rows[0]['quantity']);
    }

    public function testSalesByUniversFiltersRealSalesOfCurrentShop(): void
    {
        (new ReportsService())->getSalesByUnivers([]);

        $this->assertContains('o.valid = 1', DbQuery::$testWhereLog);
        $this->assertContains('o.total_paid_tax_incl > 0', DbQuery::$testWhereLog);
        $this->assertContains('o.id_shop = 1', DbQuery::$testWhereLog);
    }

    public function testFreePatternsQueryKeepsValidButDropsPaidConstraint(): void
    {
        (new ReportsService())->getSalesByUnivers([]);

        // Deux requêtes : CA (3 filtres) puis patrons gratuits (valid + shop + CASE, sans > 0).
        $paidCount = count(array_filter(DbQuery::$testWhereLog, static fn (string $w): bool => $w === 'o.total_paid_tax_incl > 0'));
        $validCount = count(array_filter(DbQuery::$testWhereLog, static fn (string $w): bool => $w === 'o.valid = 1'));
        $this->assertSame(1, $paidCount, 'La contrainte > 0 ne concerne que la partie CA.');
        $this->assertSame(2, $validCount);

        $freeWhere = array_values(array_filter(DbQuery::$testWhereLog, static fn (string $w): bool => strpos($w, "= 'patrons_gratuits'") !== false));
        $this->assertCount(1, $freeWhere);
    }

    public function testCaseUsesExistsAndNestedSetWithoutJoinMultiplication(): void
    {
        (new ReportsService())->getSalesByUnivers([]);

        $sql = implode(' ', DbQuery::$testSelectLog);
        $this->assertStringContainsString('CASE WHEN (p.cache_is_pack = 1 OR EXISTS', $sql);
        $this->assertStringContainsString('cp.id_category = 13', $sql);
        $this->assertStringContainsString("p.reference LIKE 'EBOOK%'", $sql);
        $this->assertStringContainsString('cc.nleft >= cr.nleft AND cc.nright <= cr.nright', $sql);
        $this->assertStringContainsString('cr.id_category = 16', $sql);
        $this->assertStringContainsString('cr.id_category = 10', $sql);
        // Ordre de priorité : kits, composants (20), gratuits (64), ebooks, pdf, livres, fils, mercerie.
        $order = ["THEN 'kits'", "THEN 'composant_kit'", "THEN 'patrons_gratuits'", "THEN 'ebooks'", "THEN 'patrons_pdf'", "THEN 'livres'", "THEN 'fils'", "THEN 'mercerie'", "ELSE 'autre'"];
        $last = -1;
        foreach ($order as $needle) {
            $pos = strpos($sql, $needle);
            $this->assertNotFalse($pos, $needle);
            $this->assertGreaterThan($last, $pos, $needle);
            $last = (int) $pos;
        }
    }

    public function testResponseMappingAndFreePatternsExcludedFromRevenue(): void
    {
        // Le stub renvoie le même jeu pour les deux requêtes ; on y glisse une ligne patrons_gratuits
        // (à ignorer côté CA), une ligne composant_kit (exclue) et une ligne kits.
        Db::$testExecuteSResult = [
            ['universe' => 'kits', 'ca_tax_incl' => '120.5', 'ca_tax_excl' => '100.4166', 'quantity' => '3', 'lines_count' => '2'],
            ['universe' => 'patrons_gratuits', 'ca_tax_incl' => '0', 'ca_tax_excl' => '0', 'quantity' => '9', 'lines_count' => '7'],
            ['universe' => 'composant_kit', 'ca_tax_incl' => '5', 'ca_tax_excl' => '4', 'quantity' => '1', 'lines_count' => '1'],
        ];

        $result = (new ReportsService())->getSalesByUnivers(['date_from' => '2026-01-01']);

        $keys = array_column($result['universes'], 'key');
        $this->assertSame(['kits', 'patrons_pdf', 'ebooks', 'livres', 'fils', 'mercerie', 'autre'], $keys);
        $this->assertNotContains('patrons_gratuits', $keys);
        $this->assertNotContains('composant_kit', $keys);

        $kits = $result['universes'][0];
        $this->assertFalse($kits['is_digital']);
        $this->assertSame(120.5, $kits['ca_tax_incl']);
        $this->assertSame(100.42, $kits['ca_tax_excl']);
        $this->assertSame(3, $kits['quantity']);
        $this->assertSame(2, $kits['lines']);

        $this->assertTrue($result['universes'][1]['is_digital']);
        $this->assertTrue($result['universes'][2]['is_digital']);
        $this->assertSame(0.0, $result['universes'][1]['ca_tax_incl']);

        // Bloc acquisition : première ligne du second executeS (le stub rend le même jeu).
        $this->assertSame(['quantity', 'lines'], array_keys($result['free_patterns']));
        $this->assertSame(['date_from' => '2026-01-01', 'date_to' => null], $result['period']);
    }

    public function testInvalidDateThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ReportsService())->getSalesByUnivers(['date_from' => '01/02/2026']);
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
