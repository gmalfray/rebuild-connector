<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class CapabilitiesControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER = ['REQUEST_METHOD' => 'GET'];
        $_GET = [];
        Module::$testInstalledModules = [];
        Module::$testEnabledModules = [];
        Configuration::$testValues = [];
    }

    protected function tearDown(): void
    {
        Configuration::$testValues = [];
        parent::tearDown();
    }

    public function testMethodNotAllowedForPost(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST'];

        $controller = new TestCapabilitiesController();
        $controller->initContent();

        $this->assertSame(405, $controller->response['status']);
        $this->assertSame('method_not_allowed', $controller->response['payload']['error']);
    }

    public function testReturnsFlatCapabilitiesObjectNoWrapperKey(): void
    {
        $controller = new TestCapabilitiesController();
        $controller->initContent();

        $this->assertSame(200, $controller->response['status']);
        // Enveloppe réelle : {"reviews": bool, "sav": bool, "shipping_labels": bool} — pas
        // {"capabilities": {...}}.
        $this->assertArrayHasKey('reviews', $controller->response['payload']);
        $this->assertArrayHasKey('sav', $controller->response['payload']);
        $this->assertArrayHasKey('shipping_labels', $controller->response['payload']);
        $this->assertTrue($controller->response['payload']['sav']);
    }

    public function testReviewsReflectsModuleState(): void
    {
        Module::$testInstalledModules = ['rbreviews' => true];
        Module::$testEnabledModules = ['rbreviews' => true];

        $controller = new TestCapabilitiesController();
        $controller->initContent();

        $this->assertTrue($controller->response['payload']['reviews']);
    }

    public function testShippingLabelsFalseByDefault(): void
    {
        $controller = new TestCapabilitiesController();
        $controller->initContent();

        $this->assertFalse($controller->response['payload']['shipping_labels']);
    }

    public function testShippingLabelsReflectsColissimoConfiguration(): void
    {
        Module::$testInstalledModules = ['colissimo' => true];
        Module::$testEnabledModules = ['colissimo' => true];
        Configuration::$testValues = [
            'COLISSIMO_CONNEXION_KEY' => '1',
            'COLISSIMO_ACCOUNT_KEY' => 'a-valid-key',
        ];

        $controller = new TestCapabilitiesController();
        $controller->initContent();

        $this->assertTrue($controller->response['payload']['shipping_labels']);
    }
}

final class TestCapabilitiesController extends RebuildconnectorCapabilitiesModuleFrontController
{
    /** @var array<string, mixed>|null */
    public ?array $response = null;

    public function __construct()
    {
        parent::__construct();
    }

    protected function renderJson(array $payload, int $statusCode = 200): void
    {
        $this->response = ['status' => $statusCode, 'payload' => $payload];
    }

    protected function jsonError(string $error, string $message, int $statusCode): void
    {
        $this->renderJson(['error' => $error, 'message' => $message], $statusCode);
    }

    protected function requireAuth(array $requiredScopes = []): array
    {
        return ['scopes' => $requiredScopes, 'sub' => 'test'];
    }
}
