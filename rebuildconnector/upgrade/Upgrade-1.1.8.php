<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade 1.1.8 — Security hardening
 *
 * - Active le rate-limiting par défaut (rate_limit_enabled = true) si non déjà activé.
 * - Déclenche la migration du compte de service FCM de la base vers le fichier hors webroot.
 *
 * @param RebuildConnector $module
 */
function upgrade_module_1_1_8($module)
{
    if (!($module instanceof RebuildConnector)) {
        return false;
    }

    $settingsService = new SettingsService();
    $settings = $settingsService->all();

    // Activer le rate-limiting si encore désactivé
    if (empty($settings['rate_limit_enabled'])) {
        $settings['rate_limit_enabled'] = true;
        $settingsService->save($settings);
    }

    // Déclencher la migration FCM : un simple appel à getFcmServiceAccount()
    // effectue la migration automatique si une valeur est encore en base.
    try {
        $settingsService->getFcmServiceAccount();
    } catch (\Throwable $e) {
        // Non bloquant
    }

    return true;
}
