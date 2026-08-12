<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/SettingsService.php';

/**
 * Upgrade 1.19.0 : notifications push SAV et avis
 *
 * Nouveautés :
 *   - `sav.message` : un message CLIENT arrive sur un fil SAV natif (`ps_customer_thread` /
 *     `ps_customer_message`). Branché sur le hook générique `actionObjectCustomerMessageAddAfter`
 *     que PrestaShop lève automatiquement à chaque `ObjectModel::add()`. Réglage BO
 *     `sav_message_alerts_enabled`, ACTIF par défaut.
 *   - `review.pending` : un avis natif (rbreviews) entre en file de modération. Branché sur
 *     `actionObjectRbReviewAddAfter`. Réglage BO `review_pending_alerts_enabled`, DÉSACTIVÉ par
 *     défaut (volume faible, cf. docs/app-avis-sav.md).
 *
 * Les DEUX hooks DOIVENT être enregistrés ici : `install()` n'est pas rejoué lors d'une mise à
 * jour, et une boutique déjà équipée resterait sans ces deux notifications malgré le code présent
 * (même raison que l'enregistrement de `actionDispatcher` en 1.17.0).
 *
 * `actionObjectRbReviewAddAfter` est enregistré INCONDITIONNELLEMENT, que rbreviews soit installé
 * ou non sur cette boutique : un hook enregistré pour une classe qui n'existe pas ne se déclenche
 * simplement jamais (aucun risque, aucun coût en dehors d'une ligne dans `ps_hook_module`).
 *
 * Pas de modification de schéma : ces deux notifications lisent des tables déjà présentes,
 * natives PrestaShop (`customer_message`) ou du module rbreviews (`rbreviews_review`).
 *
 * @param RebuildConnector $module
 */
function upgrade_module_1_19_0($module)
{
    if (!($module instanceof RebuildConnector)) {
        return false;
    }

    // Idempotent : registerHook() ne duplique pas un enregistrement existant.
    if (!$module->registerHook('actionObjectCustomerMessageAddAfter')) {
        return false;
    }

    if (!$module->registerHook('actionObjectRbReviewAddAfter')) {
        return false;
    }

    // Pose les deux nouveaux réglages BO (sav_message_alerts_enabled=true,
    // review_pending_alerts_enabled=false) sur une installation existante. ensureDefaults() est
    // idempotent et ne touche jamais un réglage déjà présent.
    $settingsService = new SettingsService();
    $settingsService->ensureDefaults();

    return true;
}
