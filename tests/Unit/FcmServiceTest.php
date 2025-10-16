<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class FcmServiceTest extends TestCase
{
    public function testSendNotificationUsesTopicsBeforeTokens(): void
    {
        $settings = new DummySettingsService();
        $service = new TestFcmService($settings, [true]);

        $result = $service->sendNotification(['token-1'], ['title' => 'Hello'], ['event' => 'order.created'], ['orders'], ['fallback-1']);

        $this->assertTrue($result);
        $this->assertCount(1, $service->dispatchedMessages);
        $message = $service->dispatchedMessages[0];
        $this->assertSame('orders', $message['message']['topic']);
        $this->assertArrayNotHasKey('token', $message['message']);
    }

    public function testSendNotificationFallsBackToTokensWhenTopicsFail(): void
    {
        $settings = new DummySettingsService();
        $service = new TestFcmService($settings, [false, true]);

        $result = $service->sendNotification(['token-1'], ['title' => 'Hello'], ['event' => 'order.created'], ['orders'], ['fallback-1']);

        $this->assertTrue($result);
        $this->assertCount(2, $service->dispatchedMessages);
        $this->assertSame('orders', $service->dispatchedMessages[0]['message']['topic']);
        $this->assertSame('token-1', $service->dispatchedMessages[1]['message']['token']);
    }

    public function testSendNotificationFallsBackToFallbackTokens(): void
    {
        $settings = new DummySettingsService();
        $service = new TestFcmService($settings, [false, false, true]);

        $result = $service->sendNotification(['token-1'], ['title' => 'Hello'], ['event' => 'order.created'], ['orders'], ['fallback-1']);

        $this->assertTrue($result);
        $this->assertSame('fallback-1', $service->dispatchedMessages[2]['message']['token']);
    }

    public function testSendNotificationReturnsFalseWhenAllChannelsFail(): void
    {
        $settings = new DummySettingsService();
        $service = new TestFcmService($settings, [false, false, false]);

        $result = $service->sendNotification(['token-1'], ['title' => 'Hello'], ['event' => 'order.created'], ['orders'], ['fallback-1']);

        $this->assertFalse($result);
    }
}

final class DummySettingsService extends SettingsService
{
    public function getFcmServiceAccount(): ?array
    {
        return [
            'project_id' => 'demo-project',
            'client_email' => 'service@demo',
            'private_key' => 'dummy-private-key',
        ];
    }
}

final class TestFcmService extends FcmService
{
    /** @var array<int, bool> */
    private array $dispatchPlan;
    /** @var array<int, array<string, mixed>> */
    public array $dispatchedMessages = [];

    /**
     * @param array<int, bool> $dispatchPlan
     */
    public function __construct(SettingsService $settingsService, array $dispatchPlan)
    {
        parent::__construct($settingsService);
        $this->dispatchPlan = $dispatchPlan;
    }

    protected function fetchAccessToken(array $serviceAccount): ?string
    {
        return 'stub-token';
    }

    protected function dispatchMessage(string $projectId, string $accessToken, array $message): bool
    {
        $this->dispatchedMessages[] = $message;
        return array_shift($this->dispatchPlan) ?? false;
    }
}
