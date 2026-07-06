<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade 1.11.0 — Alertes stock faible en push
 *
 * Nouveautés :
 *   - Nouveau hook `actionUpdateQuantity` → notification push `stock.low` (relayée par le hub push
 *     centralisé, canal Android `stock_low`) quand le stock d'un produit actif descend à ou sous
 *     son seuil de stock bas effectif (product_shop.low_stock_threshold, sinon
 *     ProductsService::DEFAULT_LOW_STOCK_THRESHOLD).
 *   - Nouvelle table `rebuildconnector_stock_alert` (StockAlertService) : anti-spam par état
 *     persistant — une seule alerte par franchissement descendant du seuil, réarmée quand le stock
 *     repasse au-dessus.
 *   - Nouveau réglage BO `stock_low_alerts_enabled` (désactivé par défaut) dans le panneau Hub push.
 *
 * Installation déjà en place : ce fichier enregistre le hook et crée la table manquants (install()
 * ne sera pas rejoué sur une mise à jour en place).
 *
 * @param RebuildConnector $module
 */
function upgrade_module_1_11_0($module)
{
    if (!($module instanceof RebuildConnector)) {
        return false;
    }

    require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/StockAlertService.php';

    if (!StockAlertService::install()) {
        return false;
    }

    if (!$module->registerHook('actionUpdateQuantity')) {
        return false;
    }

    return true;
}
