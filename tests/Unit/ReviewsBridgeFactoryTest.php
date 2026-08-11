<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class ReviewsBridgeFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Module::$testInstalledModules = [];
        Module::$testEnabledModules = [];
    }

    public function testReturnsNullBridgeWhenRbreviewsAbsent(): void
    {
        $bridge = ReviewsBridgeFactory::create();

        $this->assertInstanceOf(NullReviewsBridge::class, $bridge);
        $this->assertFalse($bridge->isAvailable());
    }

    public function testReturnsNullBridgeWhenInstalledButDisabled(): void
    {
        Module::$testInstalledModules = ['rbreviews' => true];
        Module::$testEnabledModules = ['rbreviews' => false];

        $bridge = ReviewsBridgeFactory::create();

        $this->assertInstanceOf(NullReviewsBridge::class, $bridge);
    }

    public function testReturnsRealBridgeWhenRbreviewsInstalledAndEnabled(): void
    {
        Module::$testInstalledModules = ['rbreviews' => true];
        Module::$testEnabledModules = ['rbreviews' => true];

        $bridge = ReviewsBridgeFactory::create();

        $this->assertInstanceOf(RbReviewsBridge::class, $bridge);
        $this->assertTrue($bridge->isAvailable());
    }

    public function testNullBridgeThrowsOnAnyActionMethod(): void
    {
        $bridge = new NullReviewsBridge();

        $this->expectException(ReviewsUnavailableException::class);
        $bridge->getPendingReviews(20, 0, 1);
    }
}
