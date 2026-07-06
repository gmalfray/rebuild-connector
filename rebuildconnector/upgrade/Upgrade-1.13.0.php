<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/SettingsService.php';

/**
 * Upgrade 1.13.0 — Interrupteurs BO pour les notifications de commande
 *
 * Nouveautés :
 *   - Nouveau réglage BO `order_created_alerts_enabled` (panneau Hub push) : contrôle l'émission de
 *     la notification push `order.created` (hook `actionValidateOrder`).
 *   - Nouveau réglage BO `order_status_alerts_enabled` (panneau Hub push) : contrôle l'émission de
 *     la notification push `order.status.changed` (hook `actionOrderStatusPostUpdate`).
 *
 * Contrairement à `stock_low_alerts_enabled` (désactivé par défaut), ces deux réglages sont
 * ACTIVÉS PAR DÉFAUT : ces notifications existaient déjà avant l'introduction de ce réglage, une
 * mise à jour en place ne doit donc jamais les couper silencieusement.
 *
 * Aucun nouveau hook à enregistrer (actionValidateOrder et actionOrderStatusPostUpdate sont déjà
 * enregistrés depuis des versions antérieures) : cette upgrade ne fait qu'assurer la présence des
 * deux clés de réglage avec leur valeur par défaut, via SettingsService::ensureDefaults().
 *
 * @param RebuildConnector $module
 */
function upgrade_module_1_13_0($module)
{
    if (!($module instanceof RebuildConnector)) {
        return false;
    }

    $settingsService = new SettingsService();
    $settingsService->ensureDefaults();

    return true;
}
