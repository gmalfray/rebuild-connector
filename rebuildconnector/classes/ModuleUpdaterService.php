<?php

defined('_PS_VERSION_') || exit;

/**
 * Service de mise à jour en un clic du module Rebuild Connector.
 *
 * Enchaîne de façon fail-safe :
 *   1. Re-vérification de la MAJ via UpdateCheckService (jamais de URL postée par le client).
 *   2. Validation de l'origine du download_url (whitelist de domaines autorisés).
 *   3. Téléchargement HTTPS avec vérification TLS et timeout.
 *   4. Validation du ZIP (extension php, dossier rebuildconnector/ présent).
 *   5. Sauvegarde horodatée du module avant toute modification.
 *   6. Extraction par-dessus modules/rebuildconnector/.
 *   7. Exécution du mécanisme d'upgrade PrestaShop (runUpgradeModule).
 *   8. Purge du cache PS et de la clé de vérification de version.
 *   Rollback automatique si une étape 5-8 échoue.
 */
class ModuleUpdaterService
{
    /**
     * Domaines autorisés comme source de téléchargement.
     * Toute URL dont le host ne figure pas ici est rejetée (protection SSRF).
     */
    private const ALLOWED_DOWNLOAD_HOSTS = [
        'github.com',
        'objects.githubusercontent.com',
        'codeload.github.com',
        'updates.rebuild-it.fr',
    ];

    /** Timeout cURL pour le téléchargement du ZIP (secondes). */
    private const DOWNLOAD_TIMEOUT = 60;

    /** Nom du dossier racine attendu dans l'archive. */
    private const MODULE_FOLDER = 'rebuildconnector';

    /** Clé de cache de vérification de version à invalider après MAJ. */
    private const CACHE_KEY = 'REBUILDCONNECTOR_UPDATE_CHECK';

    private UpdateCheckService $updateCheckService;

    public function __construct(UpdateCheckService $updateCheckService)
    {
        $this->updateCheckService = $updateCheckService;
    }

    /**
     * Point d'entrée principal.
     *
     * @return array{success: bool, message: string}
     */
    public function performUpdate(): array
    {
        // Étape 1 — Re-vérification de la MAJ depuis notre service (jamais depuis $_POST)
        $updateInfo = $this->updateCheckService->getAvailableUpdate();
        if ($updateInfo === null) {
            return ['success' => false, 'message' => 'Aucune mise à jour disponible. Le module est déjà à jour.'];
        }

        $downloadUrl = $updateInfo['download_url'] ?? '';
        if ($downloadUrl === '') {
            return ['success' => false, 'message' => 'URL de téléchargement introuvable dans les informations de mise à jour.'];
        }

        // Étape 2 — Validation de l'origine du download_url
        $originError = $this->validateDownloadOrigin($downloadUrl);
        if ($originError !== null) {
            return ['success' => false, 'message' => $originError];
        }

        // Étape 3 — Téléchargement
        $tmpZip = $this->downloadZip($downloadUrl);
        if ($tmpZip === null) {
            return ['success' => false, 'message' => 'Échec du téléchargement de la mise à jour. Vérifiez la connectivité et réessayez.'];
        }

        // Nettoyage garanti du fichier temporaire
        try {
            return $this->applyUpdate($tmpZip, $updateInfo['latest'] ?? '');
        } finally {
            if (file_exists($tmpZip)) {
                @unlink($tmpZip);
            }
        }
    }

    /**
     * Applique la mise à jour depuis le ZIP temporaire déjà téléchargé.
     * Gère la sauvegarde, l'extraction, l'upgrade PS et le rollback.
     *
     * @return array{success: bool, message: string}
     */
    private function applyUpdate(string $tmpZip, string $targetVersion): array
    {
        // Étape 4 — Validation du ZIP
        $zipError = $this->validateZip($tmpZip);
        if ($zipError !== null) {
            return ['success' => false, 'message' => $zipError];
        }

        $moduleDir = _PS_MODULE_DIR_ . self::MODULE_FOLDER;

        // Étape 5 — Sauvegarde du module actuel
        $backupDir = $this->backup($moduleDir);
        if ($backupDir === null) {
            return ['success' => false, 'message' => 'Impossible de créer la sauvegarde du module. Aucun emplacement inscriptible disponible (var/rebuildconnector_backups/ et répertoire temporaire système inaccessibles). La mise à jour est annulée pour préserver le module existant.'];
        }

        // Étapes 6-8 avec rollback sur échec
        try {
            // Étape 6 — Extraction
            $extractError = $this->extract($tmpZip, $moduleDir);
            if ($extractError !== null) {
                throw new \RuntimeException($extractError);
            }

            // Étape 7 — Upgrade PrestaShop
            $upgradeError = $this->runPrestaShopUpgrade();
            if ($upgradeError !== null) {
                throw new \RuntimeException($upgradeError);
            }

            // Étape 8 — Purge du cache et de la clé de vérification
            $this->clearCaches();

            $this->cleanupBackup($backupDir);

            return [
                'success' => true,
                'message' => sprintf(
                    'Module mis à jour vers la version %s. La page va se recharger.',
                    htmlspecialchars($targetVersion, ENT_QUOTES)
                ),
            ];
        } catch (\RuntimeException $exception) {
            // Rollback
            $rollbackOk = $this->rollback($moduleDir, $backupDir);
            $rollbackMsg = $rollbackOk
                ? 'La version précédente a été restaurée.'
                : 'ATTENTION : la restauration automatique a également échoué — restaurez manuellement depuis ' . $backupDir . '.';

            return [
                'success' => false,
                'message' => 'Échec de la mise à jour : ' . $exception->getMessage() . ' ' . $rollbackMsg,
            ];
        }
    }

    /**
     * Valide que l'URL de téléchargement provient d'un hôte autorisé (anti-SSRF).
     * Retourne un message d'erreur ou null si l'URL est valide.
     */
    public function validateDownloadOrigin(string $url): ?string
    {
        if (!preg_match('#^https://#i', $url)) {
            return 'URL de téléchargement invalide : HTTPS requis.';
        }

        $parsed = parse_url($url);
        if (!is_array($parsed) || !isset($parsed['host']) || !is_string($parsed['host'])) {
            return 'URL de téléchargement malformée.';
        }

        $host = strtolower($parsed['host']);

        foreach (self::ALLOWED_DOWNLOAD_HOSTS as $allowed) {
            $suffix = '.' . $allowed;
            if ($host === $allowed || substr($host, -strlen($suffix)) === $suffix) {
                return null;
            }
        }

        return sprintf('Origine de téléchargement non autorisée : %s.', htmlspecialchars($host, ENT_QUOTES));
    }

    /**
     * Télécharge le ZIP depuis l'URL et le stocke dans un fichier temporaire.
     * Retourne le chemin du fichier temporaire, ou null en cas d'échec.
     */
    private function downloadZip(string $url): ?string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rbc_update_');
        if ($tmpFile === false) {
            return null;
        }

        $handle = fopen($tmpFile, 'wb');
        if ($handle === false) {
            @unlink($tmpFile);
            return null;
        }

        if (!function_exists('curl_init')) {
            fclose($handle);
            @unlink($tmpFile);
            return null;
        }

        $ch = curl_init();
        if ($ch === false) {
            fclose($handle);
            @unlink($tmpFile);
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_FILE           => $handle,
            CURLOPT_TIMEOUT        => self::DOWNLOAD_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'RebuildConnector-Updater/' . _PS_VERSION_,
        ]);

        $ok = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($handle);

        if ($ok === false || $httpCode !== 200) {
            @unlink($tmpFile);
            return null;
        }

        return $tmpFile;
    }

    /**
     * Valide que le fichier est un ZIP exploitable contenant le dossier rebuildconnector/.
     * Retourne un message d'erreur ou null si le ZIP est valide.
     */
    public function validateZip(string $zipPath): ?string
    {
        if (!class_exists('ZipArchive')) {
            return 'L\'extension PHP ZipArchive est requise pour appliquer la mise à jour.';
        }

        $zip = new \ZipArchive();
        $result = $zip->open($zipPath, \ZipArchive::RDONLY);

        if ($result !== true) {
            return sprintf('Archive ZIP invalide (code : %d).', $result);
        }

        $hasModuleFolder = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            // Accepte rebuildconnector/ à la racine ou dans un sous-dossier (ex: rebuildconnector-1.6.0/rebuildconnector/)
            if (preg_match('#(^|/)' . preg_quote(self::MODULE_FOLDER, '#') . '/#', $name)) {
                $hasModuleFolder = true;
                break;
            }
        }

        $zip->close();

        if (!$hasModuleFolder) {
            return 'Archive ZIP invalide : le dossier « ' . self::MODULE_FOLDER . '/ » est introuvable.';
        }

        return null;
    }

    /**
     * Détermine le répertoire de base pour stocker les sauvegardes,
     * garanti inscriptible par le user web (www-data).
     *
     * Priorité :
     *   1. _PS_ROOT_DIR_/var/rebuildconnector_backups/  — hors modules/, inscriptible par www-data
     *      même quand modules/ appartient à l'uid SFTP (1002).
     *   2. sys_get_temp_dir()/rebuildconnector_backups/ — dernier recours.
     *
     * Crée le répertoire si nécessaire. Retourne null si aucun emplacement
     * inscriptible n'est disponible.
     */
    private function resolveBackupBaseDir(): ?string
    {
        $candidates = [];

        // Candidat 1 : var/ de PrestaShop (hors modules/)
        if (defined('_PS_ROOT_DIR_')) {
            $candidates[] = rtrim((string) constant('_PS_ROOT_DIR_'), '/') . '/var/rebuildconnector_backups';
        }

        // Candidat 2 : répertoire temporaire système
        $sysTemp = sys_get_temp_dir();
        if ($sysTemp !== '') {
            $candidates[] = rtrim($sysTemp, '/') . '/rebuildconnector_backups';
        }

        foreach ($candidates as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                continue;
            }
            if (is_writable($dir)) {
                return $dir;
            }
        }

        return null;
    }

    /**
     * Crée une copie de sauvegarde horodatée du module dans un emplacement
     * garanti inscriptible (voir resolveBackupBaseDir).
     * Retourne le chemin complet du backup, ou null en cas d'échec.
     */
    private function backup(string $moduleDir): ?string
    {
        if (!is_dir($moduleDir)) {
            return null;
        }

        $baseDir = $this->resolveBackupBaseDir();
        if ($baseDir === null) {
            return null;
        }

        $backupDir = $baseDir . '/' . self::MODULE_FOLDER . '_backup_' . date('Ymd_His');

        // Évite d'écraser un backup existant (collision théorique à la seconde près)
        if (is_dir($backupDir)) {
            $backupDir .= '_' . uniqid('', false);
        }

        if (!$this->copyDirectory($moduleDir, $backupDir)) {
            // Nettoyage partiel en cas de copie incomplète
            $this->removeDirectory($backupDir);
            return null;
        }

        return $backupDir;
    }

    /**
     * Extrait le ZIP vers modules/rebuildconnector/ en écrasant les fichiers existants.
     * Retourne un message d'erreur ou null en cas de succès.
     */
    private function extract(string $zipPath, string $moduleDir): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return 'Impossible d\'ouvrir l\'archive ZIP pour l\'extraction.';
        }

        // Détecte si l'archive contient un dossier racine (ex: rebuildconnector-1.6.1/)
        // ou si rebuildconnector/ est directement à la racine.
        $prefix = $this->detectArchivePrefix($zip);

        $parentDir = dirname($moduleDir);
        if (!is_writable($parentDir)) {
            $zip->close();
            return 'Le dossier modules/ n\'est pas accessible en écriture (uid www-data).';
        }

        if ($prefix === '') {
            // Les fichiers sont déjà sous rebuildconnector/ à la racine
            $ok = $zip->extractTo($parentDir);
        } else {
            // L'archive a un dossier racine intermédiaire : on extrait sélectivement
            $ok = $this->extractWithPrefix($zip, $prefix, $moduleDir);
        }

        $zip->close();

        if ($ok === false) {
            return 'Échec de l\'extraction de l\'archive ZIP.';
        }

        return null;
    }

    /**
     * Détecte le préfixe racine dans l'archive (ex: "rebuildconnector-1.6.1/").
     * Retourne une chaîne vide si rebuildconnector/ est directement à la racine.
     */
    private function detectArchivePrefix(\ZipArchive $zip): string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            // Correspond à quelque chose/rebuildconnector/ mais pas rebuildconnector/ seul
            if (preg_match('#^([^/]+)/' . preg_quote(self::MODULE_FOLDER, '#') . '/#', $name, $matches)) {
                return $matches[1] . '/' . self::MODULE_FOLDER . '/';
            }
        }

        return '';
    }

    /**
     * Extrait sélectivement les entrées sous $prefix vers $targetDir.
     */
    private function extractWithPrefix(\ZipArchive $zip, string $prefix, string $targetDir): bool
    {
        $prefixLen = strlen($prefix);
        $success = true;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || strpos($name, $prefix) !== 0) {
                continue;
            }

            $relativePath = substr($name, $prefixLen);
            if ($relativePath === '' || $relativePath === false) {
                continue;
            }

            $targetPath = $targetDir . '/' . $relativePath;

            if (substr($name, -1) === '/') {
                // Répertoire
                if (!is_dir($targetPath) && !mkdir($targetPath, 0755, true)) {
                    $success = false;
                }
                continue;
            }

            // Fichier
            $targetFileDir = dirname($targetPath);
            if (!is_dir($targetFileDir) && !mkdir($targetFileDir, 0755, true)) {
                $success = false;
                continue;
            }

            $content = $zip->getFromIndex($i);
            if ($content === false) {
                $success = false;
                continue;
            }

            if (file_put_contents($targetPath, $content) === false) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Lance le mécanisme d'upgrade PrestaShop natif.
     * Compatible 1.7.8 et PS 8 : utilise Module::getInstanceByName() + runUpgradeModule().
     * Retourne un message d'erreur ou null en cas de succès.
     */
    private function runPrestaShopUpgrade(): ?string
    {
        if (!class_exists('Module')) {
            return 'Impossible de charger la classe Module PrestaShop.';
        }

        // On recharge l'instance depuis le disque pour avoir la nouvelle version
        $module = \Module::getInstanceByName(self::MODULE_FOLDER);
        if ($module === false || !is_object($module)) {
            // Cas dégradé : le module n'est pas reconnu (PS n'a pas encore rechargé le fichier).
            // On met à jour directement la version en base pour éviter une incohérence.
            return $this->updateModuleVersionInDb();
        }

        // runUpgradeModule() existe depuis PS 1.5 — disponible en 1.7.8 et 8.
        if (!method_exists($module, 'runUpgradeModule')) {
            return $this->updateModuleVersionInDb();
        }

        $result = $module->runUpgradeModule();

        // runUpgradeModule retourne true si ok, false ou un tableau d'erreurs sinon
        if ($result === false) {
            return 'L\'upgrade PrestaShop a échoué (runUpgradeModule).';
        }

        if (is_array($result)) {
            $errors = [];
            foreach ($result as $item) {
                if (is_string($item)) {
                    $errors[] = $item;
                }
            }
            if ($errors !== []) {
                return 'Erreurs lors de l\'upgrade : ' . implode(', ', $errors);
            }
        }

        return null;
    }

    /**
     * Fallback : met à jour la version du module directement en base de données.
     * Utilisé si Module::getInstanceByName() échoue ou si runUpgradeModule() est absent.
     * Retourne un message d'erreur ou null en cas de succès.
     */
    private function updateModuleVersionInDb(): ?string
    {
        // Relit la version depuis le fichier principal rechargé
        $moduleFile = _PS_MODULE_DIR_ . self::MODULE_FOLDER . '/' . self::MODULE_FOLDER . '.php';
        if (!file_exists($moduleFile)) {
            return 'Fichier principal du module introuvable après extraction.';
        }

        // On ne peut pas require_once (déjà chargé), on lit la version à la main
        $source = file_get_contents($moduleFile);
        if ($source === false) {
            return null; // Non bloquant
        }

        if (!preg_match('/\$this->version\s*=\s*[\'"]([0-9]+\.[0-9]+\.[0-9]+)[\'"]/', $source, $matches)) {
            return null; // Non bloquant, on continue
        }

        $newVersion = $matches[1];

        $ok = \Db::getInstance()->update(
            'module',
            ['version' => pSQL($newVersion)],
            'name = \'' . pSQL(self::MODULE_FOLDER) . '\''
        );

        return $ok ? null : 'Impossible de mettre à jour la version en base de données (non bloquant).';
    }

    /**
     * Purge le cache PrestaShop et la clé de vérification de version.
     */
    private function clearCaches(): void
    {
        // Purge la clé de cache de vérification de version
        \Configuration::deleteByName(self::CACHE_KEY);

        // Purge du cache PS (var/cache) si le chemin est accessible
        if (defined('_PS_CACHE_DIR_')) {
            $cacheDir = constant('_PS_CACHE_DIR_');
            if (is_string($cacheDir) && is_dir($cacheDir)) {
                $this->clearDirectory($cacheDir);
            }
        }

        // Fallback PS 1.7/8 : var/cache/ relative à _PS_ROOT_DIR_
        if (defined('_PS_ROOT_DIR_')) {
            $rootCacheDir = constant('_PS_ROOT_DIR_') . '/var/cache';
            if (is_dir($rootCacheDir)) {
                $this->clearDirectory($rootCacheDir);
            }
        }

        // Réinitialise OPcache (best-effort) : sans ça, les fichiers PHP fraîchement
        // extraits ne sont pas pris en compte avant un reload PHP-FPM. Silencieux si
        // OPcache est absent, désactivé, ou restreint (opcache.restrict_api).
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /**
     * Restaure le module depuis le backup.
     */
    private function rollback(string $moduleDir, string $backupDir): bool
    {
        if (!is_dir($backupDir)) {
            return false;
        }

        // Supprime la version partiellement extraite
        $this->removeDirectory($moduleDir);

        // Recopie le backup
        return $this->copyDirectory($backupDir, $moduleDir);
    }

    /**
     * Supprime le backup une fois la MAJ réussie (libère de l'espace).
     */
    private function cleanupBackup(string $backupDir): void
    {
        if (is_dir($backupDir)) {
            $this->removeDirectory($backupDir);
        }
    }

    /**
     * Copie récursive d'un répertoire.
     */
    private function copyDirectory(string $src, string $dst): bool
    {
        if (!is_dir($src)) {
            return false;
        }

        if (!is_dir($dst) && !mkdir($dst, 0755, true)) {
            return false;
        }

        $items = scandir($src);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $srcPath = $src . '/' . $item;
            $dstPath = $dst . '/' . $item;

            if (is_dir($srcPath)) {
                if (!$this->copyDirectory($srcPath, $dstPath)) {
                    return false;
                }
            } else {
                if (!copy($srcPath, $dstPath)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Suppression récursive d'un répertoire.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * Vide un répertoire de cache sans supprimer le répertoire lui-même.
     */
    private function clearDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
    }
}
