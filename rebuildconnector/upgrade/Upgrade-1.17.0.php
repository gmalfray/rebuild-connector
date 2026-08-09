<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade 1.17.0 — Surveillance du tunnel de paiement
 *
 * Nouveautés :
 *   - Détection d'une panne d'encaissement (journal du module de paiement) et notification push
 *     `shop.payment.error` vers l'app PrestaFlow (≥ 0.43.0).
 *   - Enregistrement du hook `actionDispatcher`, qui sert d'horloge pauvre : PrestaShop n'a pas
 *     d'ordonnanceur, et la détection doit fonctionner sans cron système ni accès au serveur.
 *
 * Le hook DOIT être enregistré ici : `install()` n'est pas rejoué lors d'une mise à jour, et une
 * boutique déjà équipée resterait sans surveillance malgré le code présent.
 *
 * Pas de modification de schéma : l'état de la surveillance vit dans `ps_configuration`
 * (clé `REBUILDCONNECTOR_PAYWATCH`).
 *
 * @param RebuildConnector $module
 */
function upgrade_module_1_17_0($module)
{
    if (!($module instanceof RebuildConnector)) {
        return false;
    }

    // Idempotent : registerHook() ne duplique pas un enregistrement existant.
    return (bool) $module->registerHook('actionDispatcher');
}
