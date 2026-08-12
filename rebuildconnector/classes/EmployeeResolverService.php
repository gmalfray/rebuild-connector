<?php

defined('_PS_VERSION_') || exit;

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/SettingsService.php';

/**
 * Résout l'employé auquel attribuer, côté boutique, une action effectuée pour le compte du
 * marchand (réponse SAV, mouvement de stock déclenché par le module...) à partir de l'identifiant
 * éventuellement porté par le jeton JWT.
 *
 * - Jeton d'un utilisateur nommé (`id_employee` > 0 dans le JWT) : c'est lui l'auteur, on va juste
 *   chercher son nom pour l'affichage.
 * - Jeton clé API globale (aucun `id_employee` porté) : repli sur l'employé configuré en BO
 *   (`SettingsService::getSavFallbackEmployeeId()`, un seul réglage partagé entre toutes les
 *   actions attribuées à la boutique plutôt qu'un réglage par fonctionnalité) s'il est configuré
 *   ET toujours actif, sinon le premier employé actif par ID croissant.
 *
 * Règle extraite de `SavService::resolveReplyEmployee()` (v1.19.0), qui l'utilisait seule à
 * l'origine pour attribuer les réponses SAV envoyées via l'app. Les mouvements de stock déclenchés
 * par le module (`StockMovementService`) appliquent la même règle plutôt que d'en définir une
 * propre : un jeton donné doit désigner le même employé quel que soit ce qu'il vient de faire.
 */
class EmployeeResolverService
{
    private SettingsService $settingsService;

    public function __construct(?SettingsService $settingsService = null)
    {
        $this->settingsService = $settingsService ?: new SettingsService();
    }

    /**
     * @return array{id: int, firstname: string, lastname: string}
     */
    public function resolve(?int $tokenEmployeeId): array
    {
        if ($tokenEmployeeId !== null && $tokenEmployeeId > 0) {
            return $this->fetchEmployeeIdentity($tokenEmployeeId);
        }

        $configuredFallbackId = $this->settingsService->getSavFallbackEmployeeId();

        return $this->fetchFallbackEmployeeIdentity($configuredFallbackId);
    }

    /**
     * @return array{id: int, firstname: string, lastname: string}
     */
    private function fetchEmployeeIdentity(int $idEmployee): array
    {
        $query = new DbQuery();
        $query->select('id_employee, firstname, lastname');
        $query->from('employee');
        $query->where('id_employee = ' . $idEmployee);

        $rows = Db::getInstance()->executeS($query);
        $rows = is_array($rows) ? $rows : [];

        if ($rows === []) {
            // Employé du jeton introuvable (supprimé entre-temps) : on garde son ID (comportement
            // historique, l'auteur reste "employee") mais sans nom à afficher.
            return ['id' => $idEmployee, 'firstname' => '', 'lastname' => ''];
        }

        return $this->rowToIdentity($rows[0]);
    }

    /**
     * @return array{id: int, firstname: string, lastname: string}
     */
    private function fetchFallbackEmployeeIdentity(int $configuredFallbackId): array
    {
        $query = new DbQuery();
        $query->select('id_employee, firstname, lastname');
        $query->from('employee');
        $query->where('active = 1');
        if ($configuredFallbackId > 0) {
            // Priorise l'employé configuré en BO s'il est toujours actif ; sinon, premier employé
            // actif par ID croissant. Jamais une exception, jamais id_employee = 0 tant qu'il
            // existe au moins un employé actif.
            $query->orderBy('(id_employee = ' . $configuredFallbackId . ') DESC, id_employee ASC');
        } else {
            $query->orderBy('id_employee ASC');
        }
        $query->limit(1);

        $rows = Db::getInstance()->executeS($query);
        $rows = is_array($rows) ? $rows : [];

        if ($rows === []) {
            // Edge case extrême : aucun employé actif en base. Aucune attribution valide n'est
            // matérialisable : dégradation vers id_employee = 0, uniquement dans ce cas limite,
            // plus jamais atteint en usage normal.
            return ['id' => 0, 'firstname' => '', 'lastname' => ''];
        }

        return $this->rowToIdentity($rows[0]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, firstname: string, lastname: string}
     */
    private function rowToIdentity(array $row): array
    {
        return [
            'id' => isset($row['id_employee']) ? (int) $row['id_employee'] : 0,
            'firstname' => isset($row['firstname']) ? trim((string) $row['firstname']) : '',
            'lastname' => isset($row['lastname']) ? trim((string) $row['lastname']) : '',
        ];
    }
}
