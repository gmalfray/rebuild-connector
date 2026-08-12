<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/ReviewsBridgeInterface.php';
require_once __DIR__ . '/ReviewsUnavailableException.php';

/**
 * Pont neutre utilisé quand rbreviews n'est pas installé/actif. `isAvailable()` répond `false` ;
 * toute autre méthode lève `ReviewsUnavailableException` (filet de sécurité : le contrôleur doit
 * avoir déjà répondu 409 via `isAvailable()` avant d'appeler quoi que ce soit d'autre ici).
 */
final class NullReviewsBridge implements ReviewsBridgeInterface
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function getPendingReviews(int $limit, int $offset, int $idShop): array
    {
        throw new ReviewsUnavailableException();
    }

    public function publish(int $idReview, int $idShop): ?array
    {
        throw new ReviewsUnavailableException();
    }

    public function trash(int $idReview, int $idShop, string $reason): ?array
    {
        throw new ReviewsUnavailableException();
    }

    public function reply(int $idReview, int $idShop, string $reply): ?array
    {
        throw new ReviewsUnavailableException();
    }
}
