<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * ⚠️ `FakeSavService` remplace ENTIÈREMENT `SavService` : ces tests ne peuvent donc, par
 * construction, jamais toucher un vrai fil ni déclencher un vrai e-mail — conformément au mandat
 * de tâche (« ne jamais tester reply() contre les fils réels »).
 */
final class SavControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER = ['REQUEST_METHOD' => 'GET'];
        $_GET = [];
        $_POST = [];
    }

    public function testMethodNotAllowedForDelete(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'DELETE'];

        $controller = new TestSavController();
        $controller->initContent();

        $this->assertSame(405, $controller->response['status']);
    }

    public function testListReturnsThreadsEnvelope(): void
    {
        $controller = new TestSavController();
        $controller->injectFakeService(new FakeListSavService());
        $controller->initContent();

        $this->assertSame(200, $controller->response['status']);
        $this->assertArrayHasKey('threads', $controller->response['payload']);
        $this->assertArrayHasKey('pagination', $controller->response['payload']);
    }

    public function testInvalidStatusFilterIsRejected(): void
    {
        $_GET['status'] = 'archived';

        $controller = new TestSavController();
        $controller->injectFakeService(new FakeListSavService());
        $controller->initContent();

        $this->assertSame(400, $controller->response['status']);
        $this->assertSame('invalid_payload', $controller->response['payload']['error']);
    }

    public function testGetUnknownThreadReturns404(): void
    {
        $_GET['id'] = 999;

        $controller = new TestSavController();
        $controller->injectFakeService(new FakeEmptySavService());
        $controller->initContent();

        $this->assertSame(404, $controller->response['status']);
        $this->assertSame('not_found', $controller->response['payload']['error']);
    }

    public function testPatchWithUnsupportedActionReturns400(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'PATCH'];
        $_GET = ['id' => 1, 'action' => 'archive'];

        $controller = new TestSavController();
        $controller->initContent();

        $this->assertSame(400, $controller->response['status']);
        $this->assertSame('invalid_payload', $controller->response['payload']['error']);
    }

    public function testPatchStatusSuccessReturns204WithoutBody(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'PATCH'];
        $_GET = ['id' => 1, 'action' => 'status'];

        $controller = new TestSavController();
        $controller->decodeBodyOverride = ['status' => 'closed'];
        $controller->injectFakeService(new FakeListSavService());
        $controller->initContent();

        $this->assertTrue($controller->noContentCalled);
        $this->assertNull($controller->response);
    }

    public function testPostReplySuccessReturns201WithEnvelope(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
        $_GET = ['id' => 1, 'action' => 'reply'];

        $controller = new TestSavController();
        $controller->decodeBodyOverride = ['message' => 'Bonjour, voici la réponse.'];
        $controller->injectFakeService(new FakeListSavService());
        $controller->initContent();

        $this->assertSame(201, $controller->response['status']);
        $this->assertArrayHasKey('thread', $controller->response['payload']);
        $this->assertArrayHasKey('message', $controller->response['payload']);
        $this->assertArrayHasKey('email_sent', $controller->response['payload']);
    }

    public function testPostReplyOnUnknownThreadReturns404(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
        $_GET = ['id' => 999, 'action' => 'reply'];

        $controller = new TestSavController();
        $controller->decodeBodyOverride = ['message' => 'Bonjour.'];
        $controller->injectFakeService(new FakeEmptySavService());
        $controller->initContent();

        $this->assertSame(404, $controller->response['status']);
    }

    public function testStatsActionReturnsToProcessCountEnvelope(): void
    {
        $_GET['action'] = 'stats';

        $controller = new TestSavController();
        $controller->injectFakeService(new FakeStatsSavService());
        $controller->initContent();

        $this->assertSame(200, $controller->response['status']);
        $this->assertSame(['to_process' => 2], $controller->response['payload']);
    }

    public function testStatsActionNeverListsThreadsEvenWithFakeListService(): void
    {
        // `action=stats` doit être intercepté AVANT toute logique de liste/pagination, quel que
        // soit le service injecté — non-régression de l'ordre des branches dans handleGet().
        $_GET['action'] = 'stats';

        $controller = new TestSavController();
        $controller->injectFakeService(new FakeListSavService());
        $controller->initContent();

        $this->assertSame(200, $controller->response['status']);
        $this->assertArrayNotHasKey('threads', $controller->response['payload']);
    }

    public function testToProcessFilterIsForwardedToService(): void
    {
        $_GET['to_process'] = '1';

        $controller = new TestSavController();
        $fakeService = new FakeListSavService();
        $controller->injectFakeService($fakeService);
        $controller->initContent();

        $this->assertSame(200, $controller->response['status']);
        $this->assertArrayHasKey('to_process', $fakeService->receivedFilters);
        $this->assertTrue($fakeService->receivedFilters['to_process']);
    }

    public function testToProcessFilterIgnoredWhenNotTruthy(): void
    {
        $_GET['to_process'] = '0';

        $controller = new TestSavController();
        $fakeService = new FakeListSavService();
        $controller->injectFakeService($fakeService);
        $controller->initContent();

        $this->assertSame(200, $controller->response['status']);
        $this->assertArrayNotHasKey('to_process', $fakeService->receivedFilters);
    }
}

final class TestSavController extends RebuildconnectorSavModuleFrontController
{
    /** @var array<string, mixed>|null */
    public ?array $response = null;
    public bool $noContentCalled = false;
    /** @var array<string, mixed> */
    public array $decodeBodyOverride = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function injectFakeService(SavService $service): void
    {
        $property = new \ReflectionProperty(RebuildconnectorSavModuleFrontController::class, 'savService');
        $property->setAccessible(true);
        $property->setValue($this, $service);
    }

    protected function renderJson(array $payload, int $statusCode = 200): void
    {
        $this->response = ['status' => $statusCode, 'payload' => $payload];
    }

    protected function renderNoContent(): void
    {
        $this->noContentCalled = true;
    }

    protected function jsonError(string $error, string $message, int $statusCode): void
    {
        $this->renderJson(['error' => $error, 'message' => $message], $statusCode);
    }

    protected function requireAuth(array $requiredScopes = []): array
    {
        return ['scopes' => $requiredScopes, 'sub' => 'test', 'id_employee' => 7];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeRequestBody(): array
    {
        return $this->decodeBodyOverride;
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

final class FakeEmptySavService extends SavService
{
    public function getThreadById(int $idThread): ?array
    {
        return null;
    }

    public function reply(int $idThread, string $message, ?int $idEmployee, string $ipAddress, string $userAgent): ?array
    {
        return null;
    }

    public function changeStatus(int $idThread, string $status): ?array
    {
        return null;
    }
}

final class FakeStatsSavService extends SavService
{
    public function getToProcessCount(): int
    {
        return 2;
    }
}

final class FakeListSavService extends SavService
{
    /** @var array<string, mixed> */
    public array $receivedFilters = [];

    public function getThreads(array $filters = []): array
    {
        $this->receivedFilters = $filters;

        return [
            'items' => [['id' => 1, 'status' => 'open']],
            'pagination' => ['limit' => 20, 'offset' => 0, 'count' => 1, 'has_next' => false, 'next_offset' => null],
        ];
    }

    public function getThreadById(int $idThread): ?array
    {
        return ['thread' => ['id' => $idThread], 'messages' => []];
    }

    public function reply(int $idThread, string $message, ?int $idEmployee, string $ipAddress, string $userAgent): ?array
    {
        return [
            'thread' => ['id' => $idThread, 'status' => 'pending1'],
            'message' => ['id' => 99, 'author' => 'employee', 'message' => $message],
            'email_sent' => true,
        ];
    }

    public function changeStatus(int $idThread, string $status): ?array
    {
        return ['id' => $idThread, 'status' => $status];
    }
}
