<?php

defined('_PS_VERSION_') || exit;

/**
 * Levée quand une route avis est appelée alors que rbreviews n'est pas installé/actif.
 *
 * Filet de sécurité UNIQUEMENT : le contrôleur doit toujours appeler `isAvailable()` avant
 * d'appeler une autre méthode du pont et répondre 409 lui-même — cette exception ne doit donc
 * normalement jamais être atteinte en usage normal. Elle existe pour qu'un appel direct malgré
 * tout (bug, refactor futur) échoue de façon explicite plutôt que par une erreur SQL sur une
 * table absente.
 */
class ReviewsUnavailableException extends \RuntimeException
{
    public function __construct(string $message = 'The reviews module (rbreviews) is not installed or not enabled on this shop.')
    {
        parent::__construct($message);
    }
}
