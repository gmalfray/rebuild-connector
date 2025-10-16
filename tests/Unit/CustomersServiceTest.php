<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class CustomersServiceTest extends TestCase
{
    public function testPaginationMetadataReflectsNextPage(): void
    {
        $dataset = [
            ['id_customer' => 1, 'firstname' => 'A', 'lastname' => 'Alpha', 'email' => 'a@example.com', 'date_add' => '2024-01-01', 'orders_count' => 0, 'total_spent' => 0.0],
            ['id_customer' => 2, 'firstname' => 'B', 'lastname' => 'Bravo', 'email' => 'b@example.com', 'date_add' => '2024-01-02', 'orders_count' => 1, 'total_spent' => 10.0],
            ['id_customer' => 3, 'firstname' => 'C', 'lastname' => 'Charlie', 'email' => 'c@example.com', 'date_add' => '2024-01-03', 'orders_count' => 2, 'total_spent' => 20.0],
        ];

        $service = new TestCustomersService($dataset);
        $result = $service->getCustomers(['limit' => 2, 'offset' => 0]);

        $this->assertCount(2, $result['items']);
        $this->assertSame([
            'limit' => 2,
            'offset' => 0,
            'count' => 2,
            'has_next' => true,
            'next_offset' => 2,
        ], $result['pagination']);
    }

    public function testLimitIsClampedToMaximum(): void
    {
        $dataset = [
            ['id_customer' => 1, 'firstname' => 'A', 'lastname' => 'Alpha', 'email' => 'a@example.com', 'date_add' => '2024-01-01', 'orders_count' => 0, 'total_spent' => 0.0],
        ];

        $service = new TestCustomersService($dataset);
        $result = $service->getCustomers(['limit' => 999, 'offset' => 0]);

        $this->assertSame(CustomersService::MAX_LIMIT, $result['pagination']['limit']);
    }
}

final class TestCustomersService extends CustomersService
{
    /** @var array<int, array<string, mixed>> */
    private array $dataset;

    /**
     * @param array<int, array<string, mixed>> $dataset
     */
    public function __construct(array $dataset)
    {
        parent::__construct(new OrdersService());
        $this->dataset = $dataset;
    }

    protected function executeCustomerQuery(DbQuery $query): array
    {
        return $this->dataset;
    }
}
