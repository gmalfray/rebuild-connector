<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class CustomersControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER = ['REQUEST_METHOD' => 'GET'];
        $_GET = [];
    }

    public function testInvalidEmailFilterTriggersError(): void
    {
        $_GET['email'] = 'not-an-email';

        $controller = new TestCustomersController();
        $controller->initContent();

        $this->assertSame(400, $controller->response['status']);
        $this->assertSame('invalid_payload', $controller->response['payload']['error']);
    }

    public function testReturnsPaginatedList(): void
    {
        $_GET['limit'] = 1;
        $controller = new TestCustomersController([
            ['id_customer' => 1, 'firstname' => 'A', 'lastname' => 'Alpha', 'email' => 'a@example.com', 'date_add' => '2024-01-01', 'orders_count' => 0, 'total_spent' => 0.0],
            ['id_customer' => 2, 'firstname' => 'B', 'lastname' => 'Bravo', 'email' => 'b@example.com', 'date_add' => '2024-01-02', 'orders_count' => 1, 'total_spent' => 10.0],
        ]);
        $controller->initContent();

        $this->assertSame(200, $controller->response['status']);
        $this->assertCount(1, $controller->response['payload']['data']);
        $this->assertTrue($controller->response['payload']['meta']['pagination']['has_next']);
    }
}

final class TestCustomersController extends RebuildconnectorCustomersModuleFrontController
{
    /** @var array<string, mixed>|null */
    public ?array $response = null;
    /** @var array<int, array<string, mixed>> */
    private array $dataset;

    /**
     * @param array<int, array<string, mixed>> $dataset
     */
    public function __construct(array $dataset = [])
    {
        parent::__construct();
        $this->dataset = $dataset;
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

    private function buildFakeCustomersService(): CustomersService
    {
        return new class($this->dataset) extends CustomersService {
            /** @var array<int, array<string, mixed>> */
            private array $dataset;

            public function __construct(array $dataset)
            {
                parent::__construct(new OrdersService());
                $this->dataset = $dataset;
            }

            protected function executeCustomerQuery(DbQuery $query): array
            {
                return $this->dataset;
            }
        };
    }

    protected function getCustomersService(): CustomersService
    {
        if ($this->dataset === []) {
            return parent::getCustomersService();
        }

        return $this->buildFakeCustomersService();
    }
}
