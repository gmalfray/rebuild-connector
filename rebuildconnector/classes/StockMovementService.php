<?php

defined('_PS_VERSION_') || exit;

/**
 * Écrit dans `stock_mvt` les mouvements de stock déclenchés par le module, pour que l'onglet
 * Mouvements du back-office reflète les écritures faites depuis l'app au même titre qu'une saisie
 * manuelle en BO.
 *
 * `StockAvailable::setQuantity()` (appelé par `ProductsService::updateStock()` et
 * `ProductsService::applyStockDelta()`) délègue l'écriture du mouvement à
 * `StockManager::saveMovement()` (`src/Core/Stock/StockManager.php` du cœur), qui a besoin du
 * conteneur Symfony pour obtenir le repository Doctrine qui persiste la ligne
 * (`prestashop.core.api.stock_movement.repository`). Un front controller de module ne dispose
 * jamais de ce conteneur : `saveMovement()` s'arrête alors sur son propre `return false`, sans
 * lever d'exception ni journaliser quoi que ce soit. Le stock est mis à jour, le mouvement jamais
 * écrit. Constaté en production : `ps_stock_mvt` s'arrête au 19/07/2026, dernier mouvement posé
 * par une saisie BO (conteneur disponible en contexte admin), alors que l'app écrit du stock
 * depuis sans que rien n'y apparaisse.
 *
 * Cette classe supplée uniquement la partie qui échoue faute de conteneur : elle réutilise la même
 * table, les mêmes colonnes et le même motif que le cœur, via la classe cœur `StockMvt`
 * (`classes/stock/StockMvt.php`, un `ObjectModel` classique qui écrit par `Db`, sans dépendance au
 * conteneur Symfony) plutôt que le repository Doctrine hors de portée ici. Le motif retenu
 * (`PS_STOCK_MVT_INC_EMPLOYEE_EDITION` / `PS_STOCK_MVT_DEC_EMPLOYEE_EDITION` selon le signe) est
 * exactement celui que choisit `StockRepository::updateStock()`, la classe derrière la page Stocks
 * du back-office, pour une saisie manuelle d'employé : une mise à jour venue de l'app est la même
 * catégorie de mouvement qu'une saisie BO (ni une vente, ni une commande fournisseur), elle doit
 * porter le même motif pour que l'onglet Mouvements reste homogène plutôt que de faire apparaître
 * un motif propre au module que personne ne reconnaîtrait dans cette liste.
 */
class StockMovementService
{
    /**
     * N'écrit rien si le cœur a déjà pu le faire lui-même (conteneur Symfony disponible, par
     * exemple si cette méthode finissait par être appelée depuis un contexte admin), pour ne
     * jamais produire deux lignes pour un seul mouvement. N'écrit rien non plus pour un delta nul
     * (rien à tracer) ni sans ligne `stock_available` identifiée (le mouvement n'aurait rien à
     * référencer).
     *
     * @param array{id: int, firstname: string, lastname: string} $employeeIdentity
     */
    public function recordIfNeeded(int $idStockAvailable, int $deltaQuantity, array $employeeIdentity): void
    {
        if ($deltaQuantity === 0 || $idStockAvailable <= 0) {
            return;
        }

        if ($this->coreContainerIsAvailable()) {
            return;
        }

        try {
            $this->writeMovement($idStockAvailable, $deltaQuantity, $employeeIdentity);
        } catch (\Throwable $exception) {
            // La mise à jour du stock elle-même est déjà appliquée à ce stade : un échec ici ne
            // doit annuler ni la remonter à l'appelant, seulement le mouvement est perdu. Mais il
            // ne doit pas non plus disparaître sans laisser de trace comme le fait
            // StockManager::saveMovement() en cœur : error_log() est volontairement appelé ici
            // sans passer par le journal habituel du module (BaseApiController::log(), qui
            // n'écrit qu'en mode dev) : un échec silencieux en production est exactement ce qui a
            // masqué ce défaut pendant des mois, il doit rester visible même hors mode dev.
            error_log(sprintf(
                '[RebuildConnector] Stock movement write failed (id_stock_available=%d, delta=%d): %s',
                $idStockAvailable,
                $deltaQuantity,
                $exception->getMessage()
            ));
        }
    }

    /**
     * Reproduit la vérification faite par `StockManager::saveMovement()` lui-même
     * (`SymfonyContainer::getInstance() === null`) : si le conteneur est disponible, le cœur a déjà
     * réussi à écrire le mouvement au moment de l'appel à `StockAvailable::setQuantity()`, il ne
     * faut pas en écrire un second.
     */
    private function coreContainerIsAvailable(): bool
    {
        if (!class_exists(\PrestaShop\PrestaShop\Adapter\SymfonyContainer::class)) {
            return false;
        }

        return \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance() !== null;
    }

    /**
     * @param array{id: int, firstname: string, lastname: string} $employeeIdentity
     */
    private function writeMovement(int $idStockAvailable, int $deltaQuantity, array $employeeIdentity): void
    {
        $sign = $deltaQuantity >= 1 ? 1 : -1;
        $reasonKey = $sign === 1 ? 'PS_STOCK_MVT_INC_EMPLOYEE_EDITION' : 'PS_STOCK_MVT_DEC_EMPLOYEE_EDITION';

        $movement = new StockMvt();
        $movement->id_stock = $idStockAvailable;
        $movement->id_stock_mvt_reason = (int) Configuration::get($reasonKey);
        $movement->id_employee = $employeeIdentity['id'];
        $movement->employee_firstname = $employeeIdentity['firstname'];
        $movement->employee_lastname = $employeeIdentity['lastname'];
        $movement->physical_quantity = abs($deltaQuantity);
        $movement->sign = $sign;
        $movement->price_te = 0.0;
        $movement->date_add = date('Y-m-d H:i:s');

        if (!$movement->add()) {
            error_log(sprintf(
                '[RebuildConnector] Stock movement insert failed (id_stock_available=%d, delta=%d).',
                $idStockAvailable,
                $deltaQuantity
            ));
        }
    }
}
