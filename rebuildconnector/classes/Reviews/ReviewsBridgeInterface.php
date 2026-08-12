<?php

defined('_PS_VERSION_') || exit;

/**
 * Contrat du pont vers le module tiers rbreviews.
 *
 * AUCUNE méthode ne référence de type rbreviews (ex. `RbReview`) dans sa signature : les routes
 * publiques (`ReviewsController`) ne manipulent que des tableaux neutres. C'est ce qui permet à
 * `ReviewsController` de fonctionner (et de compiler/s'autoloader) que rbreviews soit présent ou
 * non : seule l'implémentation concrète (`RbReviewsBridge`), chargée UNIQUEMENT quand rbreviews
 * est confirmé présent, connaît les classes réelles du module.
 */
interface ReviewsBridgeInterface
{
    /**
     * rbreviews est-il installé ET actif sur cette boutique, maintenant ?
     */
    public function isAvailable(): bool;

    /**
     * File de modération : avis en attente (`validated=0, deleted=0`), plus récents d'abord.
     *
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     * @throws ReviewsUnavailableException si rbreviews n'est pas disponible.
     */
    public function getPendingReviews(int $limit, int $offset, int $idShop): array;

    /**
     * Publie un avis (`validated = 1`). `null` si l'avis est introuvable / autre boutique.
     *
     * @return array<string, mixed>|null
     * @throws ReviewsUnavailableException si rbreviews n'est pas disponible.
     */
    public function publish(int $idReview, int $idShop): ?array;

    /**
     * Met un avis à la corbeille AVEC motif obligatoire (`deleted=1, validated=0,
     * rejection_reason=$reason`), puis notifie l'auteur (obligation L111-7-2). `null` si l'avis
     * est introuvable / autre boutique.
     *
     * Le motif est supposé DÉJÀ validé (non vide, longueur minimale) par l'appelant : cette
     * méthode revalide quand même en défense en profondeur (elle doit rester sûre à appeler
     * seule), mais l'UX 422 « avant toute écriture » est portée par `ReviewsController`.
     *
     * @return array{review: array<string, mixed>, author_notified: bool}|null
     * @throws ReviewsUnavailableException si rbreviews n'est pas disponible.
     * @throws \InvalidArgumentException si le motif est vide ou trop court.
     */
    public function trash(int $idReview, int $idShop, string $reason): ?array;

    /**
     * Pose une réponse publique du marchand (`reply`). `null` si l'avis est introuvable / autre
     * boutique.
     *
     * @return array<string, mixed>|null
     * @throws ReviewsUnavailableException si rbreviews n'est pas disponible.
     */
    public function reply(int $idReview, int $idShop, string $reply): ?array;
}
