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
