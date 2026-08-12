<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class ProductsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER = ['REQUEST_METHOD' => 'DELETE'];
        $_GET = [];
    }

    public function testDeleteMethodIsRejected(): void
    {
        $controller = new TestProductsController();
        $controller->initContent();

        $this->assertSame(405, $controller->response['status']);
        $this->assertSame('method_not_allowed', $controller->response['payload']['error']);
    }

    public function testPatchStockActionRequiresTheStockWriteScope(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'PATCH'];
        $_GET = ['id' => 88];

        $controller = new TestProductsController();
        $controller->body = ['quantity' => 15];
        $fakeService = $controller->injectFakeService();
        $controller->initContent();

        $this->assertSame(['stock.write'], $controller->requestedScopes);
        $this->assertSame(200, $controller->response['status']);
        $this->assertSame(15, $controller->response['payload']['quantity']);
        $this->assertCount(1, $fakeService->absoluteCalls);
    }

    public function testPatchAttributesActionRequiresTheProductsWriteScope(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'PATCH'];
        $_GET = ['id' => 88];

        $controller = new TestProductsController();
        $controller->body = ['name' => 'Nouveau nom'];
        $controller->injectFakeService();
        $controller->initContent();

        $this->assertSame(['products.write'], $controller->requestedScopes);
        $this->assertArrayNotHasKey('quantity', $controller->response['payload']);
    }

    public function testPatchStockWithBothQuantityAndDeltaIsRejected(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'PATCH'];
        $_GET = ['id' => 88, 'action' => 'stock'];

        $controller = new TestProductsController();
        $controller->body = ['quantity' => 10, 'delta' => 2];
        $fakeService = $controller->injectFakeService();
        $controller->initContent();

        $this->assertSame(400, $controller->response['status']);
        $this->assertSame('invalid_payload', $controller->response['payload']['error']);
        $this->assertSame([], $fakeService->absoluteCalls);
        $this->assertSame([], $fakeService->deltaCalls);
    }

    public function testPatchStockWithNeitherQuantityNorDeltaIsRejected(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'PATCH'];
        $_GET = ['id' => 88, 'action' => 'stock'];

        $controller = new TestProductsController();
        $controller->body = ['combination_id' => 5];
        $controller->injectFakeService();
        $controller->initContent();

        $this->assertSame(400, $controller->response['status']);
        $this->assertSame('invalid_payload', $controller->response['payload']['error']);
    }

    public function testPatchStockDeltaReturnsTheResultingQuantityFromTheService(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'PATCH'];
        $_GET = ['id' => 88];

        $controller = new TestProductsController();
        $controller->body = ['delta' => -4];
        $fakeService = $controller->injectFakeService();
        $fakeService->deltaResult = 6;
        $controller->initContent();

        $this->assertSame(200, $controller->response['status']);
        $this->assertSame(6, $controller->response['payload']['quantity']);
        $this->assertCount(1, $fakeService->deltaCalls);
        $this->assertSame(-4, $fakeService->deltaCalls[0]['delta']);
        $this->assertSame(0, $fakeService->deltaCalls[0]['combinationId']);
    }

    public function testPatchStockDeltaOnACombinationForwardsTheCombinationId(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'PATCH'];
        $_GET = ['id' => 88];

        $controller = new TestProductsController();
        $controller->body = ['delta' => 3, 'combination_id' => 501];
        $fakeService = $controller->injectFakeService();
        $fakeService->deltaResult = 9;
        $controller->initContent();

        $this->assertSame(200, $controller->response['status']);
        $this->assertSame(9, $controller->response['payload']['quantity']);
        $this->assertSame(501, $fakeService->deltaCalls[0]['combinationId']);
    }

    public function testPatchStockDeltaRejectedByTheServiceReturnsInvalidPayload(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'PATCH'];
        $_GET = ['id' => 88];

        $controller = new TestProductsController();
        $controller->body = ['delta' => 3, 'combination_id' => 999];
        $fakeService = $controller->injectFakeService();
        $fakeService->deltaResult = null; // combination_id étranger au produit, cf. ProductsService.

        $controller->initContent();

        $this->assertSame(400, $controller->response['status']);
        $this->assertSame('invalid_payload', $controller->response['payload']['error']);
    }
}

final class TestProductsController extends RebuildconnectorProductsModuleFrontController
{
    /** @var array<string, mixed>|null */
    public ?array $response = null;

    /** @var array<string, mixed> */
    public array $body = [];

    /** @var array<int, string> */
    public array $requestedScopes = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function injectFakeService(): FakeStockProductsService
    {
        $fakeService = new FakeStockProductsService();
        $property = new \ReflectionProperty(RebuildconnectorProductsModuleFrontController::class, 'productsService');
        $property->setAccessible(true);
        $property->setValue($this, $fakeService);

        return $fakeService;
    }

    protected function renderJson(array $payload, int $statusCode = 200): void
    {
        $this->response = [
            'status' => $statusCode,
            'payload' => $payload,
        ];
    }

    protected function jsonError(string $error, string $message, int $statusCode): void
    {
        $this->renderJson([
            'error' => $error,
            'message' => $message,
        ], $statusCode);
    }

    protected function requireAuth(array $requiredScopes = []): array
    {
        $this->requestedScopes = $requiredScopes;

        return ['scopes' => $requiredScopes, 'id_employee' => 7, 'sub' => 'test'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeRequestBody(): array
    {
        return $this->body;
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function recordAuditEvent(string $event, array $context = []): void
    {
        // no-op en test
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function dispatchWebhookEvent(string $event, array $payload = []): void
    {
        // no-op en test
    }
}

final class FakeStockProductsService extends ProductsService
{
    /** @var array<int, array{delta: int, combinationId: int, employeeIdentity: array<string, mixed>|null}> */
    public array $deltaCalls = [];
    public ?int $deltaResult = 42;

    /** @var array<int, array{quantity: int, combinationId: int, employeeIdentity: array<string, mixed>|null}> */
    public array $absoluteCalls = [];
    public bool $absoluteResult = true;

    public function getProductById(int $productId): array
    {
        return ['id' => $productId, 'name' => 'Produit test'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateProduct(int $productId, array $payload): bool
    {
        return true;
    }

    public function applyStockDelta(int $productId, int $delta, int $combinationId = 0, ?array $employeeIdentity = null): ?int
    {
        $this->deltaCalls[] = [
            'delta' => $delta,
            'combinationId' => $combinationId,
            'employeeIdentity' => $employeeIdentity,
        ];

        return $this->deltaResult;
    }

    public function updateStock(int $productId, int $quantity, int $combinationId = 0, ?array $employeeIdentity = null): bool
    {
        $this->absoluteCalls[] = [
            'quantity' => $quantity,
            'combinationId' => $combinationId,
            'employeeIdentity' => $employeeIdentity,
        ];

        return $this->absoluteResult;
    }
}
