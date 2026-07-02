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

    public function testUpdateProductAcceptsValidEan13(): void
    {
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['ean13' => '3760123456789']);

        $this->assertTrue($result);
    }

    public function testUpdateProductAcceptsEmptyEan13ToClearIt(): void
    {
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['ean13' => '']);

        $this->assertTrue($result);
    }

    public function testUpdateProductRejectsNonNumericEan13(): void
    {
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['ean13' => 'ABC123']);

        $this->assertFalse($result);
    }

    public function testUpdateProductRejectsTooLongEan13(): void
    {
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['ean13' => '12345678901234']);

        $this->assertFalse($result);
    }

    public function testUpdateProductRejectsNonStringEan13(): void
    {
        $service = new ProductsService();

        /** @phpstan-ignore-next-line argument.type (payload volontairement mal typé pour le test) */
        $result = $service->updateProduct(88, ['ean13' => 12345]);

        $this->assertFalse($result);
    }

    public function testUpdateProductAcceptsValidName(): void
    {
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['name' => 'T-shirt noir']);

        $this->assertTrue($result);
    }

    public function testUpdateProductRejectsEmptyName(): void
    {
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['name' => '   ']);

        $this->assertFalse($result);
    }

    public function testUpdateProductRejectsNonStringName(): void
    {
        $service = new ProductsService();

        /** @phpstan-ignore-next-line argument.type (payload volontairement mal typé pour le test) */
        $result = $service->updateProduct(88, ['name' => 12345]);

        $this->assertFalse($result);
    }

    public function testUpdateProductAcceptsValidDescription(): void
    {
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['description' => '<p>Un joli t-shirt en coton bio.</p>']);

        $this->assertTrue($result);
    }

    public function testUpdateProductAcceptsEmptyDescriptionToClearIt(): void
    {
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['description' => '']);

        $this->assertTrue($result);
    }

    public function testUpdateProductRejectsUnsafeDescriptionHtml(): void
    {
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['description' => '<script>alert(1)</script>']);

        $this->assertFalse($result);
    }

    public function testUpdateProductAcceptsValidDescriptionShort(): void
    {
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['description_short' => '<p>Résumé court.</p>']);

        $this->assertTrue($result);
    }

    public function testUpdateProductRejectsUnsafeDescriptionShortHtml(): void
    {
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['description_short' => '<iframe src="evil"></iframe>']);

        $this->assertFalse($result);
    }

    public function testUpdateProductAcceptsValidReference(): void
    {
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['reference' => 'TSHIRT-BLACK-2']);

        $this->assertTrue($result);
    }

    public function testUpdateProductAcceptsEmptyReferenceToClearIt(): void
    {
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['reference' => '']);

        $this->assertTrue($result);
    }

    public function testUpdateProductRejectsTooLongReference(): void
    {
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['reference' => str_repeat('A', 65)]);

        $this->assertFalse($result);
    }

    public function testUpdateProductRejectsNonStringReference(): void
    {
        $service = new ProductsService();

        /** @phpstan-ignore-next-line argument.type (payload volontairement mal typé pour le test) */
        $result = $service->updateProduct(88, ['reference' => 12345]);

        $this->assertFalse($result);
    }

    public function testUpdateProductAcceptsMultipleSimpleFieldsAtOnce(): void
    {
        $service = new ProductsService();

        $result = $service->updateProduct(88, [
            'name' => 'T-shirt noir',
            'description' => '<p>Description</p>',
            'description_short' => '<p>Résumé</p>',
            'reference' => 'TSHIRT-BLACK',
            'price_tax_excl' => 15.9,
            'active' => true,
            'ean13' => '3760123456789',
        ]);

        $this->assertTrue($result);
    }

    public function testApplyToAllLanguagesDuplicatesValueAcrossInstalledLanguages(): void
    {
        $service = new ProductsService();

        $method = new ReflectionMethod(ProductsService::class, 'applyToAllLanguages');
        $method->setAccessible(true);
        /** @var array<int, string> $values */
        $values = $method->invoke($service, 'T-shirt noir');

        // Le stub de test Language::getLanguages() expose une seule langue (id_lang=1) :
        // on vérifie que la valeur est bien dupliquée sur cette langue via sa clé id_lang.
        $this->assertSame(['1' => 'T-shirt noir'], $values);
    }

    protected function tearDown(): void
    {
        // Les bascules de test du stub ImageManager (phpstan-bootstrap.php) sont globales (static) :
        // on les remet à leur valeur par défaut pour ne pas polluer les autres tests.
        ImageManager::$resizeSucceeds = true;

        parent::tearDown();
    }

    public function testAddProductImageReturnsEmptyArrayWhenTmpNameMissing(): void
    {
        $service = new ProductsService();

        $result = $service->addProductImage(88, []);

        $this->assertSame([], $result);
    }

    public function testAddProductImageReturnsEmptyArrayWhenTmpFileDoesNotExist(): void
    {
        $service = new ProductsService();

        $result = $service->addProductImage(88, ['tmp_name' => '/tmp/does-not-exist-rebuildconnector-test']);

        $this->assertSame([], $result);
    }

    public function testAddProductImageReturnsEmptyArrayForInvalidProductId(): void
    {
        $service = new ProductsService();

        $result = $service->addProductImage(0, ['tmp_name' => '/tmp/whatever']);

        $this->assertSame([], $result);
    }

    public function testAddProductImageRollsBackWhenResizeFails(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rc-service-test-');
        $this->assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'dummy-bytes');

        try {
            // Simule un échec de ImageManager::resize() (ex. fichier corrompu, disque plein...) :
            // l'Image core PrestaShop nouvellement créée doit être retirée (rollback), et la méthode
            // doit renvoyer [] plutôt qu'une fiche produit à moitié construite.
            ImageManager::$resizeSucceeds = false;

            $service = new ProductsService();
            $result = $service->addProductImage(88, ['tmp_name' => $tmpFile]);

            $this->assertSame([], $result);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testDeleteProductImageReturnsFalseForInvalidProductId(): void
    {
        $service = new ProductsService();

        $this->assertFalse($service->deleteProductImage(0, 501));
    }

    public function testDeleteProductImageReturnsFalseForInvalidImageId(): void
    {
        $service = new ProductsService();

        $this->assertFalse($service->deleteProductImage(88, 0));
    }

    public function testDeleteProductImageReturnsFalseWhenImageDoesNotBelongToProduct(): void
    {
        $service = new ProductsService();

        // Le stub Image (phpstan-bootstrap.php) instancie toujours un objet avec id_product = 0 :
        // pour tout id_product demandé > 0, la vérification d'appartenance doit rejeter la suppression
        // (l'image n'appartient pas — ou plus — au produit visé) plutôt que de la supprimer à l'aveugle.
        $result = $service->deleteProductImage(88, 501);

        $this->assertFalse($result);
    }
}
