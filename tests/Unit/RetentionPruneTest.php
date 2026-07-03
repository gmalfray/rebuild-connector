<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Tests de la politique de rétention (purge des tables à croissance non bornée) :
 *  - RateLimiterService::prune()  → table rebuildconnector_rate_limit
 *  - AuditLogService::prune()     → table rebuildconnector_audit_log
 *
 * On ne dispose pas d'une base réelle : on capture le DELETE généré (Db::$executedSql) et on
 * vérifie que le seuil de date sépare correctement les lignes « trop vieilles » (supprimées) des
 * lignes « récentes » (conservées).
 */
final class RetentionPruneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Db::$executedSql = [];
    }

    protected function tearDown(): void
    {
        Db::$executedSql = [];
        parent::tearDown();
    }

    // =========================================================================
    // RateLimiterService::prune() — seuil en minutes
    // =========================================================================

    public function testRateLimiterPruneGeneratesDeleteWithThreshold(): void
    {
        (new RateLimiterService())->prune(180);

        $delete = $this->findDeleteStatement('rate_limit');
        $this->assertNotNull($delete, 'Aucun DELETE généré par RateLimiterService::prune().');

        $threshold = $this->extractThreshold($delete);
        $this->assertNotNull($threshold, 'Seuil de date introuvable dans le DELETE.');

        $old = (new \DateTimeImmutable('now'))->modify('-200 minutes')->format('Y-m-d H:i:s');
        $recent = (new \DateTimeImmutable('now'))->modify('-10 minutes')->format('Y-m-d H:i:s');

        // Une ligne de 200 min est plus vieille que le seuil (180 min) → serait supprimée.
        $this->assertLessThan($threshold, $old);
        // Une ligne de 10 min est plus récente que le seuil → serait conservée.
        $this->assertGreaterThan($threshold, $recent);
    }

    // =========================================================================
    // AuditLogService::prune() — seuil en jours (rétention 90 j par défaut)
    // =========================================================================

    public function testAuditLogPruneUsesNinetyDayRetentionByDefault(): void
    {
        (new AuditLogService())->prune();

        $delete = $this->findDeleteStatement('audit_log');
        $this->assertNotNull($delete, 'Aucun DELETE généré par AuditLogService::prune().');

        $threshold = $this->extractThreshold($delete);
        $this->assertNotNull($threshold, 'Seuil de date introuvable dans le DELETE.');

        $old = (new \DateTimeImmutable('now'))->modify('-91 days')->format('Y-m-d H:i:s');
        $recent = (new \DateTimeImmutable('now'))->modify('-1 day')->format('Y-m-d H:i:s');

        // Une ligne de 91 jours dépasse la rétention (90 j) → serait supprimée.
        $this->assertLessThan($threshold, $old);
        // Une ligne d'un jour est en deçà de la rétention → serait conservée.
        $this->assertGreaterThan($threshold, $recent);
    }

    public function testAuditLogPruneTargetsAuditTable(): void
    {
        (new AuditLogService())->prune(90);

        $delete = $this->findDeleteStatement('audit_log');
        $this->assertNotNull($delete);
        $this->assertStringContainsString('created_at', (string) $delete);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function findDeleteStatement(string $tableFragment): ?string
    {
        foreach (Db::$executedSql as $sql) {
            if (strpos($sql, 'DELETE') !== false && strpos($sql, $tableFragment) !== false) {
                return $sql;
            }
        }

        return null;
    }

    private function extractThreshold(string $sql): ?string
    {
        if (preg_match('/< "([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})"/', $sql, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
