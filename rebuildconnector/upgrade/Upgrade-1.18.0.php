<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/SettingsService.php';

/**
 * Upgrade 1.18.0 — Capacités boutique + SAV natif + pont avis (rbreviews).
 *
 * Nouveautés :
 *   - GET /connector/capabilities : ce que la boutique sait faire ({reviews, sav}).
 *   - GET/PATCH/POST /sav, /sav/{id}, /sav/{id}/{status|reply} : fils clients natifs
 *     (ps_customer_thread/ps_customer_message), scopes `sav.read`/`sav.write`.
 *   - GET/POST /reviews, /reviews/{id}/{publish|trash|reply} : pont vers rbreviews (si présent),
 *     scope `reviews.moderate`.
 *
 * Comme pour l'ajout de `baskets.read`/`reports.read` en 0.3.0 : `getScopes()` renvoie EXACTEMENT
 * ce qui est persisté en base une fois `scopes` défini — les nouveaux scopes ne rejoignent donc
 * PAS automatiquement une boutique déjà installée simplement parce qu'ils rejoignent
 * `SettingsService::DEFAULT_SCOPES`. On les ajoute donc explicitement ici à la clé API globale
 * legacy (rétrocompatibilité totale, comme toutes les mises à jour précédentes).
 *
 * Les utilisateurs NOMMÉS (table `rebuildconnector_user`), eux, ne sont volontairement PAS
 * modifiés : chacun garde exactement les scopes qui lui ont été attribués en BO — c'est le
 * comportement de moindre privilège attendu pour un compte nommé, un nouveau scope ne doit pas
 * apparaître dans la poche de quelqu'un sans décision explicite.
 *
 * Pas de modification de schéma : aucune table n'est créée (les fils/messages/avis vivent dans
 * des tables déjà présentes, natives PrestaShop ou du module rbreviews).
 *
 * @param RebuildConnector $module
 */
function upgrade_module_1_18_0($module): bool
{
    if (!($module instanceof RebuildConnector)) {
        return false;
    }

    $settingsService = new SettingsService();
    $settingsService->ensureDefaults();

    $scopes = $settingsService->getScopes();
    $scopes[] = 'sav.read';
    $scopes[] = 'sav.write';
    $scopes[] = 'reviews.moderate';
    $settingsService->setScopes($scopes);

    return true;
}
