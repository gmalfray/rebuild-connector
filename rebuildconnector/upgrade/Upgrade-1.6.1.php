<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade 1.6.1 — Mise à jour en un clic depuis le back-office
 *
 * Nouveautés :
 *   - Mise à jour en un clic depuis le bandeau « MAJ disponible » du back-office.
 *   - Validation stricte de l'origine du download_url (whitelist : github.com, updates.rebuild-it.fr).
 *   - Sauvegarde horodatée avant toute modification, rollback si une étape échoue.
 *
 * Pas de modification de schéma de base de données dans cette version.
 *
 * @param RebuildConnector $module
 */
function upgrade_module_1_6_1($module)
{
    if (!($module instanceof RebuildConnector)) {
        return false;
    }

    return true;
}
