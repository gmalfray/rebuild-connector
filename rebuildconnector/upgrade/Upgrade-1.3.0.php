<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade 1.3.0 — Refonte de la page de configuration admin
 *
 * Pas de modification de schéma de base de données dans cette version.
 * La refonte concerne uniquement l'interface d'administration (configure.tpl)
 * et l'ajout de méthodes dans UserService (regenerateApiKey, updateScopes, getRolePresets)
 * et SettingsService (getFcmProjectId, regenerateApiKey).
 *
 * @param RebuildConnector $module
 */
function upgrade_module_1_3_0($module)
{
    if (!($module instanceof RebuildConnector)) {
        return false;
    }

    return true;
}
