<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade 1.6.1 — Mise à jour en un clic depuis le back-office
 *
 * Nouveautés :
 *   - Nouveau service ModuleUpdaterService : télécharge, valide, sauvegarde, extrait
 *     et applique la mise à jour du module via runUpgradeModule() avec rollback automatique.
 *   - Bouton « Mettre à jour maintenant » dans le bandeau MAJ disponible du BO.
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
