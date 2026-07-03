<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Double de test : surcharge fetchRemote() (protected) pour simuler la réponse
 * réseau sans appel HTTP réel, afin de tester checkForUpdateFresh() en isolation.
 */
final class FakeUpdateCheckService extends UpdateCheckService
{
    /** @var string|null */
    private $fakeResponse;

    public function __construct(string $currentVersion, ?string $fakeResponse)
    {
        parent::__construct($currentVersion);
        $this->fakeResponse = $fakeResponse;
    }

    protected function fetchRemote(string $url): ?string
    {
        return $this->fakeResponse;
    }
}

/**
 * Tests unitaires pour UpdateCheckService::checkForUpdateFresh().
 *
 * Contexte : une vérification renvoyait autrefois `null` aussi bien quand le module
 * est à jour que quand la vérification réseau échoue, ce qui faisait afficher à tort
 * « vous êtes à jour » sur un échec réseau (bouton BO). checkForUpdateFresh() distingue
 * désormais explicitement les 3 cas via un statut.
 */
final class UpdateCheckServiceTest extends TestCase
{
    public function testReturnsUpdateAvailableWhenRemoteVersionIsNewer(): void
    {
        $json = json_encode([
            'latest' => '2.0.0',
            'url' => 'https://example.test/changelog',
            'download_url' => 'https://example.test/download.zip',
        ]);
        $service = new FakeUpdateCheckService('1.0.0', $json);

        $result = $service->checkForUpdateFresh();

        $this->assertSame(UpdateCheckService::STATUS_UPDATE_AVAILABLE, $result['status']);
        $this->assertNotNull($result['update']);
        $this->assertSame('2.0.0', $result['update']['latest']);
    }

    public function testReturnsUpToDateWhenRemoteVersionIsNotNewer(): void
    {
        $json = json_encode([
            'latest' => '1.0.0',
            'url' => 'https://example.test/changelog',
            'download_url' => 'https://example.test/download.zip',
        ]);
        $service = new FakeUpdateCheckService('1.0.0', $json);

        $result = $service->checkForUpdateFresh();

        $this->assertSame(UpdateCheckService::STATUS_UP_TO_DATE, $result['status']);
        $this->assertNull($result['update']);
    }

    public function testReturnsCheckFailedWhenRemoteFetchFails(): void
    {
        // fetchRemote() renvoie null : échec réseau/timeout simulé.
        $service = new FakeUpdateCheckService('1.0.0', null);

        $result = $service->checkForUpdateFresh();

        $this->assertSame(UpdateCheckService::STATUS_CHECK_FAILED, $result['status']);
        $this->assertNull($result['update']);
    }

    public function testReturnsCheckFailedWhenRemoteResponseIsInvalidJson(): void
    {
        $service = new FakeUpdateCheckService('1.0.0', 'not-json{{{');

        $result = $service->checkForUpdateFresh();

        $this->assertSame(UpdateCheckService::STATUS_CHECK_FAILED, $result['status']);
        $this->assertNull($result['update']);
    }

}
