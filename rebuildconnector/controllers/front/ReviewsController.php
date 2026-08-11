<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/BaseApiController.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/Reviews/ReviewsBridgeFactory.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/Reviews/ReviewsUnavailableException.php';

/**
 * PONT vers le module tiers rbreviews — jamais d'accès direct aux tables `rbreviews_*` ici,
 * uniquement via `ReviewsBridgeInterface` (cf. `docs/app-avis-sav.md`, § « Où vit l'API des avis »).
 *
 * Si rbreviews n'est pas installé/actif : toutes les routes répondent 409 `reviews_unavailable`,
 * jamais une erreur SQL sur une table absente.
 *
 * Motif de rejet TOUJOURS obligatoire (article L111-7-2) : validé ICI (422, avant toute écriture)
 * en plus de la revalidation défensive dans `RbReviewsBridge::trash()`.
 */
class RebuildconnectorReviewsModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    private const DEFAULT_LIMIT = 20;

    /** Aligné sur `RbReviewsBridge::REASON_MIN_LENGTH` (et sur la validation déjà en place côté BO rbreviews). */
    private const REASON_MIN_LENGTH = 10;

    private ?ReviewsBridgeInterface $reviewsBridge = null;

    public function initContent(): void
    {
        parent::initContent();

        $method = Tools::strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            $bridge = $this->getReviewsBridge();
            if (!$bridge->isAvailable()) {
                $this->jsonError(
                    'reviews_unavailable',
                    $this->t('reviews.error.unavailable', [], 'The reviews module is not installed or not enabled on this shop.'),
                    409
                );
                return;
            }

            switch ($method) {
                case 'GET':
                    $this->requireAuth(['reviews.moderate']);
                    $this->handleGet($bridge);
                    break;
                case 'POST':
                    $authPayload = $this->requireAuth(['reviews.moderate']);
                    $this->handlePost($bridge, $authPayload);
                    break;
                default:
                    // @ : header() peut émettre un warning "headers already sent" hors contexte HTTP réel
                    // (ex. exécution CLI PHPUnit où du texte a déjà été écrit sur stdout) ; sans impact
                    // en production (le header Allow est informatif sur une 405).
                    @header('Allow: GET, POST');
                    $this->jsonError(
                        'method_not_allowed',
                        $this->t('api.error.method_not_allowed', [], 'HTTP method not allowed.'),
                        405
                    );
                    return;
            }
        } catch (AuthenticationException $exception) {
            $this->jsonError(
                'unauthenticated',
                $this->t('api.error.unauthenticated', [], 'Authentication required.'),
                401
            );
        } catch (AuthorizationException $exception) {
            $this->jsonError(
                'forbidden',
                $this->t('api.error.forbidden', [], 'You do not have the required permissions.'),
                403
            );
        } catch (ReviewsUnavailableException $exception) {
            // Filet de sécurité : ne devrait jamais être atteint (isAvailable() a déjà été vérifié
            // ci-dessus), cf. ReviewsUnavailableException.
            $this->jsonError(
                'reviews_unavailable',
                $this->t('reviews.error.unavailable', [], 'The reviews module is not installed or not enabled on this shop.'),
                409
            );
        } catch (\InvalidArgumentException $exception) {
            $this->jsonError(
                'invalid_payload',
                $exception->getMessage(),
                400
            );
        } catch (\Throwable $exception) {
            $message = $this->isDevMode() ? $exception->getMessage() : $this->t('api.error.unexpected', [], 'Unexpected error occurred.');
            $this->jsonError('server_error', $message, 500);
        }
    }

    private function handleGet(ReviewsBridgeInterface $bridge): void
    {
        $limit = $this->parsePositiveInt(Tools::getValue('limit'), self::DEFAULT_LIMIT);
        $offset = $this->parseNonNegativeInt(Tools::getValue('offset'), 0);

        $result = $bridge->getPendingReviews($limit, $offset, $this->getCurrentShopId());

        $this->renderJson([
            'reviews' => $result['items'],
            'pagination' => $result['pagination'],
        ]);
    }

    /**
     * @param array<string, mixed> $authPayload
     */
    private function handlePost(ReviewsBridgeInterface $bridge, array $authPayload): void
    {
        $reviewId = (int) Tools::getValue('id', 0);
        if ($reviewId <= 0) {
            $this->jsonError('not_found', $this->t('reviews.error.not_found', [], 'Review not found.'), 404);
            return;
        }

        $action = Tools::strtolower((string) Tools::getValue('action', ''));
        $idShop = $this->getCurrentShopId();

        switch ($action) {
            case 'publish':
                $review = $bridge->publish($reviewId, $idShop);
                if ($review === null) {
                    $this->jsonError('not_found', $this->t('reviews.error.not_found', [], 'Review not found.'), 404);
                    return;
                }
                $this->recordAuditEvent('reviews.published', [
                    'review_id' => $reviewId,
                    'token_subject' => $authPayload['sub'] ?? null,
                ]);
                $this->dispatchWebhookEvent('review.published', ['review_id' => (string) $reviewId]);
                $this->renderJson(['review' => $review]);
                return;

            case 'trash':
                $payload = $this->decodeRequestBody();
                $reason = isset($payload['reason']) ? trim((string) $payload['reason']) : '';
                // 422 AVANT toute écriture : aucune route ne doit permettre un rejet sans motif.
                if (Tools::strlen($reason) < self::REASON_MIN_LENGTH) {
                    $this->jsonError(
                        'invalid_rejection_reason',
                        $this->t(
                            'reviews.error.invalid_rejection_reason',
                            [],
                            'A rejection reason of at least 10 characters is required.'
                        ),
                        422
                    );
                    return;
                }

                $result = $bridge->trash($reviewId, $idShop, $reason);
                if ($result === null) {
                    $this->jsonError('not_found', $this->t('reviews.error.not_found', [], 'Review not found.'), 404);
                    return;
                }
                // Jamais le motif ni l'e-mail de l'auteur dans l'audit — uniquement des identifiants.
                $this->recordAuditEvent('reviews.trashed', [
                    'review_id' => $reviewId,
                    'author_notified' => $result['author_notified'],
                    'token_subject' => $authPayload['sub'] ?? null,
                ]);
                $this->dispatchWebhookEvent('review.trashed', ['review_id' => (string) $reviewId]);
                $this->renderJson([
                    'review' => $result['review'],
                    'author_notified' => $result['author_notified'],
                ]);
                return;

            case 'reply':
                $payload = $this->decodeRequestBody();
                $reply = isset($payload['reply']) ? trim((string) $payload['reply']) : '';
                if ($reply === '') {
                    throw new \InvalidArgumentException($this->t('reviews.error.reply_required', [], 'A reply is required.'));
                }

                $review = $bridge->reply($reviewId, $idShop, $reply);
                if ($review === null) {
                    $this->jsonError('not_found', $this->t('reviews.error.not_found', [], 'Review not found.'), 404);
                    return;
                }
                $this->recordAuditEvent('reviews.replied', [
                    'review_id' => $reviewId,
                    'token_subject' => $authPayload['sub'] ?? null,
                ]);
                $this->dispatchWebhookEvent('review.replied', ['review_id' => (string) $reviewId]);
                $this->renderJson(['review' => $review]);
                return;

            default:
                throw new \InvalidArgumentException($this->t('reviews.error.invalid_action', [], 'Unsupported review action.'));
        }
    }

    private function getReviewsBridge(): ReviewsBridgeInterface
    {
        if ($this->reviewsBridge === null) {
            $this->reviewsBridge = ReviewsBridgeFactory::create();
        }

        return $this->reviewsBridge;
    }

    private function getCurrentShopId(): int
    {
        $context = Context::getContext();

        return $context->shop instanceof Shop ? (int) $context->shop->id : 0;
    }

    /**
     * @param mixed $value
     */
    private function parsePositiveInt($value, int $default): int
    {
        if ($value === null || $value === '' || $value === false) {
            return $default;
        }
        if (!is_numeric($value) || (int) $value <= 0) {
            throw new \InvalidArgumentException($this->t('reviews.error.invalid_limit', [], 'Limit must be a positive integer.'));
        }

        return (int) $value;
    }

    /**
     * @param mixed $value
     */
    private function parseNonNegativeInt($value, int $default): int
    {
        if ($value === null || $value === '' || $value === false) {
            return $default;
        }
        if (!is_numeric($value) || (int) $value < 0) {
            throw new \InvalidArgumentException($this->t('reviews.error.invalid_offset', [], 'Offset must be a non-negative integer.'));
        }

        return (int) $value;
    }
}
