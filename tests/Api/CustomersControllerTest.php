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
        // Enveloppe réelle renvoyée par CustomersController::handleGet() :
        // { customers: [...], pagination: {...} } — pas { data: [...], meta: { pagination } }.
        $this->assertCount(1, $controller->response['payload']['customers']);
        $this->assertTrue($controller->response['payload']['pagination']['has_next']);
    }
}

final class TestCustomersController extends RebuildconnectorCustomersModuleFrontController
{
    /** @var array<string, mixed>|null */
    public ?array $response = null;

    /**
     * @param array<int, array<string, mixed>> $dataset
     */
    public function __construct(array $dataset = [])
    {
        parent::__construct();

        if ($dataset !== []) {
            // CustomersController::getCustomersService() est `private` : un override de
            // méthode dans cette sous-classe ne serait jamais appelé (liaison statique des
            // méthodes privées en PHP), le contrôleur continuerait donc à créer un vrai
            // CustomersService et à taper en base. On injecte le fake directement via
            // Reflection sur la propriété privée, comme pour NoContentOrdersController
            // dans OrdersControllerTest.
            $property = new \ReflectionProperty(RebuildconnectorCustomersModuleFrontController::class, 'customersService');
            $property->setAccessible(true);
            $property->setValue($this, $this->buildFakeCustomersService($dataset));
        }
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

    /**
     * @param array<int, array<string, mixed>> $dataset
     */
    private function buildFakeCustomersService(array $dataset): CustomersService
    {
        return new class($dataset) extends CustomersService {
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
}
