<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class CapabilitiesServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Module::$testInstalledModules = [];
        Module::$testEnabledModules = [];
    }

    public function testSavIsAlwaysTrue(): void
    {
        $capabilities = (new CapabilitiesService())->getCapabilities();

        $this->assertTrue($capabilities['sav'], 'sav est natif PrestaShop : toujours disponible.');
    }

    public function testReviewsIsFalseWhenRbreviewsAbsent(): void
    {
        $capabilities = (new CapabilitiesService())->getCapabilities();

        $this->assertFalse($capabilities['reviews']);
    }

    public function testReviewsIsFalseWhenInstalledButDisabled(): void
    {
        Module::$testInstalledModules = ['rbreviews' => true];
        Module::$testEnabledModules = ['rbreviews' => false];

        $capabilities = (new CapabilitiesService())->getCapabilities();

        $this->assertFalse($capabilities['reviews']);
    }

    public function testReviewsIsTrueWhenInstalledAndEnabled(): void
    {
        Module::$testInstalledModules = ['rbreviews' => true];
        Module::$testEnabledModules = ['rbreviews' => true];

        $capabilities = (new CapabilitiesService())->getCapabilities();

        $this->assertTrue($capabilities['reviews']);
    }

    public function testEvaluatedFreshOnEachCall(): void
    {
        $service = new CapabilitiesService();

        Module::$testInstalledModules = ['rbreviews' => true];
        Module::$testEnabledModules = ['rbreviews' => true];
        $this->assertTrue($service->getCapabilities()['reviews']);

        // Simule une désinstallation entre deux appels : pas de cache long côté service.
        Module::$testInstalledModules = ['rbreviews' => false];
        $this->assertFalse($service->getCapabilities()['reviews']);
    }
}
