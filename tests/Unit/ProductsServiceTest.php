<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class ProductsServiceTest extends TestCase
{
    public function testFormatProductRowExposesEan13(): void
    {
        $service = new ProductsService();
        $row = [
            'id_product' => 88,
            'name' => 'T-shirt noir',
            'reference' => 'TSHIRT-BLACK',
            'ean13' => '3760123456789',
            'active' => 1,
            'date_upd' => '2025-06-01 12:00:00',
            'base_price' => 19.08,
            'quantity' => 3,
            'low_stock_threshold' => 5,
        ];

        $method = new ReflectionMethod(ProductsService::class, 'formatProductRow');
        $method->setAccessible(true);
        /** @var array<string, mixed> $formatted */
        $formatted = $method->invoke($service, $row, 1, 1);

        $this->assertSame('3760123456789', $formatted['ean13']);
        $this->assertSame('TSHIRT-BLACK', $formatted['reference']);
    }

    public function testFormatProductRowDefaultsMissingEan13ToEmptyString(): void
    {
        $service = new ProductsService();
        $row = [
            'id_product' => 88,
            'name' => 'T-shirt noir',
            'reference' => 'TSHIRT-BLACK',
            'active' => 1,
            'date_upd' => '2025-06-01 12:00:00',
            'base_price' => 19.08,
            'quantity' => 3,
            'low_stock_threshold' => 5,
        ];

        $method = new ReflectionMethod(ProductsService::class, 'formatProductRow');
        $method->setAccessible(true);
        /** @var array<string, mixed> $formatted */
        $formatted = $method->invoke($service, $row, 1, 1);

        $this->assertSame('', $formatted['ean13']);
    }

    public function testGetProductsAcceptsBarcodeFilterWithoutError(): void
    {
        $service = new ProductsService();

        // Le mock Db::executeS() renvoie toujours [] ; on vérifie ici que le filtre
        // "barcode" est accepté sans lever d'exception (construction de requête valide).
        $products = $service->getProducts(['barcode' => '3760123456789']);

        $this->assertSame([], $products);
    }
}
