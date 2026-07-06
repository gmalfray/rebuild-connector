<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class PushHubServiceTest extends TestCase
{
    public function testIsDisabledWhenKeyMissing(): void
    {
        // Hub-only : l'URL est hardcodée, seule la clé de licence active le hub.
        $this->assertFalse((new PushHubService(new HubSettingsStub('')))->isEnabled());
    }

    public function testIsEnabledWhenKeyPresent(): void
    {
        $service = new PushHubService(new HubSettingsStub('rbk_secret'));
        $this->assertTrue($service->isEnabled());
    }

    public function testNotifyDoesNothingWhenDisabled(): void
    {
        $service = new TestPushHubService(new HubSettingsStub(''), null);
        $this->assertFalse($service->notify('order.created', ['title' => 'A', 'body' => 'B']));
        $this->assertSame([], $service->calls);
    }

    public function testNotifyBuildsExpectedPayload(): void
    {
        $service = new TestPushHubService(
            new HubSettingsStub('rbk_secret'),
            ['sent' => 1]
        );

        $result = $service->notify(
            'order.created',
            ['title' => 'Nouvelle commande', 'body' => '#000123'],
            ['event' => 'order.created', 'order_id' => 123]
        );

        $this->assertTrue($result);
        $this->assertCount(1, $service->calls);
        [$method, $path, $body] = $service->calls[0];
        $this->assertSame('POST', $method);
        $this->assertSame('/v1/notify', $path);
        $this->assertSame('order.created', $body['event']);
        $this->assertSame('Nouvelle commande', $body['title']);
        $this->assertSame('#000123', $body['body']);
        // Les data sont normalisées en chaînes (contrat FCM).
        $this->assertSame('123', $body['data']['order_id']);
    }

    public function testNotifyReturnsFalseWhenHubFails(): void
    {
        $service = new TestPushHubService(
            new HubSettingsStub('rbk_secret'),
            null
        );

        $this->assertFalse($service->notify('order.created', ['title' => 'A', 'body' => 'B']));
    }

    public function testRegisterDeviceSendsTokenAndTopics(): void
    {
        $service = new TestPushHubService(
            new HubSettingsStub('rbk_secret'),
            ['registered' => true]
        );

        $service->registerDevice('tok-123', 'android', ['order.created']);

        [$method, $path, $body] = $service->calls[0];
        $this->assertSame('POST', $method);
        $this->assertSame('/v1/devices', $path);
        $this->assertSame('tok-123', $body['fcm_token']);
        $this->assertSame('android', $body['platform']);
        $this->assertSame(['order.created'], $body['topics']);
    }

    public function testUnregisterDeviceUsesDeleteWithEncodedToken(): void
    {
        $service = new TestPushHubService(
            new HubSettingsStub('rbk_secret'),
            []
        );

        $service->unregisterDevice('tok/with space');

        [$method, $path] = $service->calls[0];
        $this->assertSame('DELETE', $method);
        $this->assertSame('/v1/devices/tok%2Fwith%20space', $path);
    }

    // ──────────────────────────────────────────────────────────────────
    // Auto-provisionnement de licence (endpoint public /v1/licenses/provision)
    // ──────────────────────────────────────────────────────────────────

    public function testProvisionLicenseReturnsKeyOn201(): void
    {
        $service = new ProvisionTestPushHubService(
            new HubSettingsStub(''), // aucune clé configurée : c'est justement le cas d'usage
            ['status' => 201, 'body' => [
                'provisioned' => true,
                'id' => 'lic_1',
                'license_key' => 'rbk_freshly_provisioned',
                'status' => 'trial',
            ]]
        );

        $key = $service->provisionLicense('https://shop.example.com', 'Ma Boutique');

        $this->assertSame('rbk_freshly_provisioned', $key);
        $this->assertCount(1, $service->calls);
        [$method, $path, $body, $authenticated] = $service->calls[0];
        $this->assertSame('POST', $method);
        $this->assertSame('/v1/licenses/provision', $path);
        $this->assertSame('https://shop.example.com', $body['shop_url']);
        $this->assertSame('Ma Boutique', $body['label']);
        // Endpoint public du hub : aucune authentification, même si la clé était configurée.
        $this->assertFalse($authenticated);
    }

    public function testProvisionLicenseWorksEvenWhenHubDisabled(): void
    {
        // Contrairement à notify()/registerDevice(), provisionLicense() ne doit PAS exiger
        // isEnabled() : c'est précisément l'outil pour sortir de l'état "hub désactivé".
        $service = new ProvisionTestPushHubService(
            new HubSettingsStub(''),
            ['status' => 201, 'body' => ['license_key' => 'rbk_zero_config']]
        );

        $this->assertFalse($service->isEnabled());
        $this->assertSame('rbk_zero_config', $service->provisionLicense('https://shop.example.com'));
    }

    public function testProvisionLicenseReturnsNullOn409AlreadyExists(): void
    {
        $service = new ProvisionTestPushHubService(
            new HubSettingsStub(''),
            ['status' => 409, 'body' => [
                'provisioned' => false,
                'reason' => 'already_exists',
                'id' => 'lic_1',
                'status' => 'active',
            ]]
        );

        $result = $service->provisionLicenseDetailed('https://shop.example.com');

        $this->assertFalse($result['provisioned']);
        $this->assertSame('already_exists', $result['reason']);
        $this->assertNull($result['license_key']);
        // Le hub ne renvoie jamais la clé sur 409 (secret one-time) : provisionLicense() reste null.
        $this->assertNull($service->provisionLicense('https://shop.example.com'));
    }

    public function testProvisionLicenseReturnsNullOnNetworkError(): void
    {
        // performRequest() renvoie status = 0 en cas d'échec cURL/timeout (cf. PushHubService::performRequest).
        $service = new ProvisionTestPushHubService(
            new HubSettingsStub(''),
            ['status' => 0, 'body' => []]
        );

        $this->assertNull($service->provisionLicense('https://shop.example.com'));
    }

    public function testProvisionLicenseReturnsNullOnUnexpectedHttpError(): void
    {
        $service = new ProvisionTestPushHubService(
            new HubSettingsStub(''),
            ['status' => 500, 'body' => []]
        );

        $this->assertNull($service->provisionLicense('https://shop.example.com'));
    }

    public function testProvisionLicenseReturnsNullWithoutCallWhenShopUrlEmpty(): void
    {
        $service = new ProvisionTestPushHubService(
            new HubSettingsStub(''),
            ['status' => 201, 'body' => ['license_key' => 'should_not_be_used']]
        );

        $this->assertNull($service->provisionLicense(''));
        // Aucun appel réseau émis : validation faite avant tout curl_init().
        $this->assertSame([], $service->calls);
    }

    // ──────────────────────────────────────────────────────────────────
    // Récupération self-service de licence (endpoint public /v1/licenses/recover)
    // Le hub ne renvoie JAMAIS la clé ici (livrée par callback signé vers `hubkey`) : ces tests
    // ne vérifient donc que l'interprétation du statut HTTP, jamais une clé en retour.
    // ──────────────────────────────────────────────────────────────────

    public function testRecoverLicenseReturnsRecoveredOn200(): void
    {
        $service = new ProvisionTestPushHubService(
            new HubSettingsStub(''),
            ['status' => 200, 'body' => ['recovered' => true]]
        );

        $result = $service->recoverLicenseDetailed('https://shop.example.com');

        $this->assertSame(['status' => 'recovered'], $result);
        $this->assertCount(1, $service->calls);
        [$method, $path, $body, $authenticated] = $service->calls[0];
        $this->assertSame('POST', $method);
        $this->assertSame('/v1/licenses/recover', $path);
        $this->assertSame('https://shop.example.com', $body['shop_url']);
        // Endpoint public du hub : aucune authentification (le module n'a justement plus de clé).
        $this->assertFalse($authenticated);
    }

    public function testRecoverLicenseReturnsNotFoundOn404(): void
    {
        $service = new ProvisionTestPushHubService(
            new HubSettingsStub(''),
            ['status' => 404, 'body' => ['error' => 'not_found']]
        );

        $result = $service->recoverLicenseDetailed('https://shop.example.com');

        $this->assertSame(['status' => 'not_found'], $result);
    }

    public function testRecoverLicenseReturnsCallbackFailedOn502(): void
    {
        $service = new ProvisionTestPushHubService(
            new HubSettingsStub(''),
            ['status' => 502, 'body' => ['recovered' => false, 'reason' => 'callback_failed']]
        );

        $result = $service->recoverLicenseDetailed('https://shop.example.com');

        $this->assertSame(['status' => 'callback_failed'], $result);
    }

    public function testRecoverLicenseReturnsRateLimitedOn429(): void
    {
        $service = new ProvisionTestPushHubService(
            new HubSettingsStub(''),
            ['status' => 429, 'body' => []]
        );

        $result = $service->recoverLicenseDetailed('https://shop.example.com');

        $this->assertSame(['status' => 'rate_limited'], $result);
    }

    public function testRecoverLicenseReturnsNetworkErrorOnUnexpectedStatus(): void
    {
        $service = new ProvisionTestPushHubService(
            new HubSettingsStub(''),
            ['status' => 500, 'body' => []]
        );

        $this->assertSame(['status' => 'network_error'], $service->recoverLicenseDetailed('https://shop.example.com'));
    }

    public function testRecoverLicenseReturnsNetworkErrorOnCurlFailure(): void
    {
        $service = new ProvisionTestPushHubService(
            new HubSettingsStub(''),
            ['status' => 0, 'body' => []]
        );

        $this->assertSame(['status' => 'network_error'], $service->recoverLicenseDetailed('https://shop.example.com'));
    }

    public function testRecoverLicenseReturnsNetworkErrorWithoutCallWhenShopUrlEmpty(): void
    {
        $service = new ProvisionTestPushHubService(
            new HubSettingsStub(''),
            ['status' => 200, 'body' => ['recovered' => true]]
        );

        $this->assertSame(['status' => 'network_error'], $service->recoverLicenseDetailed(''));
        // Aucun appel réseau émis : validation faite avant tout curl_init().
        $this->assertSame([], $service->calls);
    }

    public function testRecoverLicenseWorksEvenWhenHubDisabled(): void
    {
        // Comme provisionLicense(), recoverLicenseDetailed() ne doit PAS exiger isEnabled() :
        // c'est précisément l'outil pour sortir de l'état "hub désactivé" après réinstallation.
        $service = new ProvisionTestPushHubService(
            new HubSettingsStub(''),
            ['status' => 200, 'body' => ['recovered' => true]]
        );

        $this->assertFalse($service->isEnabled());
        $this->assertSame(['status' => 'recovered'], $service->recoverLicenseDetailed('https://shop.example.com'));
    }
}

/**
 * Hub-only : l'URL est hardcodée, seule la clé de licence est configurable.
 */
final class HubSettingsStub extends SettingsService
{
    private string $hubKey;

    public function __construct(string $hubKey)
    {
        $this->hubKey = $hubKey;
    }

    public function getHubUrl(): string
    {
        return 'https://push.rebuild-it.fr';
    }

    public function getHubLicenseKey(): string
    {
        return $this->hubKey;
    }

    public function isHubEnabled(): bool
    {
        return $this->hubKey !== '';
    }
}

final class TestPushHubService extends PushHubService
{
    /** @var array<int, array{0: string, 1: string, 2: array<string, mixed>|null}> */
    public array $calls = [];

    /** @var array<string, mixed>|null */
    private $response;

    /**
     * @param array<string, mixed>|null $response réponse simulée (null = échec hub)
     */
    public function __construct(SettingsService $settings, $response)
    {
        parent::__construct($settings);
        $this->response = $response;
    }

    protected function request(string $method, string $path, ?array $body): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $this->calls[] = [$method, $path, $body];

        return $this->response;
    }
}

/**
 * Double de test pour provisionLicense()/provisionLicenseDetailed() : surcharge performRequest()
 * (bas niveau, pas de dépendance à isEnabled()) pour simuler 201 / 409 / erreur réseau sans curl réel.
 */
final class ProvisionTestPushHubService extends PushHubService
{
    /** @var array<int, array{0: string, 1: string, 2: array<string, mixed>|null, 3: bool}> */
    public array $calls = [];

    /** @var array{status: int, body: array<string, mixed>} */
    private array $forcedResult;

    /**
     * @param array{status: int, body: array<string, mixed>} $forcedResult
     */
    public function __construct(SettingsService $settings, array $forcedResult)
    {
        parent::__construct($settings);
        $this->forcedResult = $forcedResult;
    }

    protected function performRequest(string $method, string $path, ?array $body, bool $authenticated): array
    {
        $this->calls[] = [$method, $path, $body, $authenticated];

        return $this->forcedResult;
    }
}
