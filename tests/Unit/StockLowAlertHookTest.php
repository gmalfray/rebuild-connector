<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * hookActionUpdateQuantity (alertes push "stock faible", événement `stock.low`) : vérifie le
 * cablage du toggle BO, la garde produit actif/seuil (ProductsService::getStockAlertContext) et le
 * branchement sur la logique de franchissement (StockAlertService::decide()), sans base réelle
 * (Db/Configuration/StockAvailable sont les doubles de test de phpstan-bootstrap.php).
 *
 * L'envoi effectif (hub push) est hors périmètre ici : le hub n'est jamais configuré dans ces tests
 * (REBUILDCONNECTOR_HUB_URL_OVERRIDE pointe vers un port fermé, comme ValidateOrderHookTest), donc
 * notifyDevices() court-circuite avant tout appel réseau. Le signal observable retenu est l'audit
 * `stock.low` (recordAudit()), écrit de façon SYNCHRONE avant le différé runAfterResponse() — un
 * proxy fiable de "le module a décidé de notifier".
 */
final class StockLowAlertHookTest extends TestCase
{
    private RebuildConnector $module;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('REBUILDCONNECTOR_HUB_URL_OVERRIDE')) {
            define('REBUILDCONNECTOR_HUB_URL_OVERRIDE', 'http://127.0.0.1:1');
        }

        Db::$testGetValueResult = 0;
        Db::$testExecuteSResult = [];
        Db::$executedSql = [];
        Db::$insertedRows = [];
        StockAvailable::$testQuantityAvailableResult = 0;
        Configuration::$testValues = [];

        $this->module = new RebuildConnector();
    }

    protected function tearDown(): void
    {
        Db::$testGetValueResult = 0;
        Db::$testExecuteSResult = [];
        Db::$executedSql = [];
        Db::$insertedRows = [];
        StockAvailable::$testQuantityAvailableResult = 0;
        Configuration::$testValues = [];
        parent::tearDown();
    }

    public function testDisabledByDefaultDoesNotNotifyEvenBelowThreshold(): void
    {
        // Toggle stock_low_alerts_enabled non configuré → ensureDefaults() le pose à false.
        $this->givenActiveProductWithThreshold(5);
        StockAvailable::$testQuantityAvailableResult = 2;

        $this->module->hookActionUpdateQuantity(['id_product' => 42, 'id_product_attribute' => 0]);

        $this->assertNull($this->findStockLowAudit(), 'Le toggle désactivé par défaut ne doit jamais notifier.');
    }

    public function testEnabledAndBelowThresholdNeverAlertedRecordsAudit(): void
    {
        $this->enableStockLowAlerts();
        $this->givenActiveProductWithThreshold(5);
        StockAvailable::$testQuantityAvailableResult = 3;
        Db::$testGetValueResult = 0; // hasAlert() => jamais alerté.

        try {
            $this->module->hookActionUpdateQuantity(['id_product' => 42, 'id_product_attribute' => 0]);
        } catch (\Throwable $exception) {
            $this->fail('hookActionUpdateQuantity ne doit jamais lever d\'exception : ' . $exception->getMessage());
        }

        $this->assertNotNull($this->findStockLowAudit(), 'Un franchissement descendant jamais alerté doit notifier.');
    }

    public function testEnabledAndAlreadyAlertedDoesNotSpam(): void
    {
        $this->enableStockLowAlerts();
        $this->givenActiveProductWithThreshold(5);
        StockAvailable::$testQuantityAvailableResult = 3;
        Db::$testGetValueResult = 1; // hasAlert() => déjà alerté pour ce franchissement.

        $this->module->hookActionUpdateQuantity(['id_product' => 42, 'id_product_attribute' => 0]);

        $this->assertNull($this->findStockLowAudit(), 'Un produit déjà alerté ne doit pas redéclencher de notification.');
    }

    public function testEnabledAndAboveThresholdRearmsWithoutNotifying(): void
    {
        $this->enableStockLowAlerts();
        $this->givenActiveProductWithThreshold(5);
        StockAvailable::$testQuantityAvailableResult = 20;

        $this->module->hookActionUpdateQuantity(['id_product' => 42, 'id_product_attribute' => 0]);

        $this->assertNull($this->findStockLowAudit(), 'Un stock au-dessus du seuil ne doit jamais notifier.');
        $delete = $this->findExecutedSql('DELETE FROM');
        $this->assertNotNull($delete, 'Un stock au-dessus du seuil doit réarmer (DELETE) l\'éventuelle alerte existante.');
        $this->assertStringContainsString('rebuildconnector_stock_alert', (string) $delete);
    }

    public function testEnabledAndOutOfStockDoesNotNotify(): void
    {
        $this->enableStockLowAlerts();
        $this->givenActiveProductWithThreshold(5);
        StockAvailable::$testQuantityAvailableResult = 0;

        $this->module->hookActionUpdateQuantity(['id_product' => 42, 'id_product_attribute' => 0]);

        $this->assertNull($this->findStockLowAudit(), 'Une rupture totale (qty <= 0) est hors périmètre "stock faible".');
    }

    public function testInactiveProductNeverNotifies(): void
    {
        $this->enableStockLowAlerts();
        Db::$testExecuteSResult = [['active' => 0, 'low_stock_threshold' => 5]];
        StockAvailable::$testQuantityAvailableResult = 1;

        $this->module->hookActionUpdateQuantity(['id_product' => 42, 'id_product_attribute' => 0]);

        $this->assertNull($this->findStockLowAudit(), 'Un produit inactif ne doit jamais notifier.');
    }

    public function testMissingProductIdIsIgnoredSafely(): void
    {
        $this->enableStockLowAlerts();

        try {
            $this->module->hookActionUpdateQuantity([]);
            $this->module->hookActionUpdateQuantity(['id_product' => 0]);
        } catch (\Throwable $exception) {
            $this->fail('hookActionUpdateQuantity doit ignorer un id_product manquant/invalide sans throw : ' . $exception->getMessage());
        }

        $this->assertNull($this->findStockLowAudit());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function enableStockLowAlerts(): void
    {
        Configuration::$testValues['REBUILDCONNECTOR_SETTINGS'] = json_encode([
            'stock_low_alerts_enabled' => true,
        ]);
    }

    private function givenActiveProductWithThreshold(int $threshold): void
    {
        Db::$testExecuteSResult = [[
            'active' => 1,
            'low_stock_threshold' => $threshold,
        ]];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findStockLowAudit(): ?array
    {
        foreach (Db::$insertedRows as $row) {
            if ($row['table'] !== AuditLogService::TABLE_NAME) {
                continue;
            }

            if (($row['data']['event'] ?? null) === 'stock.low') {
                return $row['data'];
            }
        }

        return null;
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
