<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class StockMovementServiceTest extends TestCase
{
    public function testRecordIfNeededSkipsAZeroDelta(): void
    {
        $service = new StockMovementService();

        $service->recordIfNeeded(900, 0, ['id' => 7, 'firstname' => 'Julie', 'lastname' => 'Bernard']);

        $this->assertSame([], StockMvt::$addCalls);
    }

    public function testRecordIfNeededSkipsWithoutAnIdentifiedStockAvailableRow(): void
    {
        $service = new StockMovementService();

        $service->recordIfNeeded(0, 5, ['id' => 7, 'firstname' => 'Julie', 'lastname' => 'Bernard']);

        $this->assertSame([], StockMvt::$addCalls);
    }

    public function testRecordIfNeededWritesAPositiveMovementWithTheConfiguredIncreaseReason(): void
    {
        Configuration::$testValues['PS_STOCK_MVT_INC_EMPLOYEE_EDITION'] = 11;

        $service = new StockMovementService();
        $service->recordIfNeeded(900, 6, ['id' => 7, 'firstname' => 'Julie', 'lastname' => 'Bernard']);

        $this->assertCount(1, StockMvt::$addCalls);
        $this->assertSame(900, StockMvt::$addCalls[0]['id_stock']);
        $this->assertSame(11, StockMvt::$addCalls[0]['id_stock_mvt_reason']);
        $this->assertSame(1, StockMvt::$addCalls[0]['sign']);
        $this->assertSame(6, StockMvt::$addCalls[0]['physical_quantity']);
        $this->assertSame(7, StockMvt::$addCalls[0]['id_employee']);
        $this->assertSame('Julie', StockMvt::$addCalls[0]['employee_firstname']);
        $this->assertSame('Bernard', StockMvt::$addCalls[0]['employee_lastname']);
    }

    public function testRecordIfNeededWritesANegativeMovementWithTheConfiguredDecreaseReason(): void
    {
        Configuration::$testValues['PS_STOCK_MVT_DEC_EMPLOYEE_EDITION'] = 12;

        $service = new StockMovementService();
        $service->recordIfNeeded(900, -4, ['id' => 7, 'firstname' => 'Julie', 'lastname' => 'Bernard']);

        $this->assertCount(1, StockMvt::$addCalls);
        $this->assertSame(12, StockMvt::$addCalls[0]['id_stock_mvt_reason']);
        $this->assertSame(-1, StockMvt::$addCalls[0]['sign']);
        $this->assertSame(4, StockMvt::$addCalls[0]['physical_quantity']);
    }

    public function testRecordIfNeededDoesNotWriteWhenTheCoreContainerIsAvailable(): void
    {
        \PrestaShop\PrestaShop\Adapter\SymfonyContainer::$testAvailable = true;

        try {
            $service = new StockMovementService();
            $service->recordIfNeeded(900, 5, ['id' => 7, 'firstname' => 'Julie', 'lastname' => 'Bernard']);

            $this->assertSame([], StockMvt::$addCalls, 'StockManager::saveMovement() du cœur a déjà écrit ce mouvement.');
        } finally {
            \PrestaShop\PrestaShop\Adapter\SymfonyContainer::$testAvailable = false;
        }
    }

    public function testRecordIfNeededDoesNotThrowWhenTheInsertFails(): void
    {
        // Un échec d'écriture du mouvement ne doit jamais remonter à l'appelant (la mise à jour du
        // stock elle-même est déjà appliquée) : seul un error_log() est attendu, pas d'exception.
        StockMvt::$addSucceeds = false;

        try {
            $service = new StockMovementService();
            $service->recordIfNeeded(900, 5, ['id' => 7, 'firstname' => 'Julie', 'lastname' => 'Bernard']);
            $this->assertSame([], StockMvt::$addCalls);
        } finally {
            StockMvt::$addSucceeds = true;
        }
    }

    protected function tearDown(): void
    {
        StockMvt::$addCalls = [];
        StockMvt::$addSucceeds = true;
        Configuration::$testValues = [];
        \PrestaShop\PrestaShop\Adapter\SymfonyContainer::$testAvailable = false;

        parent::tearDown();
    }
}
