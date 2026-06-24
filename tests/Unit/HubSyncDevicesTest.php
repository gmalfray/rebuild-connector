<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Tests du backfill / synchronisation des devices existants vers le hub.
 *
 * On mock à deux niveaux :
 *  - FcmDeviceServiceStub : contrôle les lots retournés par getDevicesBatch().
 *  - TestPushHubServiceForSync : surcharge request() pour simuler succès/échec hub
 *    sans appel réseau réel.
 */
final class HubSyncDevicesTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────
    // Hub désactivé
    // ──────────────────────────────────────────────────────────────────

    public function testSyncReturnsZeroCountsWhenHubDisabled(): void
    {
        $hub = new SyncableHubService(new SyncHubSettingsStub('', ''), []);
        $fcm = new FcmDeviceServiceStub([]);

        $result = $hub->syncAllDevices($fcm);

        $this->assertSame(0, $result['synced']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['skipped']);
        // Aucun appel réseau ne doit avoir été émis.
        $this->assertSame([], $hub->calls);
    }

    // ──────────────────────────────────────────────────────────────────
    // Table vide
    // ──────────────────────────────────────────────────────────────────

    public function testSyncWithNoDevicesReturnsZeroCounts(): void
    {
        $hub = new SyncableHubService(
            new SyncHubSettingsStub('https://push.rebuild-it.fr', 'rbk_secret'),
            [['registered' => true]]
        );
        $fcm = new FcmDeviceServiceStub([]);

        $result = $hub->syncAllDevices($fcm);

        $this->assertSame(0, $result['synced']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame([], $hub->calls);
    }

    // ──────────────────────────────────────────────────────────────────
    // Chaque device est bien relayé au hub
    // ──────────────────────────────────────────────────────────────────

    public function testSyncRelaiesEachDeviceToHub(): void
    {
        $devices = [
            ['token' => 'tok-A', 'platform' => 'android', 'topics' => '["order.created"]'],
            ['token' => 'tok-B', 'platform' => 'ios',     'topics' => '[]'],
            ['token' => 'tok-C', 'platform' => '',        'topics' => '["order.status.changed","order.created"]'],
        ];

        $hub = new SyncableHubService(
            new SyncHubSettingsStub('https://push.rebuild-it.fr', 'rbk_secret'),
            // Toutes les réponses hub = succès.
            array_fill(0, count($devices), ['registered' => true])
        );
        $fcm = new FcmDeviceServiceStub($devices);

        $result = $hub->syncAllDevices($fcm);

        $this->assertSame(3, $result['synced']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['skipped']);
        $this->assertCount(3, $hub->calls);

        // Vérifier le premier device : token, platform, topics décodés.
        [$method, $path, $body] = $hub->calls[0];
        $this->assertSame('POST', $method);
        $this->assertSame('/v1/devices', $path);
        $this->assertSame('tok-A', $body['fcm_token']);
        $this->assertSame('android', $body['platform']);
        $this->assertSame(['order.created'], $body['topics']);

        // Device sans platform explicite → 'android' par défaut.
        $callC = $hub->calls[2];
        $this->assertIsArray($callC[2]);
        /** @var array<string, mixed> $bodyC */
        $bodyC = $callC[2];
        $this->assertSame('android', $bodyC['platform']);
        $this->assertSame(['order.status.changed', 'order.created'], $bodyC['topics']);
    }

    // ──────────────────────────────────────────────────────────────────
    // Un token vide est ignoré (skipped), ne déclenche pas d'appel hub
    // ──────────────────────────────────────────────────────────────────

    public function testSyncSkipsDevicesWithEmptyToken(): void
    {
        $devices = [
            ['token' => '',      'platform' => 'android', 'topics' => '[]'],
            ['token' => '  ',    'platform' => 'android', 'topics' => '[]'],
            ['token' => 'tok-X', 'platform' => 'android', 'topics' => '[]'],
        ];

        $hub = new SyncableHubService(
            new SyncHubSettingsStub('https://push.rebuild-it.fr', 'rbk_secret'),
            [['registered' => true]]
        );
        $fcm = new FcmDeviceServiceStub($devices);

        $result = $hub->syncAllDevices($fcm);

        $this->assertSame(1, $result['synced']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(2, $result['skipped']);
        $this->assertCount(1, $hub->calls);
    }

    // ──────────────────────────────────────────────────────────────────
    // Un échec hub n'interrompt pas les devices suivants
    // ──────────────────────────────────────────────────────────────────

    public function testSyncContinuesAfterHubFailure(): void
    {
        $devices = [
            ['token' => 'tok-1', 'platform' => 'android', 'topics' => '[]'],
            ['token' => 'tok-2', 'platform' => 'android', 'topics' => '[]'],
            ['token' => 'tok-3', 'platform' => 'android', 'topics' => '[]'],
        ];

        // Réponses : succès, échec (null), succès.
        $hub = new SyncableHubService(
            new SyncHubSettingsStub('https://push.rebuild-it.fr', 'rbk_secret'),
            [['ok' => true], null, ['ok' => true]]
        );
        $fcm = new FcmDeviceServiceStub($devices);

        $result = $hub->syncAllDevices($fcm);

        $this->assertSame(2, $result['synced']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['skipped']);
        // Les trois devices ont bien été tentés malgré l'échec du second.
        $this->assertCount(3, $hub->calls);
    }

    // ──────────────────────────────────────────────────────────────────
    // Pagination : plusieurs lots sont parcourus
    // ──────────────────────────────────────────────────────────────────

    public function testSyncIteratesMultipleBatches(): void
    {
        // On génère 3 lots : 50 + 50 + 10 = 110 devices.
        $batch1 = $this->makeDeviceBatch(50, 0);
        $batch2 = $this->makeDeviceBatch(50, 50);
        $batch3 = $this->makeDeviceBatch(10, 100);

        // Toutes les réponses hub = succès (on en fournit 110).
        $responses = array_fill(0, 110, ['registered' => true]);

        $hub = new SyncableHubService(
            new SyncHubSettingsStub('https://push.rebuild-it.fr', 'rbk_secret'),
            $responses
        );
        $fcm = new FcmDeviceServiceStub([], [$batch1, $batch2, $batch3]);

        $result = $hub->syncAllDevices($fcm);

        $this->assertSame(110, $result['synced']);
        $this->assertSame(0, $result['failed']);
        $this->assertCount(110, $hub->calls);
    }

    // ──────────────────────────────────────────────────────────────────
    // decodeTopicsStatic : topics valides, invalides, vides
    // ──────────────────────────────────────────────────────────────────

    public function testDecodeTopicsStaticWithValidJson(): void
    {
        $result = FcmDeviceService::decodeTopicsStatic('["order.created","order.status.changed"]');
        $this->assertSame(['order.created', 'order.status.changed'], $result);
    }

    public function testDecodeTopicsStaticWithEmptyPayload(): void
    {
        $this->assertSame([], FcmDeviceService::decodeTopicsStatic(''));
        $this->assertSame([], FcmDeviceService::decodeTopicsStatic('[]'));
    }

    public function testDecodeTopicsStaticWithInvalidJson(): void
    {
        $this->assertSame([], FcmDeviceService::decodeTopicsStatic('not-json'));
    }

    public function testDecodeTopicsStaticFiltersInvalidTopics(): void
    {
        // Les topics avec caractères invalides doivent être filtrés par sanitizeTopics.
        $result = FcmDeviceService::decodeTopicsStatic('["order.created",""]');
        $this->assertSame(['order.created'], $result);
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, string>>
     */
    private function makeDeviceBatch(int $count, int $startIndex): array
    {
        $batch = [];
        for ($i = 0; $i < $count; $i++) {
            $batch[] = [
                'token'    => 'tok-' . ($startIndex + $i),
                'platform' => 'android',
                'topics'   => '["order.created"]',
            ];
        }
        return $batch;
    }
}

// ══════════════════════════════════════════════════════════════════════
// Stubs & fakes
// ══════════════════════════════════════════════════════════════════════

/**
 * Stub de SettingsService isolé pour les tests de synchronisation hub.
 * Nommé SyncHubSettingsStub pour ne pas entrer en conflit avec HubSettingsStub
 * défini dans PushHubServiceTest.php et chargé dans le même run PHPUnit.
 */
final class SyncHubSettingsStub extends SettingsService
{
    private string $hubUrl;
    private string $hubKey;

    public function __construct(string $hubUrl, string $hubKey)
    {
        $this->hubUrl = $hubUrl;
        $this->hubKey = $hubKey;
    }

    public function getHubUrl(): string
    {
        return $this->hubUrl;
    }

    public function getHubLicenseKey(): string
    {
        return $this->hubKey;
    }

    public function isHubEnabled(): bool
    {
        return $this->hubUrl !== '' && $this->hubKey !== '';
    }
}

/**
 * Surcharge request() de PushHubService pour éviter tout appel réseau.
 * Les réponses sont consommées dans l'ordre d'appel.
 */
final class SyncableHubService extends PushHubService
{
    /** @var array<int, array{0: string, 1: string, 2: array<string, mixed>|null}> */
    public array $calls = [];

    /** @var array<int, array<string, mixed>|null> */
    private array $responses;

    private int $responseIndex = 0;

    /**
     * @param array<int, array<string, mixed>|null> $responses
     */
    public function __construct(SettingsService $settings, array $responses)
    {
        parent::__construct($settings);
        $this->responses = $responses;
    }

    protected function request(string $method, string $path, ?array $body): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $this->calls[] = [$method, $path, $body];

        $response = $this->responses[$this->responseIndex] ?? null;
        ++$this->responseIndex;

        return $response;
    }
}

/**
 * Stub de FcmDeviceService : retourne des lots contrôlés sans accès BDD.
 *
 * Si $batches est fourni, getDevicesBatch() retourne chaque élément de $batches
 * en séquence. Sinon, il découpe $devices en lots de $batchSize.
 */
final class FcmDeviceServiceStub extends FcmDeviceService
{
    /** @var array<int, array<string, string>> */
    private array $devices;

    /** @var array<int, array<int, array<string, string>>>|null */
    private ?array $batches;

    private int $batchCallCount = 0;

    /**
     * @param array<int, array<string, string>>                       $devices  Utilisé si $batches est null.
     * @param array<int, array<int, array<string, string>>>|null      $batches  Lots pré-découpés (optionnel).
     */
    public function __construct(array $devices, ?array $batches = null)
    {
        $this->devices = $devices;
        $this->batches = $batches;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDevicesBatch(int $offset, int $limit): array
    {
        if ($this->batches !== null) {
            $batch = $this->batches[$this->batchCallCount] ?? [];
            ++$this->batchCallCount;
            return $batch;
        }

        // Découpage simple de $this->devices.
        /** @var array<int, array<string, mixed>> $slice */
        $slice = array_slice($this->devices, $offset, $limit);
        return $slice;
    }
}
