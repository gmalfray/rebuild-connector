<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Logique de franchissement de StockAlertService::decide() — anti-spam des alertes push
 * "stock faible" (événement `stock.low`). Isolée de toute base réelle : l'accès à l'état persistant
 * (hasAlert(), via SELECT COUNT(*)) est mocké par la bascule de test Db::$testGetValueResult
 * (cf. phpstan-bootstrap.php), comme le fait déjà RetentionPruneTest pour Db::$executedSql.
 */
final class StockAlertServiceTest extends TestCase
{
    private StockAlertService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StockAlertService();
        Db::$testGetValueResult = 0;
        Db::$executedSql = [];
    }

    protected function tearDown(): void
    {
        Db::$testGetValueResult = 0;
        Db::$executedSql = [];
        parent::tearDown();
    }

    public function testQuantityAboveThresholdAlwaysRearmsRegardlessOfAlertState(): void
    {
        // Même si une alerte était enregistrée (hasAlert() simulé à vrai), qty > seuil doit réarmer :
        // le franchissement est ascendant, pas descendant.
        Db::$testGetValueResult = 1;

        $this->assertSame(
            StockAlertService::ACTION_REARM,
            $this->service->decide(42, 0, 10, 5)
        );
    }

    public function testQuantityExactlyAtThresholdNeverAlertedNotifies(): void
    {
        // Seuil INCLUS (0 < qty <= seuil) : qty == seuil doit notifier si jamais alerté.
        Db::$testGetValueResult = 0;

        $this->assertSame(
            StockAlertService::ACTION_NOTIFY,
            $this->service->decide(42, 0, 5, 5)
        );
    }

    public function testOutOfStockNeverNotifiesRegardlessOfAlertState(): void
    {
        // Rupture totale (qty <= 0) : hors périmètre de "stock faible", quel que soit l'état d'alerte.
        Db::$testGetValueResult = 0;
        $this->assertSame(StockAlertService::ACTION_NONE, $this->service->decide(42, 0, 0, 5));

        Db::$testGetValueResult = 1;
        $this->assertSame(StockAlertService::ACTION_NONE, $this->service->decide(42, 0, 0, 5));
    }

    public function testLowStockNeverAlertedYetNotifies(): void
    {
        Db::$testGetValueResult = 0;

        $this->assertSame(
            StockAlertService::ACTION_NOTIFY,
            $this->service->decide(42, 0, 3, 5)
        );
    }

    public function testLowStockAlreadyAlertedDoesNotSpam(): void
    {
        // Point dur de la feature : une alerte déjà enregistrée pour ce couple (produit, déclinaison)
        // ne doit PAS redéclencher de notification tant que le stock reste sous le seuil.
        Db::$testGetValueResult = 1;

        $this->assertSame(
            StockAlertService::ACTION_NONE,
            $this->service->decide(42, 0, 3, 5)
        );
    }

    public function testDecisionIsIndependentPerCombination(): void
    {
        // decide() ne fait pas de distinction id_product_attribute dans son propre code (délégué à
        // hasAlert()), mais l'appel doit rester valide pour une déclinaison (id_product_attribute > 0).
        Db::$testGetValueResult = 0;

        $this->assertSame(
            StockAlertService::ACTION_NOTIFY,
            $this->service->decide(42, 7, 2, 5)
        );
    }

    public function testMarkAlertedGeneratesUpsertOnProductAndCombinationKey(): void
    {
        $this->service->markAlerted(42, 7);

        $insert = $this->findExecutedSql('INSERT INTO');
        $this->assertNotNull($insert, 'markAlerted() doit générer un INSERT.');
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', (string) $insert);
        $this->assertStringContainsString('rebuildconnector_stock_alert', (string) $insert);
    }

    public function testClearAlertGeneratesDeleteOnProductAndCombinationKey(): void
    {
        $this->service->clearAlert(42, 7);

        $delete = $this->findExecutedSql('DELETE FROM');
        $this->assertNotNull($delete, 'clearAlert() doit générer un DELETE.');
        $this->assertStringContainsString('rebuildconnector_stock_alert', (string) $delete);
        $this->assertStringContainsString('42', (string) $delete);
        $this->assertStringContainsString('7', (string) $delete);
    }

    public function testInvalidProductIdIsNoOp(): void
    {
        $this->service->markAlerted(0, 0);
        $this->service->clearAlert(0, 0);

        $this->assertSame([], Db::$executedSql, 'Un id_product invalide ne doit générer aucune requête.');
        $this->assertFalse($this->service->hasAlert(0, 0));
    }

    private function findExecutedSql(string $needle): ?string
    {
        foreach (Db::$executedSql as $sql) {
            if (strpos($sql, $needle) !== false) {
                return $sql;
            }
        }

        return null;
    }
}
