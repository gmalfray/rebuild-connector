<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Tests unitaires pour OrdersService.
 *
 * Les méthodes testées ici (parseStatusesFilter, formatOrderRow) sont privées.
 * On les invoque via ReflectionMethod pour éviter de changer leur visibilité
 * uniquement dans un but de testabilité.
 */
final class OrdersServiceTest extends TestCase
{
    private OrdersService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OrdersService();
        Order::$testIdShop = null;
        Order::$testInvoices = [];
        PDF::$testRenderResult = '';
    }

    protected function tearDown(): void
    {
        Order::$testIdShop = null;
        Order::$testInvoices = [];
        PDF::$testRenderResult = '';
        parent::tearDown();
    }

    // =========================================================================
    // getInvoicePdf — contrôle multistore id_shop (protection IDOR, m4).
    // La récupération de facture ne doit JAMAIS renvoyer le PDF d'une commande
    // appartenant à une autre boutique que celle du contexte courant.
    // =========================================================================

    public function testGetInvoicePdfDeniesCrossShopAccess(): void
    {
        // Contexte boutique courante = shop id 1 (stub Shop). La commande appartient au shop 2.
        Order::$testIdShop = 2;
        // Même avec une facture disponible et un PDF rendable, l'accès doit être refusé.
        Order::$testInvoices = [new OrderInvoice()];
        PDF::$testRenderResult = 'PDFBYTES';

        $this->assertNull($this->service->getInvoicePdf(42));
    }

    public function testGetInvoicePdfReturnsPdfForSameShop(): void
    {
        Order::$testIdShop = 1;
        Order::$testInvoices = [new OrderInvoice()];
        PDF::$testRenderResult = 'PDFBYTES';

        $this->assertSame('PDFBYTES', $this->service->getInvoicePdf(42));
    }

    public function testGetInvoicePdfReturnsNullWhenNoInvoice(): void
    {
        Order::$testIdShop = 1;
        Order::$testInvoices = [];
        PDF::$testRenderResult = 'PDFBYTES';

        $this->assertNull($this->service->getInvoicePdf(42));
    }

    // =========================================================================
    // updateStatus / updateShipping — contrôle multistore id_shop (protection IDOR, m1).
    // Une écriture sur une commande d'une autre boutique doit être refusée, à l'identique
    // de getInvoicePdf()/getOrderById() ci-dessus.
    // =========================================================================

    public function testUpdateStatusDeniesCrossShopAccess(): void
    {
        // Contexte boutique courante = shop id 1 (stub Shop). La commande appartient au shop 2.
        Order::$testIdShop = 2;
        // Statut par ailleurs valide (id existant simulé) : seul le contrôle boutique doit bloquer.
        Db::$testGetValueResult = 4;

        $this->assertFalse($this->service->updateStatus(42, '4'));

        Db::$testGetValueResult = 0;
    }

    public function testUpdateStatusSucceedsForSameShop(): void
    {
        Order::$testIdShop = 1;
        Db::$testGetValueResult = 4;

        $this->assertTrue($this->service->updateStatus(42, '4'));

        Db::$testGetValueResult = 0;
    }

    public function testUpdateShippingDeniesCrossShopAccess(): void
    {
        // Contexte boutique courante = shop id 1 (stub Shop). La commande appartient au shop 2.
        Order::$testIdShop = 2;

        $this->assertFalse($this->service->updateShipping(42, 'TRACK123', 1));
    }

    public function testUpdateShippingSucceedsForSameShop(): void
    {
        Order::$testIdShop = 1;

        $this->assertTrue($this->service->updateShipping(42, 'TRACK123', 1));
    }

    // =========================================================================
    // parseStatusesFilter
    // =========================================================================

    public function testParseStatusesFilterReturnsEmptyForNull(): void
    {
        $result = $this->invokeParseStatusesFilter(null);
        $this->assertSame([], $result);
    }

    public function testParseStatusesFilterReturnsEmptyForEmptyString(): void
    {
        $result = $this->invokeParseStatusesFilter('');
        $this->assertSame([], $result);
    }

    public function testParseStatusesFilterReturnsEmptyForFalse(): void
    {
        $result = $this->invokeParseStatusesFilter(false);
        $this->assertSame([], $result);
    }

    public function testParseStatusesFilterParsesCsvString(): void
    {
        $result = $this->invokeParseStatusesFilter('2,3,4,5');
        $this->assertSame([2, 3, 4, 5], $result);
    }

    public function testParseStatusesFilterAcceptsArray(): void
    {
        $result = $this->invokeParseStatusesFilter([2, '3', 4]);
        $this->assertSame([2, 3, 4], $result);
    }

    public function testParseStatusesFilterFiltersOutZeroAndNegative(): void
    {
        $result = $this->invokeParseStatusesFilter('0,-1,3,foo,5');
        $this->assertSame([3, 5], $result);
    }

    public function testParseStatusesFilterDeduplicates(): void
    {
        $result = $this->invokeParseStatusesFilter('2,2,3,3');
        $this->assertSame([2, 3], $result);
    }

    public function testParseStatusesFilterIgnoresNonNumericStrings(): void
    {
        $result = $this->invokeParseStatusesFilter('foo,bar,baz');
        $this->assertSame([], $result);
    }

    public function testParseStatusesFilterAcceptsSingleId(): void
    {
        $result = $this->invokeParseStatusesFilter('4');
        $this->assertSame([4], $result);
    }

    // =========================================================================
    // formatOrderRow — vérification que status_color est présent dans la sortie
    // =========================================================================

    public function testFormatOrderRowExposesStatusColor(): void
    {
        $row = [
            'id_order'            => 10,
            'reference'           => 'TEST001',
            'current_state'       => 2,
            'status_name'         => 'En attente',
            'status_color'        => '#d9534f',
            'total_paid_tax_incl' => 49.90,
            'currency_iso'        => 'EUR',
            'date_add'            => '2025-06-01 10:00:00',
            'date_upd'            => '2025-06-02 11:00:00',
            'invoice_number'      => 0,
            'id_customer'         => 5,
            'firstname'           => 'Jean',
            'lastname'            => 'Dupont',
        ];

        $result = $this->invokeFormatOrderRow($row);

        $this->assertArrayHasKey('status_color', $result);
        $this->assertSame('#d9534f', $result['status_color']);
    }

    public function testFormatOrderRowStatusColorIsEmptyStringWhenMissing(): void
    {
        $row = [
            'id_order'            => 11,
            'reference'           => 'TEST002',
            'current_state'       => 3,
            'status_name'         => 'Expédiée',
            // Pas de status_color dans la ligne (valeur NULL côté BDD → clé absente)
            'total_paid_tax_incl' => 20.00,
            'currency_iso'        => 'EUR',
            'date_add'            => '2025-06-03 09:00:00',
            'date_upd'            => '2025-06-03 09:00:00',
            'invoice_number'      => 1,
            'id_customer'         => 6,
            'firstname'           => 'Marie',
            'lastname'            => 'Martin',
        ];

        $result = $this->invokeFormatOrderRow($row);

        $this->assertArrayHasKey('status_color', $result);
        $this->assertSame('', $result['status_color']);
    }

    public function testFormatOrderRowStatusColorIsStringWhenNull(): void
    {
        $row = [
            'id_order'            => 12,
            'reference'           => 'TEST003',
            'current_state'       => 1,
            'status_name'         => 'Annulée',
            'status_color'        => null,
            'total_paid_tax_incl' => 0.0,
            'currency_iso'        => 'EUR',
            'date_add'            => '2025-06-04 08:00:00',
            'date_upd'            => '2025-06-04 08:00:00',
            'invoice_number'      => 0,
            'id_customer'         => 7,
            'firstname'           => 'Paul',
            'lastname'            => 'Bernard',
        ];

        $result = $this->invokeFormatOrderRow($row);

        $this->assertArrayHasKey('status_color', $result);
        $this->assertSame('', $result['status_color']);
    }

    // =========================================================================
    // resolveOrderStateId — résolution nom → id (FIX : plus d'appel à la méthode
    // fantôme OrderState::getIdByName qui provoquait un fatal 500 sur PATCH /orders/{id})
    // =========================================================================

    public function testResolveOrderStateIdResolvesStatusByName(): void
    {
        // Le stub Db::getValue renvoie l'id simulé de order_state_lang pour « Expédié ».
        Db::$testGetValueResult = 4;

        $result = $this->invokeResolveOrderStateId('Expédié');

        $this->assertSame(4, $result);

        Db::$testGetValueResult = 0;
    }

    public function testResolveOrderStateIdReturnsZeroForUnknownName(): void
    {
        Db::$testGetValueResult = 0;

        $result = $this->invokeResolveOrderStateId('Statut qui n’existe pas');

        $this->assertSame(0, $result);
    }

    public function testResolveOrderStateIdReturnsZeroForEmptyReference(): void
    {
        $this->assertSame(0, $this->invokeResolveOrderStateId(''));
    }

    public function testStatusExistsByNameDoesNotFatal(): void
    {
        // Reproduit le flux PATCH /orders/{id} avec un statut passé par nom : ne doit plus lever
        // de fatal (Error) sur une méthode PrestaShop inexistante.
        Db::$testGetValueResult = 4;

        $this->assertTrue($this->service->statusExists('Expédié'));

        Db::$testGetValueResult = 0;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param mixed $raw
     * @return int[]
     */
    private function invokeParseStatusesFilter($raw): array
    {
        $method = new \ReflectionMethod(OrdersService::class, 'parseStatusesFilter');
        $method->setAccessible(true);
        /** @var int[] $result */
        $result = $method->invoke($this->service, $raw);

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function invokeFormatOrderRow(array $row): array
    {
        $method = new \ReflectionMethod(OrdersService::class, 'formatOrderRow');
        $method->setAccessible(true);
        /** @var array<string, mixed> $result */
        $result = $method->invoke($this->service, $row);

        return $result;
    }

    private function invokeResolveOrderStateId(string $reference): int
    {
        $method = new \ReflectionMethod(OrdersService::class, 'resolveOrderStateId');
        $method->setAccessible(true);

        return (int) $method->invoke($this->service, $reference);
    }
}
