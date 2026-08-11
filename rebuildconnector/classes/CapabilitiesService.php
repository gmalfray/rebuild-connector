<?php

defined('_PS_VERSION_') || exit;

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/Reviews/ReviewsAvailability.php';

/**
 * Capacités de LA BOUTIQUE — à ne pas confondre avec les scopes du jeton (droit de l'utilisatrice).
 *
 * Une capacité répond à « cette boutique est-elle équipée pour ça ? », indépendamment de qui
 * pose la question : un jeton peut très bien porter le scope `reviews.moderate` sur une boutique
 * où rbreviews n'est pas installé (cf. `docs/app-avis-sav.md`, § « Capacité ≠ droit »). L'app
 * s'appuie sur ce bloc pour savoir quelles sections proposer, avant même de regarder ses scopes.
 */
class CapabilitiesService
{
    /**
     * @return array{reviews: bool, sav: bool}
     */
    public function getCapabilities(): array
    {
        return [
            // Fils clients natifs PrestaShop (ps_customer_thread/ps_customer_message) : aucun
            // module requis, toujours vrai.
            'sav' => true,
            // Vérifié à chaud à chaque appel — jamais mis en cache au-delà de la requête HTTP
            // courante, pour qu'une désinstallation de rbreviews soit vue immédiatement.
            'reviews' => ReviewsAvailability::isAvailable(),
        ];
    }
}
