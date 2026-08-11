<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/ReviewsBridgeInterface.php';
require_once __DIR__ . '/ReviewsAvailability.php';
require_once __DIR__ . '/NullReviewsBridge.php';

/**
 * Service locator : décide s'il faut charger le pont réel (rbreviews présent) ou le pont neutre.
 *
 * C'est l'UNIQUE endroit du connecteur qui `require` conditionnellement `RbReviewsBridge.php` —
 * jamais en tête de `ReviewsController.php`, précisément pour que ce contrôleur reste chargeable
 * (et son autoload sans erreur) que rbreviews soit installé ou non sur la boutique.
 */
class ReviewsBridgeFactory
{
    public static function create(): ReviewsBridgeInterface
    {
        if (!ReviewsAvailability::isAvailable()) {
            return new NullReviewsBridge();
        }

        require_once __DIR__ . '/RbReviewsBridge.php';

        return new RbReviewsBridge();
    }
}
