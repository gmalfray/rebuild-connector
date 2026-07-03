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

    // =========================================================================
    // m2 — un limit non numérique doit renvoyer 400 (pas de TypeError → 500).
    // =========================================================================

    public function testNonNumericLimitReturnsBadRequest(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET'];
        $_GET = ['resource' => 'bestsellers', 'limit' => 'abc'];

        $controller = new TestReportsController();
        $controller->initContent();

        $this->assertSame(400, $controller->response['status']);
        $this->assertSame('invalid_payload', $controller->response['payload']['error']);
    }

    public function testParseLimitThrowsForNonNumericValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->invokeParseLimit('abc');
    }

    public function testParseLimitReturnsNullWhenAbsent(): void
    {
        $this->assertNull($this->invokeParseLimit(false));
        $this->assertNull($this->invokeParseLimit(''));
        $this->assertNull($this->invokeParseLimit(null));
    }

    public function testParseLimitCastsNumericValue(): void
    {
        $this->assertSame(25, $this->invokeParseLimit('25'));
    }

    /**
     * @param mixed $value
     */
    private function invokeParseLimit($value): ?int
    {
        $method = new \ReflectionMethod(RebuildconnectorReportsModuleFrontController::class, 'parseLimit');
        $method->setAccessible(true);

        /** @var int|null $result */
        $result = $method->invoke(new TestReportsController(), $value);

        return $result;
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
