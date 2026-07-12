<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Tests des bornes de périodes CIVILES (v1.15.0) et de la comparaison N-1 « à date comparable ».
 *
 * @see DashboardService::resolvePeriodRange()
 * @see DashboardService::resolveYoyComparisonRange()
 */
final class DashboardServiceCivilPeriodsTest extends TestCase
{
    protected function tearDown(): void
    {
        Db::$testLoggedSelectQueries = [];
        Db::$testGetValueResult = 0;
        Db::$testExecuteSResult = [];
        DbQuery::$testSelectLog = [];
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // resolvePeriodRange() — bornes civiles calées sur « maintenant » (fuseau boutique).
    // Les assertions restent structurelles (jour de semaine / 1er du mois-trimestre-année / heure
    // 00:00:00) plutôt que sur une date figée, pour ne pas dépendre du jour d'exécution du test.
    // -----------------------------------------------------------------------

    private function callResolvePeriodRange(string $period): array
    {
        $service = new DashboardService();
        $method = new \ReflectionMethod(DashboardService::class, 'resolvePeriodRange');
        $method->setAccessible(true);

        /** @var array{from: \DateTimeImmutable, to: \DateTimeImmutable} $range */
        $range = $method->invoke($service, $period);

        return $range;
    }

    public function testTodayRangeStartsAtMidnightAndEndsNow(): void
    {
        $now = new \DateTimeImmutable('now');
        $range = $this->callResolvePeriodRange('today');

        $this->assertSame($now->format('Y-m-d'), $range['from']->format('Y-m-d'));
        $this->assertSame('00:00:00', $range['from']->format('H:i:s'));
        $this->assertEqualsWithDelta($now->getTimestamp(), $range['to']->getTimestamp(), 5);
    }

    public function testDayAliasBehavesLikeToday(): void
    {
        $range = $this->callResolvePeriodRange('day');
        $this->assertSame('00:00:00', $range['from']->format('H:i:s'));
        $this->assertSame((new \DateTimeImmutable('now'))->format('Y-m-d'), $range['from']->format('Y-m-d'));
    }

    public function testWeekRangeStartsOnMonday(): void
    {
        $now = new \DateTimeImmutable('now');
        $range = $this->callResolvePeriodRange('week');

        $this->assertSame('1', $range['from']->format('N'), 'La borne de début « week » doit être un lundi.');
        $this->assertSame('00:00:00', $range['from']->format('H:i:s'));
        // Même semaine ISO (année-semaine) que maintenant.
        $this->assertSame($now->format('o-W'), $range['from']->format('o-W'));
        $this->assertLessThanOrEqual($now->format('Y-m-d'), $range['from']->format('Y-m-d'));
    }

    public function testMonthRangeStartsOnFirstOfMonth(): void
    {
        $now = new \DateTimeImmutable('now');
        $range = $this->callResolvePeriodRange('month');

        $this->assertSame($now->format('Y-m-01'), $range['from']->format('Y-m-d'));
        $this->assertSame('00:00:00', $range['from']->format('H:i:s'));
    }

    public function testQuarterRangeStartsOnFirstOfCivilQuarter(): void
    {
        $now = new \DateTimeImmutable('now');
        $range = $this->callResolvePeriodRange('quarter');

        $this->assertSame('01', $range['from']->format('d'));
        $this->assertSame('00:00:00', $range['from']->format('H:i:s'));
        $this->assertSame($now->format('Y'), $range['from']->format('Y'));

        $expectedQuarterStartMonth = intdiv(((int) $now->format('n')) - 1, 3) * 3 + 1;
        $this->assertSame($expectedQuarterStartMonth, (int) $range['from']->format('n'));
    }

    public function testYearRangeStartsOnJanuaryFirst(): void
    {
        $now = new \DateTimeImmutable('now');
        $range = $this->callResolvePeriodRange('year');

        $this->assertSame($now->format('Y-01-01'), $range['from']->format('Y-m-d'));
        $this->assertSame('00:00:00', $range['from']->format('H:i:s'));
    }

    public function testAllPresetsEndAtNowNotEndOfDay(): void
    {
        // Les périodes sont désormais potentiellement PARTIELLES : `to` = maintenant, pas 23:59:59.
        $now = new \DateTimeImmutable('now');
        foreach (['today', 'week', 'month', 'quarter', 'year'] as $period) {
            $range = $this->callResolvePeriodRange($period);
            $this->assertEqualsWithDelta(
                $now->getTimestamp(),
                $range['to']->getTimestamp(),
                5,
                "La borne de fin de la période '$period' doit être proche de maintenant."
            );
        }
    }

    // -----------------------------------------------------------------------
    // resolveYoyComparisonRange() — comparaison N-1 « à date comparable », tronquée à la durée
    // écoulée D = to - from. Dates FIGÉES (pas « maintenant ») pour des assertions déterministes.
    // -----------------------------------------------------------------------

    /**
     * @return array{from: \DateTimeImmutable, to: \DateTimeImmutable}
     */
    private function callResolveYoyComparisonRange(\DateTimeImmutable $from, \DateTimeImmutable $to, string $period): array
    {
        $service = new DashboardService();
        $method = new \ReflectionMethod(DashboardService::class, 'resolveYoyComparisonRange');
        $method->setAccessible(true);

        /** @var array{from: \DateTimeImmutable, to: \DateTimeImmutable} $range */
        $range = $method->invoke($service, $from, $to, $period);

        return $range;
    }

    public function testYoyComparisonTodayIsSameDateLastYear(): void
    {
        // 'today' consulté à 09:30 → comparaison au même jour l'an dernier, même heure écoulée.
        $from = new \DateTimeImmutable('2025-07-13 00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2025-07-13 09:30:00', new \DateTimeZone('UTC'));

        $range = $this->callResolveYoyComparisonRange($from, $to, 'today');

        $this->assertSame('2024-07-13 00:00:00', $range['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2024-07-13 09:30:00', $range['to']->format('Y-m-d H:i:s'));
    }

    public function testYoyComparisonWeekRealignsOnPreviousYearMonday(): void
    {
        // 2025-07-07 est un lundi ; 2025-07-07 - 1 an = 2024-07-07, un DIMANCHE. La comparaison doit
        // retomber sur le lundi de LA SEMAINE contenant cette date (2024-07-01), pas sur le dimanche.
        $from = new \DateTimeImmutable('2025-07-07 00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2025-07-10 15:00:00', new \DateTimeZone('UTC'));

        $range = $this->callResolveYoyComparisonRange($from, $to, 'week');

        $this->assertSame('2024-07-01 00:00:00', $range['from']->format('Y-m-d H:i:s'));
        $this->assertSame('1', $range['from']->format('N'), 'Le début de la comparaison N-1 doit rester un lundi.');
        // D = 3j15h appliqué à partir du lundi N-1 recalé.
        $this->assertSame('2024-07-04 15:00:00', $range['to']->format('Y-m-d H:i:s'));
    }

    public function testYoyComparisonPartialMonth(): void
    {
        // Cas explicitement demandé : « ce mois au 13 » vs « même mois l'an dernier au 13 ».
        $from = new \DateTimeImmutable('2025-07-01 00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2025-07-13 14:00:00', new \DateTimeZone('UTC'));

        $range = $this->callResolveYoyComparisonRange($from, $to, 'month');

        $this->assertSame('2024-07-01 00:00:00', $range['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2024-07-13 14:00:00', $range['to']->format('Y-m-d H:i:s'));
    }

    public function testYoyComparisonQuarter(): void
    {
        // Q3 2025 (juil-sept), consultation le 15 août.
        $from = new \DateTimeImmutable('2025-07-01 00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2025-08-15 10:00:00', new \DateTimeZone('UTC'));

        $range = $this->callResolveYoyComparisonRange($from, $to, 'quarter');

        $this->assertSame('2024-07-01 00:00:00', $range['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2024-08-15 10:00:00', $range['to']->format('Y-m-d H:i:s'));
    }

    public function testYoyComparisonYearCrossingLeapDayTruncatesByRawDuration(): void
    {
        // Cas limite documenté : D est une durée BRUTE (secondes écoulées), pas un décalage
        // calendaire. Ici D (1er janv → 13 juil 2025, année non bissextile) = 193 jours + 9h.
        // Appliquée à partir du 1er janvier 2024 (bissextile, +1 jour en février), la même durée
        // brute retombe donc au 12 juillet 2024 (et non au 13) : le jour bissextile « consomme »
        // un jour de la durée écoulée. C'est le comportement attendu de la troncature « à D ».
        $from = new \DateTimeImmutable('2025-01-01 00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2025-07-13 09:00:00', new \DateTimeZone('UTC'));

        $range = $this->callResolveYoyComparisonRange($from, $to, 'year');

        $this->assertSame('2024-01-01 00:00:00', $range['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2024-07-12 09:00:00', $range['to']->format('Y-m-d H:i:s'));
    }

    public function testYoyComparisonFeb29FallsBackToFeb28OnNonLeapYear(): void
    {
        // 'today' un 29 février (année bissextile 2024) → l'an dernier (2023, non bissextile) n'a
        // pas de 29 février : convention retenue = 28 février.
        $from = new \DateTimeImmutable('2024-02-29 00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2024-02-29 18:00:00', new \DateTimeZone('UTC'));

        $range = $this->callResolveYoyComparisonRange($from, $to, 'today');

        $this->assertSame('2023-02-28 00:00:00', $range['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2023-02-28 18:00:00', $range['to']->format('Y-m-d H:i:s'));
    }

    // -----------------------------------------------------------------------
    // Intégration : getMetrics() expose `comparison_period` (champ additif) et garde
    // `previous_turnover` intact ; le chart `year` bascule en granularité mensuelle.
    // -----------------------------------------------------------------------

    public function testGetMetricsExposesComparisonPeriodField(): void
    {
        $service = new DashboardService();
        $metrics = $service->getMetrics('month');

        $this->assertArrayHasKey('comparison_period', $metrics);
        $this->assertArrayHasKey('from', $metrics['comparison_period']);
        $this->assertArrayHasKey('to', $metrics['comparison_period']);
        // Champ historique toujours présent, forme inchangée (un nombre).
        $this->assertArrayHasKey('previous_turnover', $metrics);
        $this->assertIsFloat($metrics['previous_turnover']);
    }

    public function testGetMetricsCustomRangeKeepsShiftedWindowComparison(): void
    {
        // Plage libre : comportement historique inchangé (fenêtre équivalente précédente).
        $from = new \DateTimeImmutable('2025-06-01 00:00:00');
        $to = new \DateTimeImmutable('2025-06-07 23:59:59');
        $service = new DashboardService();
        $metrics = $service->getMetrics('custom', DashboardService::LOW_STOCK_THRESHOLD, $from, $to);

        // period/comparison_period sont sérialisés en DATE_ATOM (avec offset) : on vérifie le
        // préfixe date+heure, indépendant du fuseau par défaut du process exécutant le test.
        $this->assertStringStartsWith('2025-05-25T00:00:00', $metrics['comparison_period']['from']);
        $this->assertStringStartsWith('2025-05-31T23:59:59', $metrics['comparison_period']['to']);
    }

    public function testYearPeriodChartUsesMonthlyGranularity(): void
    {
        $service = new DashboardService();
        $metrics = $service->getMetrics('year');

        $now = new \DateTimeImmutable('now');
        $expectedPoints = (int) $now->format('n'); // janvier..mois courant inclus

        $this->assertCount($expectedPoints, $metrics['chart'], 'Le chart « year » doit avoir 1 point par mois écoulé (jusqu\'à 12).');
        foreach ($metrics['chart'] as $point) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-01$/', $point['label'], 'Le label du chart mensuel doit être le 1er du mois.');
            $this->assertArrayHasKey('new_customers', $point);
        }
    }
}
