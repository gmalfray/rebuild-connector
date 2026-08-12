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
        // "barcode" est accepté sans lever d'exception (construction de requête valide, jointures
        // product_attribute/product_attribute_shop combinaison-aware comprises).
        $products = $service->getProducts(['barcode' => '3760123456789']);

        $this->assertSame([], $products);
    }

    public function testCountProductsAcceptsBarcodeFilterWithoutError(): void
    {
        $service = new ProductsService();

        // Même jointure combinaison-aware que getProducts() : ne doit pas lever d'exception non plus.
        $total = $service->countProducts(['barcode' => '3760123456789']);

        $this->assertSame(0, $total);
    }

    /**
     * @dataProvider stockFilterProvider
     */
    public function testStockFilterRestrictsToActiveProducts(string $stockFilter): void
    {
        DbQuery::$testWhereLog = [];
        $service = new ProductsService();

        $service->getProducts(['stock' => $stockFilter]);

        $this->assertContains(
            'p.active = 1',
            DbQuery::$testWhereLog,
            "Le filtre stock '$stockFilter' doit être restreint aux produits actifs (p.active = 1)."
        );
    }

    /**
     * @dataProvider stockFilterProvider
     */
    public function testCountProductsStockFilterRestrictsToActiveProducts(string $stockFilter): void
    {
        DbQuery::$testWhereLog = [];
        $service = new ProductsService();

        $service->countProducts(['stock' => $stockFilter]);

        $this->assertContains(
            'p.active = 1',
            DbQuery::$testWhereLog,
            "countProducts avec le filtre '$stockFilter' doit être restreint aux actifs."
        );
    }

    /**
     * @return array<int, array{0: string}>
     */
    public function stockFilterProvider(): array
    {
        return [['in_stock'], ['out_of_stock'], ['low_stock']];
    }

    public function testNoStockFilterKeepsActiveAndInactiveProducts(): void
    {
        DbQuery::$testWhereLog = [];
        $service = new ProductsService();

        // « Tous » = pas de clé `stock` → aucune contrainte p.active forcée (actifs + inactifs).
        $service->getProducts([]);

        $this->assertNotContains(
            'p.active = 1',
            DbQuery::$testWhereLog,
            'Le filtre « Tous » ne doit PAS restreindre aux actifs (actifs + inactifs attendus).'
        );
    }

    public function testFormatProductRowExposesMatchedCombinationWhenBarcodeMatchesCombination(): void
    {
        // Cas pensebonheur : pelote de laine = combinaison "Coloris" du produit 52, l'EAN13 est posé
        // sur product_attribute (pa), pas sur product. La ligne SQL simulée ici reproduit ce que
        // ProductsService::joinProductAttributeForBarcode() ajoute au SELECT quand le filtre "barcode"
        // matche une déclinaison plutôt que le produit lui-même.
        $service = new ProductsService();
        $row = [
            'id_product' => 52,
            'name' => 'Fil Ricorumi réf.035 Bleu nuit',
            'reference' => 'RICO-035',
            'ean13' => '',
            'active' => 1,
            'date_upd' => '2025-06-01 12:00:00',
            'base_price' => 3.5,
            'quantity' => 0,
            'low_stock_threshold' => 5,
            'matched_id_product_attribute' => 7,
            'matched_pa_ean13' => '3760123456999',
            'matched_pa_reference' => 'RICO-035-BLEU',
        ];

        $method = new ReflectionMethod(ProductsService::class, 'formatProductRow');
        $method->setAccessible(true);
        /** @var array<string, mixed> $formatted */
        $formatted = $method->invoke($service, $row, 1, 1);

        $this->assertIsArray($formatted['matched_combination']);
        $this->assertSame(7, $formatted['matched_combination']['id']);
        $this->assertSame('3760123456999', $formatted['matched_combination']['ean13']);
        $this->assertSame('RICO-035-BLEU', $formatted['matched_combination']['reference']);
        // Stub Db::executeS() (phpstan-bootstrap.php) => pas de ligne attribute_lang/attribute_group_lang
        // résolvable hors base réelle : libellé vide, mais la clé est bien présente et bien construite.
        $this->assertSame('', $formatted['matched_combination']['name']);
        // Stub StockAvailable::getQuantityAvailableByProduct() => 0 par défaut.
        $this->assertSame(0, $formatted['matched_combination']['quantity']);
    }

    public function testFormatProductRowMatchedCombinationIsNullWithoutBarcodeSearch(): void
    {
        // Produit sans déclinaison (ou lecture hors filtre barcode, ex. GET /products/{id}) : la ligne
        // SQL ne contient pas les colonnes matched_id_product_attribute/matched_pa_* -> null attendu.
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

        $this->assertNull($formatted['matched_combination']);
    }

    public function testFormatProductRowIncludesCombinationsOnlyWhenIncludeCombinationsRequested(): void
    {
        // Cf. ProductsService::getProductCombinations() : simule product_attribute.ean13/.reference.
        Db::$testExecuteSResult = [
            ['id_product_attribute' => 7, 'ean13' => '3760123456999', 'reference' => 'RICO-035-BLEU'],
            ['id_product_attribute' => 8, 'ean13' => '', 'reference' => 'RICO-035-ROUGE'],
        ];

        $service = new ProductsService();
        $row = [
            'id_product' => 52,
            'name' => 'Fil Ricorumi réf.035',
            'reference' => 'RICO-035',
            'ean13' => '3760123450000',
            'active' => 1,
            'date_upd' => '2025-06-01 12:00:00',
            'base_price' => 3.5,
            'quantity' => 0,
            'low_stock_threshold' => 5,
        ];

        $method = new ReflectionMethod(ProductsService::class, 'formatProductRow');
        $method->setAccessible(true);

        /** @var array<string, mixed> $withoutBarcode */
        $withoutBarcode = $method->invoke($service, $row, 1, 1);
        $this->assertArrayNotHasKey('combinations', $withoutBarcode);

        /** @var array<string, mixed> $withBarcode */
        $withBarcode = $method->invoke($service, $row, 1, 1, true, '3760123450000');
        $this->assertArrayHasKey('combinations', $withBarcode);
        $this->assertCount(2, $withBarcode['combinations']);
        $this->assertSame(7, $withBarcode['combinations'][0]['id']);
        $this->assertSame('3760123456999', $withBarcode['combinations'][0]['ean13']);
        $this->assertSame('RICO-035-BLEU', $withBarcode['combinations'][0]['reference']);
        $this->assertSame(8, $withBarcode['combinations'][1]['id']);
    }

    public function testGetProductCombinationsMapsAllDeclinaisonsWithQuantity(): void
    {
        Db::$testExecuteSResult = [
            ['id_product_attribute' => 7, 'ean13' => '3760123456999', 'reference' => 'RICO-035-BLEU'],
        ];

        $service = new ProductsService();
        $method = new ReflectionMethod(ProductsService::class, 'getProductCombinations');
        $method->setAccessible(true);
        /** @var array<int, array<string, mixed>> $combinations */
        $combinations = $method->invoke($service, 52, 1, 1);

        $this->assertCount(1, $combinations);
        $this->assertSame(7, $combinations[0]['id']);
        $this->assertSame('3760123456999', $combinations[0]['ean13']);
        $this->assertSame('RICO-035-BLEU', $combinations[0]['reference']);
        // Stub StockAvailable::getQuantityAvailableByProduct() => 0 par défaut.
        $this->assertSame(0, $combinations[0]['quantity']);
    }

    public function testBuildMatchedCombinationTargetsSoleCombinationWhenBarcodeMatchesProductWithOneCombination(): void
    {
        // Cas pensebonheur fréquent : l'auto-association a posé l'EAN13 sur le PRODUIT (pas la
        // combinaison), mais le produit n'a qu'une seule déclinaison => pas d'ambiguïté, l'app ne doit
        // pas avoir à faire choisir l'utilisateur.
        $service = new ProductsService();
        $row = [
            'id_product' => 52,
            'ean13' => '3760123450000',
            'reference' => 'RICO-035',
        ];
        $combinations = [
            ['id' => 7, 'name' => 'Coloris - Bleu nuit', 'ean13' => '', 'reference' => 'RICO-035-BLEU', 'quantity' => 12],
        ];

        $method = new ReflectionMethod(ProductsService::class, 'buildMatchedCombination');
        $method->setAccessible(true);
        $result = $method->invoke($service, $row, 1, '3760123450000', $combinations);

        $this->assertSame($combinations[0], $result);
    }

    public function testBuildMatchedCombinationIsNullWhenBarcodeMatchesProductWithMultipleCombinations(): void
    {
        // Ambigu (≥ 2 déclinaisons) : l'app doit laisser choisir via le champ `combinations`, le module
        // ne doit pas trancher à sa place.
        $service = new ProductsService();
        $row = [
            'id_product' => 52,
            'ean13' => '3760123450000',
            'reference' => 'RICO-035',
        ];
        $combinations = [
            ['id' => 7, 'name' => 'Coloris - Bleu', 'ean13' => '', 'reference' => 'A', 'quantity' => 5],
            ['id' => 8, 'name' => 'Coloris - Rouge', 'ean13' => '', 'reference' => 'B', 'quantity' => 3],
        ];

        $method = new ReflectionMethod(ProductsService::class, 'buildMatchedCombination');
        $method->setAccessible(true);
        $result = $method->invoke($service, $row, 1, '3760123450000', $combinations);

        $this->assertNull($result);
    }

    public function testBuildMatchedCombinationIsNullWhenProductHasNoCombination(): void
    {
        // Rétrocompat : produit sans déclinaison, comportement historique inchangé.
        $service = new ProductsService();
        $row = [
            'id_product' => 88,
            'ean13' => '3760123456789',
            'reference' => 'TSHIRT-BLACK',
        ];

        $method = new ReflectionMethod(ProductsService::class, 'buildMatchedCombination');
        $method->setAccessible(true);
        $result = $method->invoke($service, $row, 1, '3760123456789', []);

        $this->assertNull($result);
    }

    /**
     * Simule product_shop.id_product trouvé pour CE produit + CETTE boutique (m1, protection IDOR
     * multiboutique : ProductsService::productBelongsToShop(), qui passe par Db::executeS() —
     * délibérément DISTINCT de Db::$testGetValueResult utilisé par combinationBelongsToProduct(),
     * pour pouvoir simuler indépendamment « boutique OK / combinaison KO » et inversement.
     */
    private function simulateProductBelongsToShop(): void
    {
        Db::$testExecuteSResult = [['id_product' => 88]];
    }

    public function testUpdateStockWritesAtProductLevelByDefault(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        $result = $service->updateStock(88, 10);

        $this->assertTrue($result);
        // id_product_attribute = 0 : comportement historique inchangé pour un produit sans déclinaison.
        $this->assertSame([[88, 0, 10]], StockAvailable::$setQuantityCalls);
    }

    public function testUpdateStockRejectsProductForeignToShop(): void
    {
        // m1 : Db::$testExecuteSResult reste à son défaut ([]) : simule un produit qui n'est pas
        // associé à la boutique courante (product_shop sans ligne pour ce couple produit/boutique) —
        // protection IDOR multiboutique, l'écriture doit être refusée.
        $service = new ProductsService();
        $result = $service->updateStock(88, 10);

        $this->assertFalse($result);
        $this->assertSame([], StockAvailable::$setQuantityCalls);
    }

    public function testUpdateStockWritesAtCombinationLevelWhenCombinationBelongsToProduct(): void
    {
        $this->simulateProductBelongsToShop();
        // Simule product_attribute.id_product_attribute trouvé en base pour CE produit
        // (cf. ProductsService::combinationBelongsToProduct(), qui passe par Db::getValue()).
        Db::$testGetValueResult = 501;

        $service = new ProductsService();
        $result = $service->updateStock(88, 10, 501);

        $this->assertTrue($result);
        $this->assertSame([[88, 501, 10]], StockAvailable::$setQuantityCalls);
    }

    public function testUpdateStockRejectsCombinationIdForeignToProduct(): void
    {
        $this->simulateProductBelongsToShop();
        // Db::$testGetValueResult reste à son défaut (0) : simule un combination_id qui n'appartient PAS
        // au produit ciblé (product_attribute.id_product != productId côté base réelle).
        $service = new ProductsService();
        $result = $service->updateStock(88, 10, 999);

        $this->assertFalse($result);
        // Rejeté AVANT tout appel StockAvailable::setQuantity() : le stock existant n'est pas touché.
        $this->assertSame([], StockAvailable::$setQuantityCalls);
    }

    public function testUpdateStockWithoutEmployeeIdentityWritesNoStockMovement(): void
    {
        // Comportement historique de updateStock(88, 10) sans 4e argument (cf. tests ci-dessus) :
        // aucune trace de mouvement n'est tentée quand l'appelant ne fournit pas d'auteur, plutôt
        // que d'attribuer le mouvement à un employé arbitraire.
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        $service->updateStock(88, 10);

        $this->assertSame([], StockMvt::$addCalls);
    }

    public function testUpdateStockRecordsMovementReflectingTheAbsoluteDeltaApplied(): void
    {
        $this->simulateProductBelongsToShop();
        Configuration::$testValues['PS_STOCK_MVT_INC_EMPLOYEE_EDITION'] = 11;
        StockAvailable::$testQuantityAvailableResult = 4; // quantité avant écriture
        StockAvailable::$testStockAvailableId = 900;

        $service = new ProductsService();
        $employee = ['id' => 7, 'firstname' => 'Julie', 'lastname' => 'Bernard'];
        $service->updateStock(88, 10, 0, $employee);

        // 10 (nouvelle quantité absolue) - 4 (quantité précédente) = +6.
        $this->assertCount(1, StockMvt::$addCalls);
        $this->assertSame(900, StockMvt::$addCalls[0]['id_stock']);
        $this->assertSame(11, StockMvt::$addCalls[0]['id_stock_mvt_reason']);
        $this->assertSame(1, StockMvt::$addCalls[0]['sign']);
        $this->assertSame(6, StockMvt::$addCalls[0]['physical_quantity']);
        $this->assertSame(7, StockMvt::$addCalls[0]['id_employee']);
        $this->assertSame('Julie', StockMvt::$addCalls[0]['employee_firstname']);
        $this->assertSame('Bernard', StockMvt::$addCalls[0]['employee_lastname']);
    }

    public function testUpdateStockDoesNotRecordMovementWhenAbsoluteQuantityEqualsCurrentStock(): void
    {
        // Delta nul (la quantité envoyée est déjà la quantité en base) : rien à tracer, exactement
        // comme le fait StockManager::saveMovement() du cœur pour un delta à 0.
        $this->simulateProductBelongsToShop();
        StockAvailable::$testQuantityAvailableResult = 10;

        $service = new ProductsService();
        $service->updateStock(88, 10, 0, ['id' => 7, 'firstname' => 'Julie', 'lastname' => 'Bernard']);

        $this->assertSame([], StockMvt::$addCalls);
    }

    public function testApplyStockDeltaAppliesPositiveDeltaOnTopOfTheLockedCurrentQuantity(): void
    {
        $this->simulateProductBelongsToShop();
        StockAvailable::$testStockAvailableId = 900;
        Db::$testLockedStockRows = [['quantity' => 10]];

        $service = new ProductsService();
        $result = $service->applyStockDelta(88, 5);

        $this->assertSame(15, $result);
        $this->assertSame([[88, 0, 15]], StockAvailable::$setQuantityCalls);
    }

    public function testApplyStockDeltaAppliesNegativeDelta(): void
    {
        $this->simulateProductBelongsToShop();
        StockAvailable::$testStockAvailableId = 900;
        Db::$testLockedStockRows = [['quantity' => 10]];

        $service = new ProductsService();
        $result = $service->applyStockDelta(88, -3);

        $this->assertSame(7, $result);
        $this->assertSame([[88, 0, 7]], StockAvailable::$setQuantityCalls);
    }

    public function testApplyStockDeltaAllowsTheResultToGoBelowZero(): void
    {
        // Décision assumée (voir docblock d'applyStockDelta()) : ni clamp à zéro, ni rejet. Un
        // delta négatif plus grand que le stock courant produit une quantité négative, renvoyée
        // telle quelle, comme le permet déjà le mode quantité absolue de ce même endpoint.
        $this->simulateProductBelongsToShop();
        StockAvailable::$testStockAvailableId = 900;
        Db::$testLockedStockRows = [['quantity' => 2]];

        $service = new ProductsService();
        $result = $service->applyStockDelta(88, -5);

        $this->assertSame(-3, $result);
        $this->assertSame([[88, 0, -3]], StockAvailable::$setQuantityCalls);
    }

    public function testApplyStockDeltaRejectsProductForeignToShop(): void
    {
        // m1 : Db::$testExecuteSResult reste à son défaut ([]) : produit non associé à la boutique.
        $service = new ProductsService();
        $result = $service->applyStockDelta(88, 5);

        $this->assertNull($result);
        $this->assertSame([], StockAvailable::$setQuantityCalls);
    }

    public function testApplyStockDeltaRejectsCombinationIdForeignToProduct(): void
    {
        $this->simulateProductBelongsToShop();
        // Db::$testGetValueResult reste à son défaut (0) : combination_id qui n'appartient pas au produit.
        $service = new ProductsService();
        $result = $service->applyStockDelta(88, 5, 999);

        $this->assertNull($result);
        $this->assertSame([], StockAvailable::$setQuantityCalls);
    }

    public function testApplyStockDeltaWritesAtCombinationLevelWhenCombinationBelongsToProduct(): void
    {
        $this->simulateProductBelongsToShop();
        Db::$testGetValueResult = 501;
        StockAvailable::$testStockAvailableId = 900;
        Db::$testLockedStockRows = [['quantity' => 20]];

        $service = new ProductsService();
        $result = $service->applyStockDelta(88, 3, 501);

        $this->assertSame(23, $result);
        $this->assertSame([[88, 501, 23]], StockAvailable::$setQuantityCalls);
    }

    public function testApplyStockDeltaRecordsPositiveMovementWithTheConfiguredIncreaseReason(): void
    {
        $this->simulateProductBelongsToShop();
        StockAvailable::$testStockAvailableId = 900;
        Db::$testLockedStockRows = [['quantity' => 10]];
        Configuration::$testValues['PS_STOCK_MVT_INC_EMPLOYEE_EDITION'] = 11;

        $service = new ProductsService();
        $service->applyStockDelta(88, 5, 0, ['id' => 7, 'firstname' => 'Julie', 'lastname' => 'Bernard']);

        $this->assertCount(1, StockMvt::$addCalls);
        $this->assertSame(900, StockMvt::$addCalls[0]['id_stock']);
        $this->assertSame(11, StockMvt::$addCalls[0]['id_stock_mvt_reason']);
        $this->assertSame(1, StockMvt::$addCalls[0]['sign']);
        $this->assertSame(5, StockMvt::$addCalls[0]['physical_quantity']);
        $this->assertSame(7, StockMvt::$addCalls[0]['id_employee']);
    }

    public function testApplyStockDeltaRecordsNegativeMovementWithTheConfiguredDecreaseReason(): void
    {
        $this->simulateProductBelongsToShop();
        StockAvailable::$testStockAvailableId = 900;
        Db::$testLockedStockRows = [['quantity' => 10]];
        Configuration::$testValues['PS_STOCK_MVT_DEC_EMPLOYEE_EDITION'] = 12;

        $service = new ProductsService();
        $service->applyStockDelta(88, -4, 0, ['id' => 7, 'firstname' => 'Julie', 'lastname' => 'Bernard']);

        $this->assertCount(1, StockMvt::$addCalls);
        $this->assertSame(12, StockMvt::$addCalls[0]['id_stock_mvt_reason']);
        $this->assertSame(-1, StockMvt::$addCalls[0]['sign']);
        $this->assertSame(4, StockMvt::$addCalls[0]['physical_quantity']);
    }

    public function testApplyStockDeltaRecordsMovementOnTheTargetedCombination(): void
    {
        $this->simulateProductBelongsToShop();
        Db::$testGetValueResult = 501;
        StockAvailable::$testStockAvailableId = 777;
        Db::$testLockedStockRows = [['quantity' => 1]];

        $service = new ProductsService();
        $service->applyStockDelta(88, 2, 501, ['id' => 7, 'firstname' => 'Julie', 'lastname' => 'Bernard']);

        $this->assertCount(1, StockMvt::$addCalls);
        // id_stock référence la ligne stock_available de LA DÉCLINAISON (777), pas celle du produit.
        $this->assertSame(777, StockMvt::$addCalls[0]['id_stock']);
    }

    public function testApplyStockDeltaDoesNotWriteAMovementTwiceWhenTheCoreContainerIsAvailable(): void
    {
        // Contexte où StockManager::saveMovement() du cœur a pu écrire lui-même le mouvement
        // (conteneur Symfony disponible) : le module ne doit pas en écrire un second.
        $this->simulateProductBelongsToShop();
        StockAvailable::$testStockAvailableId = 900;
        Db::$testLockedStockRows = [['quantity' => 10]];
        \PrestaShop\PrestaShop\Adapter\SymfonyContainer::$testAvailable = true;

        try {
            $service = new ProductsService();
            $result = $service->applyStockDelta(88, 5, 0, ['id' => 7, 'firstname' => 'Julie', 'lastname' => 'Bernard']);

            $this->assertSame(15, $result, 'La quantité reste appliquée même quand le module ne trace pas le mouvement.');
            $this->assertSame([], StockMvt::$addCalls);
        } finally {
            \PrestaShop\PrestaShop\Adapter\SymfonyContainer::$testAvailable = false;
        }
    }

    public function testUpdateProductAcceptsValidEan13(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['ean13' => '3760123456789']);

        $this->assertTrue($result);
    }

    public function testUpdateProductRejectsProductForeignToShop(): void
    {
        // m1 : Db::$testExecuteSResult reste à son défaut ([]) : produit non associé à la boutique
        // courante → refus, même avec un payload par ailleurs valide.
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['ean13' => '3760123456789']);

        $this->assertFalse($result);
    }

    public function testUpdateProductAcceptsEmptyEan13ToClearIt(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['ean13' => '']);

        $this->assertTrue($result);
    }

    public function testUpdateProductRejectsNonNumericEan13(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['ean13' => 'ABC123']);

        $this->assertFalse($result);
    }

    public function testUpdateProductRejectsTooLongEan13(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['ean13' => '12345678901234']);

        $this->assertFalse($result);
    }

    public function testUpdateProductRejectsNonStringEan13(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        /** @phpstan-ignore-next-line argument.type (payload volontairement mal typé pour le test) */
        $result = $service->updateProduct(88, ['ean13' => 12345]);

        $this->assertFalse($result);
    }

    public function testUpdateProductAcceptsValidName(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['name' => 'T-shirt noir']);

        $this->assertTrue($result);
    }

    public function testUpdateProductRejectsEmptyName(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['name' => '   ']);

        $this->assertFalse($result);
    }

    public function testUpdateProductRejectsNonStringName(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        /** @phpstan-ignore-next-line argument.type (payload volontairement mal typé pour le test) */
        $result = $service->updateProduct(88, ['name' => 12345]);

        $this->assertFalse($result);
    }

    public function testUpdateProductAcceptsValidDescription(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['description' => '<p>Un joli t-shirt en coton bio.</p>']);

        $this->assertTrue($result);
    }

    public function testUpdateProductAcceptsEmptyDescriptionToClearIt(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['description' => '']);

        $this->assertTrue($result);
    }

    public function testUpdateProductRejectsUnsafeDescriptionHtml(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['description' => '<script>alert(1)</script>']);

        $this->assertFalse($result);
    }

    public function testUpdateProductAcceptsValidDescriptionShort(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['description_short' => '<p>Résumé court.</p>']);

        $this->assertTrue($result);
    }

    public function testUpdateProductRejectsUnsafeDescriptionShortHtml(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['description_short' => '<iframe src="evil"></iframe>']);

        $this->assertFalse($result);
    }

    public function testUpdateProductAcceptsValidReference(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['reference' => 'TSHIRT-BLACK-2']);

        $this->assertTrue($result);
    }

    public function testUpdateProductAcceptsEmptyReferenceToClearIt(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['reference' => '']);

        $this->assertTrue($result);
    }

    public function testUpdateProductRejectsTooLongReference(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        $result = $service->updateProduct(88, ['reference' => str_repeat('A', 65)]);

        $this->assertFalse($result);
    }

    public function testUpdateProductRejectsNonStringReference(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        /** @phpstan-ignore-next-line argument.type (payload volontairement mal typé pour le test) */
        $result = $service->updateProduct(88, ['reference' => 12345]);

        $this->assertFalse($result);
    }

    public function testUpdateProductWritesEan13OnCombinationNotProductWhenCombinationIdProvided(): void
    {
        $this->simulateProductBelongsToShop();
        // Simule product_attribute.id_product_attribute trouvé en base pour CE produit (combinationBelongsToProduct()).
        Db::$testGetValueResult = 501;

        $service = new ProductsService();
        $result = $service->updateProduct(88, ['ean13' => '3760123456789', 'combination_id' => 501]);

        $this->assertTrue($result);
        // L'EAN13 est écrit sur la déclinaison (Combination::update()), pas sur le produit.
        $this->assertSame([['id_product_attribute' => 501, 'ean13' => '3760123456789']], Combination::$updateCalls);
    }

    public function testUpdateProductRejectsEan13WithForeignCombinationId(): void
    {
        $this->simulateProductBelongsToShop();
        // Db::$testGetValueResult reste à son défaut (0) : simule un combination_id qui n'appartient PAS
        // au produit ciblé.
        $service = new ProductsService();
        $result = $service->updateProduct(88, ['ean13' => '3760123456789', 'combination_id' => 999]);

        $this->assertFalse($result);
        $this->assertSame([], Combination::$updateCalls);
    }

    public function testUpdateProductRejectsNonNumericCombinationId(): void
    {
        $this->simulateProductBelongsToShop();
        $service = new ProductsService();

        /** @phpstan-ignore-next-line argument.type (payload volontairement mal typé pour le test) */
        $result = $service->updateProduct(88, ['ean13' => '3760123456789', 'combination_id' => 'abc']);

        $this->assertFalse($result);
        $this->assertSame([], Combination::$updateCalls);
    }

    public function testUpdateProductAcceptsMultipleSimpleFieldsAtOnce(): void
    {
        $this->simulateProductBelongsToShop();
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
        // Les bascules de test des stubs (phpstan-bootstrap.php) sont globales (static) :
        // on les remet à leur valeur par défaut pour ne pas polluer les autres tests.
        ImageManager::$resizeSucceeds = true;
        Db::$testGetValueResult = 0;
        Db::$testExecuteSResult = [];
        Db::$testLockedStockRows = [];
        StockAvailable::$setQuantityCalls = [];
        StockAvailable::$testStockAvailableId = 0;
        StockAvailable::$testQuantityAvailableResult = 0;
        Combination::$updateCalls = [];
        StockMvt::$addCalls = [];
        StockMvt::$addSucceeds = true;
        Configuration::$testValues = [];
        \PrestaShop\PrestaShop\Adapter\SymfonyContainer::$testAvailable = false;

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
