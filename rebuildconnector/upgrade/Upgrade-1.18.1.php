<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade 1.18.1 : correctifs SAV (attribution employé + nom dans la réponse) + avertissement BO.
 *
 * Aucune migration de données : ni schéma, ni scopes.
 *   - `sav_fallback_employee_id` (nouveau réglage BO, cf. SettingsService) est absent tant qu'un
 *     admin ne l'a pas explicitement configuré. `getSavFallbackEmployeeId()` renvoie alors 0, ce
 *     qui déclenche la résolution automatique (premier employé actif). Rien à initialiser ici.
 *   - Les scopes des utilisateurs NOMMÉS existants (table `rebuildconnector_user`) restent
 *     volontairement inchangés, exactement comme en 1.18.0 (cf. Upgrade-1.18.0.php) : accorder
 *     `sav.write`/`reviews.moderate` sans décision explicite de l'admin serait une élévation de
 *     privilège silencieuse (envoi de vrais e-mails à de vraies clientes). Le BO affiche désormais
 *     un avertissement listant nommément les utilisateurs concernés (rebuildconnector.php,
 *     getContent()) tant que leurs scopes n'ont pas été mis à jour manuellement.
 *
 * @param RebuildConnector $module
 */
function upgrade_module_1_18_1($module): bool
{
    return $module instanceof RebuildConnector;
}
