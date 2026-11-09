<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class ReportsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        DbQuery::$testWhereLog = [];
        DbQuery::$testSelectLog = [];
        Db::$testExecuteSResult = [];
    }

    protected function tearDown(): void
    {
        Db::$testExecuteSResult = [];
        DbQuery::$testWhereLog = [];
        DbQuery::$testSelectLog = [];
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
}
