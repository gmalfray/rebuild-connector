<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/BaseApiController.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/BasketsService.php';

class RebuildconnectorBasketsModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    private ?BasketsService $basketsService = null;

    public function initContent(): void
    {
        parent::initContent();

        $method = Tools::strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            if ($method !== 'GET') {
                header('Allow: GET');
                $this->jsonError(
                    'method_not_allowed',
                    $this->t('api.error.method_not_allowed', [], 'HTTP method not allowed.'),
                    405
                );
                return;
            }

            $this->requireAuth(['baskets.read']);
            $this->handleGet();
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

    private function handleGet(): void
    {
        $cartId = (int) Tools::getValue('id_cart', (int) Tools::getValue('id', 0));
        if ($cartId > 0) {
            $basket = $this->getBasketsService()->getBasketById($cartId);
            if ($basket === []) {
                $this->jsonError(
                    'not_found',
                    $this->t('baskets.error.not_found', [], 'Basket not found.'),
                    404
                );
                return;
            }

            $this->renderJson([
                'data' => $basket,
            ]);

            return;
        }

        $filters = [
            'limit' => Tools::getValue('limit'),
            'offset' => Tools::getValue('offset'),
            'customer_id' => Tools::getValue('customer_id'),
            'date_from' => Tools::getValue('date_from'),
            'date_to' => Tools::getValue('date_to'),
            'has_order' => Tools::getValue('has_order'),
            'abandoned_since_days' => Tools::getValue('abandoned_since_days'),
        ];

        $baskets = $this->getBasketsService()->getBaskets($filters);

        $this->renderJson([
            'data' => $baskets,
        ]);
    }

    private function getBasketsService(): BasketsService
    {
        if ($this->basketsService === null) {
            $this->basketsService = new BasketsService();
        }

        return $this->basketsService;
    }
}
