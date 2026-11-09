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
        Db::$testExecuteSResult = [];
    }

    protected function tearDown(): void
    {
        DbQuery::$testWhereLog = [];
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
}
