<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Tests du callback public `hubkey` : livraison signée d'une nouvelle clé de licence par le hub
 * push (récupération self-service, preuve de contrôle du domaine — rebuild-it/docs/push-recover.md).
 *
 * Tools::getShopDomainSsl() (stub) renvoie toujours 'https://example.com' dans cet environnement
 * de test : le shop_url "réel" attendu par le contrôleur est donc systématiquement cette valeur.
 */
final class HubkeyControllerTest extends TestCase
{
    private static string $privateKeyPem;
    private static string $publicKeyPem;

    public static function setUpBeforeClass(): void
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($resource, $privateKeyPem);
        self::$privateKeyPem = $privateKeyPem;

        $details = openssl_pkey_get_details($resource);
        self::$publicKeyPem = $details['key'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
    }

    private function sign(string $payloadJson): string
    {
        openssl_sign($payloadJson, $signature, self::$privateKeyPem, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    private function makeController(string $payloadJson, string $signatureB64): TestHubkeyController
    {
        $controller = new TestHubkeyController(self::$publicKeyPem);
        $controller->requestBody = ['payload' => $payloadJson, 'signature' => $signatureB64];

        return $controller;
    }

    public function testRejectsNonPostMethod(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $controller = new TestHubkeyController(self::$publicKeyPem);
        $controller->initContent();

        $this->assertSame(405, $controller->response['status']);
        $this->assertSame('method_not_allowed', $controller->response['payload']['error']);
    }

    public function testRejectsMissingEnvelopeFields(): void
    {
        $controller = new TestHubkeyController(self::$publicKeyPem);
        $controller->requestBody = ['payload' => 'only-payload'];
        $controller->initContent();

        $this->assertSame(400, $controller->response['status']);
        $this->assertSame('invalid_request', $controller->response['payload']['error']);
    }

    public function testAcceptsValidCallbackAndStoresLicenseKey(): void
    {
        $issuedAt = gmdate('Y-m-d\TH:i:s\Z');
        $payload = json_encode([
            'shop_url' => 'https://example.com',
            'license_key' => 'rbk_recovered_secret',
            'issued_at' => $issuedAt,
        ]);
        $controller = $this->makeController($payload, $this->sign($payload));

        $controller->initContent();

        $this->assertSame(200, $controller->response['status']);
        $this->assertSame(['ok' => true], $controller->response['payload']);
        $this->assertSame('rbk_recovered_secret', $controller->settingsService->getHubLicenseKey());
    }

    public function testRejectsInvalidSignatureAndStoresNothing(): void
    {
        $issuedAt = gmdate('Y-m-d\TH:i:s\Z');
        $payload = json_encode([
            'shop_url' => 'https://example.com',
            'license_key' => 'rbk_should_not_be_stored',
            'issued_at' => $issuedAt,
        ]);
        // Signature d'un AUTRE payload : invalide pour celui-ci.
        $otherSignature = $this->sign('{"shop_url":"https://other.example","license_key":"x","issued_at":"' . $issuedAt . '"}');

        $controller = $this->makeController($payload, $otherSignature);
        $controller->initContent();

        $this->assertSame(401, $controller->response['status']);
        $this->assertSame('invalid_signature', $controller->response['payload']['error']);
        $this->assertSame('', $controller->settingsService->getHubLicenseKey());
    }

    public function testRejectsDomainMismatchAndStoresNothing(): void
    {
        $issuedAt = gmdate('Y-m-d\TH:i:s\Z');
        $payload = json_encode([
            'shop_url' => 'https://attacker.example', // ≠ Tools::getShopDomainSsl() stub
            'license_key' => 'rbk_should_not_be_stored',
            'issued_at' => $issuedAt,
        ]);
        $controller = $this->makeController($payload, $this->sign($payload));

        $controller->initContent();

        $this->assertSame(400, $controller->response['status']);
        $this->assertSame('domain_mismatch', $controller->response['payload']['error']);
        $this->assertSame('', $controller->settingsService->getHubLicenseKey());
    }

    public function testRejectsStalePayloadAndStoresNothing(): void
    {
        $staleIssuedAt = gmdate('Y-m-d\TH:i:s\Z', time() - 3600); // 1h dans le passé
        $payload = json_encode([
            'shop_url' => 'https://example.com',
            'license_key' => 'rbk_should_not_be_stored',
            'issued_at' => $staleIssuedAt,
        ]);
        $controller = $this->makeController($payload, $this->sign($payload));

        $controller->initContent();

        $this->assertSame(400, $controller->response['status']);
        $this->assertSame('stale_payload', $controller->response['payload']['error']);
        $this->assertSame('', $controller->settingsService->getHubLicenseKey());
    }

    public function testRejectsMalformedPayloadEvenWithValidSignature(): void
    {
        $payload = '{"shop_url":"https://example.com"}'; // license_key/issued_at manquants
        $controller = $this->makeController($payload, $this->sign($payload));

        $controller->initContent();

        $this->assertSame(400, $controller->response['status']);
        $this->assertSame('invalid_payload', $controller->response['payload']['error']);
    }

    public function testRejectsInvalidJsonBody(): void
    {
        $controller = new TestHubkeyController(self::$publicKeyPem);
        $controller->rawBodyOverride = 'not-json-at-all';
        $controller->initContent();

        $this->assertSame(400, $controller->response['status']);
        $this->assertSame('invalid_request', $controller->response['payload']['error']);
    }
}

/**
 * Double de test : injecte une clé publique de test (paire RSA jetable) au lieu de la clé réelle
 * du hub, capture la réponse JSON au lieu de l'émettre en HTTP, et permet de piloter le corps de
 * requête décodé (Tools::file_get_contents() est stubbé à '' en environnement de test).
 */
final class TestHubkeyController extends RebuildconnectorHubkeyModuleFrontController
{
    /** @var array<string, mixed>|null */
    public ?array $response = null;
    /** @var array<string, mixed> */
    public array $requestBody = [];
    public ?string $rawBodyOverride = null;
    public SettingsService $settingsService;

    private HubKeyVerifier $verifierOverride;

    public function __construct(string $publicKeyPem)
    {
        $this->verifierOverride = new HubKeyVerifier($publicKeyPem);
        $this->settingsService = new SettingsService();
    }

    protected function renderJson(array $payload, int $statusCode = 200): void
    {
        $this->response = [
            'status' => $statusCode,
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeRequestBody(): array
    {
        if ($this->rawBodyOverride !== null) {
            $decoded = json_decode($this->rawBodyOverride, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('Request body must be valid JSON.');
            }

            return $decoded;
        }

        return $this->requestBody;
    }

    protected function getSettingsService(): SettingsService
    {
        return $this->settingsService;
    }

    protected function recordAuditEvent(string $event, array $context = []): void
    {
        // No-op : évite toute dépendance à AuditLogService/Db dans ce test unitaire ciblé.
    }

    protected function getVerifier(): HubKeyVerifier
    {
        return $this->verifierOverride;
    }
}
