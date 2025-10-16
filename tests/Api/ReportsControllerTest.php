<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class ReportsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
        $_GET = [];
    }

    public function testMethodNotAllowedIsReturnedForPost(): void
    {
        $controller = new TestReportsController();
        $controller->initContent();

        $this->assertSame(405, $controller->response['status']);
        $this->assertSame('method_not_allowed', $controller->response['payload']['error']);
    }
}

final class TestReportsController extends RebuildconnectorReportsModuleFrontController
{
    /** @var array<string, mixed>|null */
    public ?array $response = null;

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
