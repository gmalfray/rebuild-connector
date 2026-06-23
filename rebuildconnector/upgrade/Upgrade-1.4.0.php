<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade 1.4.0 — Endpoint bordereau d'expédition
 *
 * Nouveautés :
 *   - Nouveau service ShippingLabelService : lecture PDF Colissimo (fichier local)
 *     et proxy cURL Mondial Relay (URL distante).
 *   - Endpoint GET orders/{id}/shipping-label (action=shipping-label) — stream PDF,
 *     scope orders.read, contrôle id_shop (IDOR).
 *   - Champ shipping_label ajouté au détail commande (GET orders/{id}) :
 *       { "has_shipping_label": bool, "carrier_type": "colissimo"|"mondialrelay"|null }
 *
 * Pas de modification de schéma de base de données dans cette version.
 *
 * @param RebuildConnector $module
 */
function upgrade_module_1_4_0($module)
{
    if (!($module instanceof RebuildConnector)) {
        return false;
    }

    return true;
}
