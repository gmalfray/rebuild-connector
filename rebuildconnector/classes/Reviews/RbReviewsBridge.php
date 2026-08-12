<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/ReviewsBridgeInterface.php';
require_once __DIR__ . '/ReviewsUnavailableException.php';
require_once __DIR__ . '/ReviewsAvailability.php';

/**
 * Implémentation réelle du pont. SEUL fichier du connecteur qui connaît les classes du module
 * tiers rbreviews (`RbReview`). Instancié UNIQUEMENT par `ReviewsBridgeFactory::create()`, qui a
 * déjà vérifié `ReviewsAvailability::isAvailable()` avant de charger ce fichier : à ce stade,
 * `RbReview` est garantie autochargée par PrestaShop (convention `modules/<module>/classes/`,
 * tant que rbreviews est installé).
 *
 * Aucune méthode de CE fichier ne référence `RbReview` dans une signature exposée par l'interface
 * (cf. `ReviewsBridgeInterface`) : uniquement dans le corps des méthodes, en instanciation directe
 * (`new RbReview(...)`), jamais comme type de paramètre/retour visible de l'extérieur.
 */
final class RbReviewsBridge implements ReviewsBridgeInterface
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;

    /** Motif de refus minimum, aligné sur la validation déjà appliquée côté BO rbreviews. */
    public const REASON_MIN_LENGTH = 10;

    public function isAvailable(): bool
    {
        return ReviewsAvailability::isAvailable() && class_exists('RbReview');
    }

    public function getPendingReviews(int $limit, int $offset, int $idShop): array
    {
        $this->assertAvailable();

        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');

        $query = new DbQuery();
        $query->select(
            'r.id_rbreviews_review, r.id_product, r.display_name, r.email, r.title, r.content, '
            . 'r.grade, r.verified_buyer, r.date_add, pl.name AS product_name'
        );
        $query->from('rbreviews_review', 'r');
        $query->leftJoin(
            'product_lang',
            'pl',
            'pl.id_product = r.id_product AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = r.id_shop'
        );
        $query->where('r.validated = 0 AND r.deleted = 0');
        if ($idShop > 0) {
            $query->where('r.id_shop = ' . $idShop);
        }
        $query->orderBy('r.date_add DESC, r.id_rbreviews_review DESC');
        $query->limit($limit + 1, $offset);

        $rows = Db::getInstance()->executeS($query);
        $rows = is_array($rows) ? $rows : [];

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $items = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $items[] = $this->formatPendingRow($row);
        }

        return [
            'items' => $items,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($items),
                'has_next' => $hasMore,
                'next_offset' => $hasMore ? $offset + $limit : null,
            ],
        ];
    }

    public function publish(int $idReview, int $idShop): ?array
    {
        $this->assertAvailable();

        $review = $this->loadOwnedReview($idReview, $idShop);
        if ($review === null) {
            return null;
        }

        $review->validated = true;
        $review->date_upd = date('Y-m-d H:i:s');
        $review->update();

        return $this->formatReviewRow($review);
    }

    public function trash(int $idReview, int $idShop, string $reason): ?array
    {
        $this->assertAvailable();

        $reason = trim($reason);
        if (Tools::strlen($reason) < self::REASON_MIN_LENGTH) {
            throw new \InvalidArgumentException(
                'The rejection reason must be at least ' . self::REASON_MIN_LENGTH . ' characters long.'
            );
        }

        $review = $this->loadOwnedReview($idReview, $idShop);
        if ($review === null) {
            return null;
        }

        // Même ordre que la corbeille en BO (AdminRbReviewsController::processBulkRejectSelection) :
        // poser deleted/validated/rejection_reason puis SEULEMENT ENSUITE notifier l'auteur, jamais
        // l'inverse, sinon `notifyRejection()` lirait un `rejection_reason` pas encore en base.
        $review->deleted = true;
        $review->validated = false;
        $review->rejection_reason = $reason;
        $review->date_upd = date('Y-m-d H:i:s');
        $review->update();

        $notified = false;
        $module = Module::getInstanceByName(ReviewsAvailability::MODULE_NAME);
        if ($module instanceof Module) {
            try {
                $notified = (bool) $review->notifyRejection($module);
            } catch (\Throwable $exception) {
                // Même discipline que le BO rbreviews : un échec de notification (Exception OU
                // Error PHP 8, ex. TypeError) ne doit jamais faire échouer la mise en corbeille
                // elle-même : l'avis EST en corbeille, seule la notification a pu rater.
                if (defined('_PS_MODE_DEV_') && (bool) constant('_PS_MODE_DEV_')) {
                    error_log('[RebuildConnector] notifyRejection a échoué (avis #' . $idReview . '): ' . $exception->getMessage());
                }
            }
        }

        return [
            'review' => $this->formatReviewRow($review),
            'author_notified' => $notified,
        ];
    }

    public function reply(int $idReview, int $idShop, string $reply): ?array
    {
        $this->assertAvailable();

        $reply = trim($reply);
        if ($reply === '') {
            throw new \InvalidArgumentException('The reply field is required.');
        }

        $review = $this->loadOwnedReview($idReview, $idShop);
        if ($review === null) {
            return null;
        }

        $review->reply = $reply;
        $review->date_upd = date('Y-m-d H:i:s');
        $review->update();

        return $this->formatReviewRow($review);
    }

    private function resolveProductName(int $idProduct, int $idLang, int $idShop): ?string
    {
        if ($idProduct <= 0) {
            return null;
        }

        $query = new DbQuery();
        $query->select('pl.name');
        $query->from('product_lang', 'pl');
        $query->where('pl.id_product = ' . $idProduct);
        $query->where('pl.id_lang = ' . $idLang);
        if ($idShop > 0) {
            $query->where('pl.id_shop = ' . $idShop);
        }

        $name = Db::getInstance()->getValue($query);

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new ReviewsUnavailableException();
        }
    }

    /**
     * Charge un avis et vérifie son appartenance à la boutique courante (protection IDOR). Même
     * garde que partout ailleurs dans le connecteur : une absence ou une mauvaise boutique
     * retournent toutes les deux `null`, jamais une distinction qui confirmerait l'existence d'un
     * avis d'une autre boutique.
     *
     * @return \RbReview|null
     */
    private function loadOwnedReview(int $idReview, int $idShop)
    {
        if ($idReview <= 0) {
            return null;
        }

        $exists = Db::getInstance()->getValue(
            'SELECT id_rbreviews_review FROM `' . _DB_PREFIX_ . 'rbreviews_review`'
            . ' WHERE id_rbreviews_review = ' . $idReview
            . ($idShop > 0 ? ' AND id_shop = ' . $idShop : '')
        );
        if (empty($exists)) {
            return null;
        }

        $review = new \RbReview($idReview);

        return $review;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatPendingRow(array $row): array
    {
        return [
            'id' => (int) $row['id_rbreviews_review'],
            'product' => [
                'id' => (int) $row['id_product'],
                'name' => isset($row['product_name']) ? (string) $row['product_name'] : null,
            ],
            'author' => [
                'name' => (string) $row['display_name'],
                'email' => (string) ($row['email'] ?? ''),
            ],
            'grade' => (int) $row['grade'],
            'title' => (string) ($row['title'] ?? ''),
            'content' => (string) ($row['content'] ?? ''),
            'verified_buyer' => (bool) $row['verified_buyer'],
            'date_add' => (string) $row['date_add'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatReviewRow(\RbReview $review): array
    {
        $idLang = (int) ($review->id_lang ?: Configuration::get('PS_LANG_DEFAULT'));
        $idShop = (int) ($review->id_shop ?: 0);
        $productName = $this->resolveProductName((int) $review->id_product, $idLang, $idShop);

        return [
            'id' => (int) $review->id,
            'product' => [
                'id' => (int) $review->id_product,
                'name' => $productName,
            ],
            'author' => [
                'name' => (string) $review->display_name,
                'email' => (string) $review->email,
            ],
            'grade' => (int) $review->grade,
            'title' => (string) $review->title,
            'content' => (string) $review->content,
            'verified_buyer' => (bool) $review->verified_buyer,
            'validated' => (bool) $review->validated,
            'deleted' => (bool) $review->deleted,
            'reply' => $review->reply !== null ? (string) $review->reply : null,
            'rejection_reason' => $review->rejection_reason !== null ? (string) $review->rejection_reason : null,
            'date_add' => (string) $review->date_add,
        ];
    }
}
