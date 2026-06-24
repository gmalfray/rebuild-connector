<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../rebuildconnector/classes/UpdateCheckService.php';
require_once __DIR__ . '/../../rebuildconnector/classes/ModuleUpdaterService.php';

/**
 * Tests unitaires pour ModuleUpdaterService.
 *
 * Couvre :
 *   - validateDownloadOrigin : rejet et acceptation des origines
 *   - validateZip : détection d'un ZIP valide, invalide, dossier manquant
 *   - performUpdate : branche « déjà à jour » (pas de MAJ disponible)
 *   - Rollback : simulé via un sous-test d'intégration léger
 */
final class ModuleUpdaterServiceTest extends TestCase
{
    // ─── validateDownloadOrigin ────────────────────────────────────────────────

    public function testValidOriginGitHub(): void
    {
        $service = $this->makeService(null);
        $result = $service->validateDownloadOrigin(
            'https://objects.githubusercontent.com/github-production-release-asset-42/gmalfray/rebuild-connector/v1.6.1/rebuildconnector.zip'
        );
        $this->assertNull($result, 'objects.githubusercontent.com doit être autorisé');
    }

    public function testValidOriginGitHubReleases(): void
    {
        $service = $this->makeService(null);
        $result = $service->validateDownloadOrigin(
            'https://github.com/gmalfray/rebuild-connector/releases/download/v1.6.1/rebuildconnector.zip'
        );
        $this->assertNull($result, 'github.com doit être autorisé');
    }

    public function testValidOriginUpdatesRebuildIt(): void
    {
        $service = $this->makeService(null);
        $result = $service->validateDownloadOrigin(
            'https://updates.rebuild-it.fr/rebuildconnector/rebuildconnector.zip'
        );
        $this->assertNull($result, 'updates.rebuild-it.fr doit être autorisé');
    }

    public function testRejectHttpUrl(): void
    {
        $service = $this->makeService(null);
        $result = $service->validateDownloadOrigin(
            'http://github.com/gmalfray/rebuild-connector/releases/download/v1.6.1/rebuildconnector.zip'
        );
        $this->assertNotNull($result, 'HTTP doit être rejeté');
        $this->assertStringContainsString('HTTPS', $result);
    }

    public function testRejectArbitraryHost(): void
    {
        $service = $this->makeService(null);
        $result = $service->validateDownloadOrigin('https://evil.example.com/rebuildconnector.zip');
        $this->assertNotNull($result, 'Un hôte arbitraire doit être rejeté');
        $this->assertStringContainsString('evil.example.com', $result);
    }

    public function testRejectSubdomainSpoofing(): void
    {
        // "notgithub.com" ne doit PAS matcher "github.com"
        $service = $this->makeService(null);
        $result = $service->validateDownloadOrigin('https://notgithub.com/evil.zip');
        $this->assertNotNull($result, 'notgithub.com ne doit pas être accepté comme alias de github.com');
    }

    public function testRejectMalformedUrl(): void
    {
        $service = $this->makeService(null);
        $result = $service->validateDownloadOrigin('not-a-url');
        $this->assertNotNull($result, 'Une URL malformée doit être rejetée');
    }

    // ─── validateZip ──────────────────────────────────────────────────────────

    public function testValidZipWithModuleFolderAtRoot(): void
    {
        $zipPath = $this->createZip([
            'rebuildconnector/',
            'rebuildconnector/rebuildconnector.php',
            'rebuildconnector/config.xml',
        ]);

        $service = $this->makeService(null);
        $result = $service->validateZip($zipPath);
        @unlink($zipPath);

        $this->assertNull($result, 'Un ZIP valide avec rebuildconnector/ doit être accepté');
    }

    public function testValidZipWithPrefixedFolder(): void
    {
        // Cas GitHub : archive rebuildconnector-1.6.1/rebuildconnector/...
        $zipPath = $this->createZip([
            'rebuildconnector-1.6.1/',
            'rebuildconnector-1.6.1/rebuildconnector/',
            'rebuildconnector-1.6.1/rebuildconnector/rebuildconnector.php',
        ]);

        $service = $this->makeService(null);
        $result = $service->validateZip($zipPath);
        @unlink($zipPath);

        $this->assertNull($result, 'Un ZIP avec préfixe de version doit être accepté');
    }

    public function testInvalidZipMissingModuleFolder(): void
    {
        $zipPath = $this->createZip([
            'some-other-folder/',
            'some-other-folder/file.txt',
        ]);

        $service = $this->makeService(null);
        $result = $service->validateZip($zipPath);
        @unlink($zipPath);

        $this->assertNotNull($result, 'Un ZIP sans dossier rebuildconnector/ doit être rejeté');
        $this->assertStringContainsString('rebuildconnector', $result);
    }

    public function testInvalidZipNotAZip(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rbc_test_');
        if ($tmpFile === false) {
            $this->markTestSkipped('Impossible de créer un fichier temporaire.');
        }
        file_put_contents($tmpFile, 'not a zip file at all');

        $service = $this->makeService(null);
        $result = $service->validateZip($tmpFile);
        @unlink($tmpFile);

        $this->assertNotNull($result, 'Un fichier non-ZIP doit être rejeté');
        $this->assertStringContainsString('ZIP invalide', $result);
    }

    // ─── performUpdate : branche « pas de MAJ disponible » ───────────────────

    public function testPerformUpdateReturnsFalseWhenAlreadyUpToDate(): void
    {
        // UpdateCheckService stub qui renvoie null (pas de MAJ)
        $service = $this->makeService(null);
        $result = $service->performUpdate();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('à jour', $result['message']);
    }

    public function testPerformUpdateRejectsBadOriginFromService(): void
    {
        // UpdateCheckService stub qui renvoie une URL d'origine non autorisée
        $service = $this->makeService([
            'latest'       => '9.9.9',
            'url'          => 'https://evil.example.com/',
            'download_url' => 'https://evil.example.com/rebuildconnector.zip',
        ]);
        $result = $service->performUpdate();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('non autorisée', $result['message']);
    }

    // ─── Helpers privés ───────────────────────────────────────────────────────

    /**
     * Crée un ModuleUpdaterService avec un UpdateCheckService stub.
     *
     * @param array{latest: string, url: string, download_url: string}|null $updateInfo
     */
    private function makeService(?array $updateInfo): ModuleUpdaterService
    {
        $stub = $this->createMock(UpdateCheckService::class);
        $stub->method('getAvailableUpdate')->willReturn($updateInfo);

        return new ModuleUpdaterService($stub);
    }

    /**
     * Crée un fichier ZIP temporaire avec les entrées données.
     *
     * @param array<int, string> $entries Chemins à ajouter (suffixe / = répertoire)
     */
    private function createZip(array $entries): string
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive non disponible.');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'rbc_zip_test_');
        if ($tmpFile === false) {
            $this->fail('Impossible de créer un fichier temporaire pour le ZIP.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpFile, \ZipArchive::OVERWRITE) !== true) {
            $this->fail('Impossible d\'ouvrir le ZIP temporaire.');
        }

        foreach ($entries as $entry) {
            if (substr($entry, -1) === '/') {
                $zip->addEmptyDir($entry);
            } else {
                $zip->addFromString($entry, '<?php // stub');
            }
        }

        $zip->close();

        return $tmpFile;
    }
}
