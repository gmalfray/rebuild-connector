<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade 1.2.0 — Multi-utilisateur
 *
 * Crée la table rebuildconnector_user pour la gestion des utilisateurs nommés.
 *
 * @param RebuildConnector $module
 */
function upgrade_module_1_2_0($module)
{
    if (!($module instanceof RebuildConnector)) {
        return false;
    }

    require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/UserService.php';

    return UserService::install();
}
