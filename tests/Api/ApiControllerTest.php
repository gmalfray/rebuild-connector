<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class ApiControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER = [];
        $_POST = [];
    }

    public function testRejectsUnsupportedHttpMethod(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $controller = new TestApiController();
        $controller->initContent();

        $this->assertSame(405, $controller->response['status']);
        $this->assertSame('method_not_allowed', $controller->response['payload']['error']);
    }

    public function testRejectsRequestWithoutApiKey(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['api_key' => ''];
        $controller = new TestApiController();
        $controller->initContent();

        $this->assertSame(400, $controller->response['status']);
        $this->assertSame('invalid_request', $controller->response['payload']['error']);
    }

    public function testAuthenticatesAndReturnsToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['api_key' => 'valid'];
        $controller = new TestApiController();
        $controller->mockToken = [
            'token_type' => 'Bearer',
            'token' => 'jwt-token',
            'expires_in' => 3600,
            'issued_at' => '2024-01-01T00:00:00Z',
            'expires_at' => '2024-01-01T01:00:00Z',
        ];
        $controller->initContent();

        $this->assertSame(200, $controller->response['status']);
        $this->assertSame('jwt-token', $controller->response['payload']['access_token']);
    }
}

final class TestApiController extends RebuildconnectorApiModuleFrontController
{
    /** @var array<string, mixed>|null */
    public ?array $response = null;
    /** @var array<string, mixed>|null */
    public ?array $mockToken = null;

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

    protected function getAuthService(): AuthService
    {
        return new class($this->mockToken) extends AuthService {
            /** @var array<string, mixed>|null */
            private ?array $payload;

            public function __construct(?array $payload)
            {
                $this->payload = $payload;
            }

            public function authenticate(string $apiKey, ?string $shopUrl = null): array
            {
                if ($apiKey !== 'valid') {
                    throw new AuthenticationException('invalid');
                }

                return $this->payload ?? ['token_type' => 'Bearer', 'token' => 'default-token'];
            }
        };
    }
}
