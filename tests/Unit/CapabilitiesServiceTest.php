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
        Configuration::$testValues = [];
    }

    protected function tearDown(): void
    {
        Configuration::$testValues = [];
        parent::tearDown();
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

    // =========================================================================
    // shipping_labels — reflète EXACTEMENT la condition de POST /orders/{id}/shipping-label
    // (module Colissimo installé+actif ET credentials configurés).
    // =========================================================================

    public function testShippingLabelsIsFalseWhenColissimoAbsent(): void
    {
        $capabilities = (new CapabilitiesService())->getCapabilities();

        $this->assertFalse($capabilities['shipping_labels']);
    }

    public function testShippingLabelsIsFalseWhenColissimoInstalledButDisabled(): void
    {
        Module::$testInstalledModules = ['colissimo' => true];
        Module::$testEnabledModules = ['colissimo' => false];
        Configuration::$testValues = [
            'COLISSIMO_CONNEXION_KEY' => '1',
            'COLISSIMO_ACCOUNT_KEY' => 'a-valid-key',
        ];

        $capabilities = (new CapabilitiesService())->getCapabilities();

        $this->assertFalse($capabilities['shipping_labels']);
    }

    public function testShippingLabelsIsFalseWhenActiveButCredentialsMissing(): void
    {
        Module::$testInstalledModules = ['colissimo' => true];
        Module::$testEnabledModules = ['colissimo' => true];
        // Ni mode clé, ni mode login/password : COLISSIMO_CONNEXION_KEY absent, login/password vides.

        $capabilities = (new CapabilitiesService())->getCapabilities();

        $this->assertFalse($capabilities['shipping_labels']);
    }

    public function testShippingLabelsIsTrueWithConnexionKeyMode(): void
    {
        Module::$testInstalledModules = ['colissimo' => true];
        Module::$testEnabledModules = ['colissimo' => true];
        Configuration::$testValues = [
            'COLISSIMO_CONNEXION_KEY' => '1',
            'COLISSIMO_ACCOUNT_KEY' => 'a-valid-key',
        ];

        $capabilities = (new CapabilitiesService())->getCapabilities();

        $this->assertTrue($capabilities['shipping_labels']);
    }

    public function testShippingLabelsIsTrueWithLoginPasswordMode(): void
    {
        Module::$testInstalledModules = ['colissimo' => true];
        Module::$testEnabledModules = ['colissimo' => true];
        Configuration::$testValues = [
            'COLISSIMO_ACCOUNT_LOGIN' => '123456',
            'COLISSIMO_ACCOUNT_PASSWORD' => 'secret',
        ];

        $capabilities = (new CapabilitiesService())->getCapabilities();

        $this->assertTrue($capabilities['shipping_labels']);
    }

    public function testShippingLabelsDoesNotLeakCredentialValues(): void
    {
        Module::$testInstalledModules = ['colissimo' => true];
        Module::$testEnabledModules = ['colissimo' => true];
        Configuration::$testValues = [
            'COLISSIMO_ACCOUNT_LOGIN' => '123456',
            'COLISSIMO_ACCOUNT_PASSWORD' => 'secret',
        ];

        $capabilities = (new CapabilitiesService())->getCapabilities();

        $this->assertIsBool($capabilities['shipping_labels']);
        $this->assertSame(['sav', 'reviews', 'shipping_labels'], array_keys($capabilities));
    }
}
