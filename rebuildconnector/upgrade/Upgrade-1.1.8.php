<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade 1.1.8 — Security hardening
 *
 * - Active le rate-limiting par défaut (rate_limit_enabled = true) si non déjà activé.
 *
 * Note : la migration du compte de service FCM (présente historiquement ici) a été retirée
 * en v1.7.1 avec le passage en push hub-only (suppression du FCM direct embarqué).
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

    return true;
}
