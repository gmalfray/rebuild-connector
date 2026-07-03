<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Vérifie que RebuildConnector::install() ne throw JAMAIS et réussit quand même quand
 * l'auto-provisionnement « zéro config » de la licence hub échoue (réseau/hub indisponible).
 *
 * L'échec réseau est forcé de façon déterministe — pas de dépendance à un réseau externe ni de
 * flakiness — en pointant REBUILDCONNECTOR_HUB_URL_OVERRIDE (override DEV documenté dans
 * SettingsService::getHubUrl()) vers un port local fermé : le cURL échoue immédiatement
 * (connexion refusée) sans attendre le timeout complet.
 */
final class ModuleAutoProvisionInstallTest extends TestCase
{
    public function testInstallSucceedsAndDoesNotThrowWhenHubProvisionFails(): void
    {
        if (!defined('REBUILDCONNECTOR_HUB_URL_OVERRIDE')) {
            // Port local très probablement fermé : connexion refusée quasi instantanée.
            define('REBUILDCONNECTOR_HUB_URL_OVERRIDE', 'http://127.0.0.1:1');
        }

        $module = new RebuildConnector();

        $result = null;
        try {
            $result = $module->install();
        } catch (\Throwable $exception) {
            $this->fail('install() ne doit jamais lever d\'exception : ' . $exception->getMessage());
        }

        $this->assertTrue($result, 'install() doit réussir même si le hub push est injoignable.');
    }
}
