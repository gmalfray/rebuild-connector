<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Tests unitaires pour ColissimoLabelService — deux zones à risque, testées sans base réelle
 * (doubles de test de phpstan-bootstrap.php) :
 *
 *   1. applyShippedState() — la règle de garde-fou anti-rétrogradation du changement d'état
 *      après génération d'étiquette (ne jamais rétrograder une commande déjà Expédiée/Livrée/
 *      Remise au transporteur/Terminée/Annulée, ne jamais dupliquer si déjà au bon état).
 *   2. syncTrackingNumberToOrder() — l'écriture double champ (order_carrier.tracking_number +
 *      orders.shipping_number) sur la bonne ligne order_carrier, et le traçage (audit) des échecs
 *      qui étaient auparavant avalés en silence.
 *
 * Les deux méthodes sont privées : invoquées via ReflectionMethod, à l'identique du reste de la
 * suite (cf. SettingsServiceTest::invokeRenderSecretPreview).
 */
final class ColissimoLabelServiceTest extends TestCase
{
    private ColissimoLabelService $service;
    private SettingsService $settingsService;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolation : Configuration::$testValues est un registre statique partagé entre fichiers de
        // test (process PHPUnit unique, cf. phpunit.xml sans isolation) — on repart toujours à vide.
        Configuration::$testValues = [];

        $this->settingsService = new SettingsService();
        $this->service = new ColissimoLabelService($this->settingsService, new AuditLogService());

        OrderHistory::$testChangeIdOrderStateCalls = [];
        OrderHistory::$testAddWithemailCallCount = 0;
        Db::$testGetValueResult = 0;
        Db::$updatedRows = [];
        Db::$insertedRows = [];
    }

    protected function tearDown(): void
    {
        Configuration::$testValues = [];
        OrderHistory::$testChangeIdOrderStateCalls = [];
        OrderHistory::$testAddWithemailCallCount = 0;
        Db::$testGetValueResult = 0;
        Db::$updatedRows = [];
        Db::$insertedRows = [];
        parent::tearDown();
    }

    // =========================================================================
    // applyShippedState — règle de garde-fou
    // =========================================================================

    public function testAppliesDefaultShippedStateFromUpstreamState(): void
    {
        $order = new Order(42);
        $order->current_state = 2; // Paiement accepté

        $this->invokeApplyShippedState($order);

        $this->assertSame(
            [['state_id' => 20, 'order_id' => 42]],
            OrderHistory::$testChangeIdOrderStateCalls
        );
        $this->assertSame(1, OrderHistory::$testAddWithemailCallCount);
    }

    public function testDoesNotChangeStateWhenAlreadyAtTargetState(): void
    {
        $order = new Order(42);
        $order->current_state = 20; // déjà à l'état cible : pas de doublon d'historique

        $this->invokeApplyShippedState($order);

        $this->assertSame([], OrderHistory::$testChangeIdOrderStateCalls);
        $this->assertSame(0, OrderHistory::$testAddWithemailCallCount);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function noRegressStateProvider(): array
    {
        return [
            'Expédié (4)' => [4],
            'Livré (5)' => [5],
            'Annulée (6)' => [6],
            'Terminée (9)' => [9],
            'Remis au transporteur (21)' => [21],
        ];
    }

    /**
     * @dataProvider noRegressStateProvider
     */
    public function testNeverRegressesAnOrderAlreadyInAFinalOrDownstreamState(int $currentStateId): void
    {
        $order = new Order(42);
        $order->current_state = $currentStateId;

        $this->invokeApplyShippedState($order);

        $this->assertSame(
            [],
            OrderHistory::$testChangeIdOrderStateCalls,
            'Une commande déjà en aval ne doit jamais être rétrogradée par la génération d\'étiquette.'
        );
        $this->assertSame(0, OrderHistory::$testAddWithemailCallCount);
    }

    public function testUsesConfiguredShippedStateIdInsteadOfDefault(): void
    {
        $this->settingsService->setLabelShippedStateId(30);

        $order = new Order(42);
        $order->current_state = 3; // Préparation en cours

        $this->invokeApplyShippedState($order);

        $this->assertSame(
            [['state_id' => 30, 'order_id' => 42]],
            OrderHistory::$testChangeIdOrderStateCalls
        );
    }

    // =========================================================================
    // syncTrackingNumberToOrder — écriture double champ + traçage des échecs
    // =========================================================================

    public function testSyncWritesBothTrackingFieldsAndLogsSuccessAudit(): void
    {
        Db::$testGetValueResult = 77; // id_order_carrier trouvé

        $order = new Order(42);
        $this->invokeSyncTrackingNumberToOrder($order, 'TRACK123');

        $orderCarrierUpdate = $this->findUpdate('order_carrier');
        $this->assertNotNull($orderCarrierUpdate);
        $this->assertSame(['tracking_number' => 'TRACK123'], $orderCarrierUpdate['data']);
        $this->assertSame('id_order_carrier = 77', $orderCarrierUpdate['where']);

        $ordersUpdate = $this->findUpdate('orders');
        $this->assertNotNull($ordersUpdate);
        $this->assertSame(['shipping_number' => 'TRACK123'], $ordersUpdate['data']);
        $this->assertSame('id_order = 42', $ordersUpdate['where']);

        $audit = $this->findAudit('orders.shipping_label.tracking_synced');
        $this->assertNotNull($audit);
        $this->assertNull($this->findAudit('orders.shipping_label.tracking_sync_failed'));
    }

    public function testSyncLogsFailureAuditWhenNoOrderCarrierRowFound(): void
    {
        Db::$testGetValueResult = 0; // aucune ligne order_carrier pour cette commande

        $order = new Order(42);
        $this->invokeSyncTrackingNumberToOrder($order, 'TRACK123');

        $this->assertNull($this->findUpdate('order_carrier'));
        $this->assertNull($this->findUpdate('orders'));

        $audit = $this->findAudit('orders.shipping_label.tracking_sync_failed');
        $this->assertNotNull($audit);

        $context = json_decode((string) $audit['context'], true);
        $this->assertSame('order_carrier_row_not_found', $context['reason'] ?? null);
        $this->assertNull($this->findAudit('orders.shipping_label.tracking_synced'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function invokeApplyShippedState(Order $order): void
    {
        $method = new \ReflectionMethod(ColissimoLabelService::class, 'applyShippedState');
        $method->setAccessible(true);
        $method->invoke($this->service, $order);
    }

    private function invokeSyncTrackingNumberToOrder(Order $order, string $trackingNumber): void
    {
        $method = new \ReflectionMethod(ColissimoLabelService::class, 'syncTrackingNumberToOrder');
        $method->setAccessible(true);
        $method->invoke($this->service, $order, $trackingNumber);
    }

    /**
     * @return array{table: string, data: array<string, mixed>, where: string}|null
     */
    private function findUpdate(string $table): ?array
    {
        foreach (Db::$updatedRows as $row) {
            if ($row['table'] === $table) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findAudit(string $event): ?array
    {
        foreach (Db::$insertedRows as $row) {
            if ($row['table'] !== AuditLogService::TABLE_NAME) {
                continue;
            }

            if (($row['data']['event'] ?? null) === $event) {
                return $row['data'];
            }
        }

        return null;
    }
}
