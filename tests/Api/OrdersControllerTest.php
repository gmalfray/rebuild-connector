<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class OrdersControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
        $_GET = [];
    }

    public function testMethodNotAllowedIsReturnedForPost(): void
    {
        $controller = new TestOrdersController();
        $controller->initContent();

        $this->assertSame(405, $controller->response['status']);
        $this->assertSame('method_not_allowed', $controller->response['payload']['error']);
    }

    // =========================================================================
    // m6 — le succès d'un PATCH de statut doit répondre 204 SANS corps
    // (renderNoContent), et non renderJson([], 204) qui envoyait un corps `[]`.
    // =========================================================================

    public function testStatusUpdateSuccessReturns204WithoutBody(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'PATCH'];
        $_GET = ['id' => 42, 'action' => 'status'];

        $controller = new NoContentOrdersController();
        $controller->injectFakeService();
        $controller->initContent();

        $this->assertTrue($controller->noContentCalled, 'renderNoContent() aurait dû être appelé.');
        $this->assertNull($controller->response, 'Aucun corps JSON ne doit être émis pour un 204.');
    }
}

final class NoContentOrdersController extends RebuildconnectorOrdersModuleFrontController
{
    /** @var array<string, mixed>|null */
    public ?array $response = null;
    public bool $noContentCalled = false;

    public function __construct()
    {
        parent::__construct();
    }

    public function injectFakeService(): void
    {
        $property = new \ReflectionProperty(RebuildconnectorOrdersModuleFrontController::class, 'ordersService');
        $property->setAccessible(true);
        $property->setValue($this, new FakeStatusOrdersService());
    }

    protected function renderNoContent(): void
    {
        $this->noContentCalled = true;
    }

    protected function renderJson(array $payload, int $statusCode = 200): void
    {
        $this->response = ['status' => $statusCode, 'payload' => $payload];
    }

    protected function jsonError(string $error, string $message, int $statusCode): void
    {
        $this->renderJson(['error' => $error, 'message' => $message], $statusCode);
    }

    protected function requireAuth(array $requiredScopes = []): array
    {
        return ['scopes' => $requiredScopes, 'sub' => 'test'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeRequestBody(): array
    {
        return ['status' => 'Expédié'];
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

final class FakeStatusOrdersService extends OrdersService
{
    public function statusExists(string $statusReference): bool
    {
        return true;
    }

    public function updateStatus(int $orderId, string $statusReference): bool
    {
        return true;
    }
}

final class TestOrdersController extends RebuildconnectorOrdersModuleFrontController
{
    /** @var array<string, mixed>|null */
    public ?array $response = null;

    public function __construct()
    {
        parent::__construct();
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
        return ['scopes' => $requiredScopes];
    }
}
