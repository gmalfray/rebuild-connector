<?php

declare(strict_types=1);

// Fichier de bootstrap SÉPARÉ de phpstan-bootstrap.php : PHP exige qu'une déclaration de
// namespace soit la toute première instruction du fichier, or phpstan-bootstrap.php contient déjà
// des centaines de lignes de classes en namespace global avant le point où ce stub serait
// nécessaire. Chargé en plus de phpstan-bootstrap.php par phpstan.neon.dist (bootstrapFiles) et
// par tests/bootstrap.php.

namespace PrestaShop\PrestaShop\Adapter;

/**
 * Stub de l'adaptateur cœur donnant accès au conteneur Symfony (`\PrestaShop\PrestaShop\Adapter\SymfonyContainer`).
 * `StockManager::saveMovement()` (src/Core/Stock/StockManager.php du cœur) l'utilise pour obtenir
 * le repository Doctrine qui écrit les mouvements de stock ; ce conteneur n'existe pas dans le
 * contexte d'un front controller de module, ce que `StockMovementService` détecte avant de
 * suppléer l'écriture. Voir `rebuildconnector/classes/StockMovementService.php`.
 */
class SymfonyContainer
{
    /**
     * Bascule de test : simule un contexte où le conteneur Symfony est disponible (ex. admin), pour
     * vérifier qu'aucun mouvement de stock n'est alors écrit en double par le module. `false` par
     * défaut, qui reproduit fidèlement le contexte front controller réel de ce module (constaté en
     * production : le conteneur n'y est jamais instancié, cf. StockMovementServiceTest).
     */
    public static bool $testAvailable = false;

    public static function getInstance(): ?self
    {
        return self::$testAvailable ? new self() : null;
    }
}
