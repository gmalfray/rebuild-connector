<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade 1.8.0 — Scaffold génération d'étiquettes de transport
 *
 * Nouveautés :
 *   - Nouveau service ShippingLabelGenerator : détecte le transporteur, vérifie l'idempotence
 *     (étiquette déjà présente → pas de double génération), dispatche vers generateColissimo()
 *     ou generateMondialRelay() (marqués TODO en attente des sources modules + credentials transporteurs).
 *   - Nouveau endpoint POST /orders/{id}/shipping-label (scope orders.write) :
 *       200  — étiquette déjà disponible (label_ready: true, generated: false)
 *       201  — étiquette générée (label_ready: true, generated: true) [futur]
 *       422  — transporteur non reconnu
 *       501  — transporteur détecté mais génération non configurée (état actuel Colissimo / MR)
 *       502  — erreur webservice transporteur [futur]
 *   - Alignement config.xml sur la version réelle du module (était 1.1.6, maintenant 1.8.0).
 *
 * Pas de modification de schéma de base de données dans cette version.
 *
 * @param RebuildConnector $module
 */
function upgrade_module_1_8_0($module)
{
    if (!($module instanceof RebuildConnector)) {
        return false;
    }

    return true;
}
