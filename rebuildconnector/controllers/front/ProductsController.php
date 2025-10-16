<?php

defined('_PS_VERSION_') || exit;

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/ProductsService.php';

class RebuildconnectorProductsModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    private ?ProductsService $productsService = null;

    public function initContent(): void
    {
        parent::initContent();

        $method = Tools::strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            switch ($method) {
                case 'GET':
                    $this->requireAuth(['products.read']);
                    $this->handleGet();
                    break;
                case 'PATCH':
                    $this->requireAuth(['products.write']);
                    $this->handlePatch();
                    break;
                default:
                    header('Allow: GET, PATCH');
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
        } catch (\InvalidArgumentException $exception) {
            $this->jsonError(
                'invalid_payload',
                $exception->getMessage(),
                400
            );
        } catch (\Throwable $exception) {
            $message = _PS_MODE_DEV_ ? $exception->getMessage() : $this->t('api.error.unexpected', [], 'Unexpected error during authentication.');
            $this->jsonError('server_error', $message, 500);
        }
    }

    private function handleGet(): void
    {
        $productId = (int) Tools::getValue('id_product', (int) Tools::getValue('id', 0));
        if ($productId > 0) {
            $product = $this->getProductsService()->getProductById($productId);
            if ($product === []) {
                $this->jsonError(
                    'not_found',
                    $this->t('products.error.not_found', [], 'Product not found.'),
                    404
                );
                return;
            }

            $this->renderJson([
                'data' => $product,
            ]);

            return;
        }

        $filters = [
            'limit' => Tools::getValue('limit'),
            'offset' => Tools::getValue('offset'),
            'active' => Tools::getValue('active'),
            'search' => Tools::getValue('search'),
        ];

        $idsParam = Tools::getValue('ids');
        if (is_string($idsParam) && $idsParam !== '') {
            $filters['ids'] = array_filter(array_map('intval', explode(',', $idsParam)));
        } elseif (is_array($idsParam)) {
            $filters['ids'] = array_filter(array_map('intval', $idsParam));
        }

        $products = $this->getProductsService()->getProducts($filters);

        $this->renderJson([
            'data' => $products,
        ]);
    }

    private function handlePatch(): void
    {
        $productId = (int) Tools::getValue('id_product', (int) Tools::getValue('id', 0));
        if ($productId <= 0) {
            throw new \InvalidArgumentException($this->t('products.error.not_found', [], 'Product not found.'));
        }

        $payload = $this->decodeRequestBody();
        $product = $this->getProductsService()->getProductById($productId);
        if ($product === []) {
            $this->jsonError(
                'not_found',
                $this->t('products.error.not_found', [], 'Product not found.'),
                404
            );
            return;
        }
        $action = Tools::getValue('action');
        if ($action === null && isset($payload['action'])) {
            $action = (string) $payload['action'];
        }
        $action = Tools::strtolower((string) $action);
        if ($action === '') {
            if (isset($payload['quantity'])) {
                $action = 'stock';
            } else {
                $action = 'attributes';
            }
        }

        switch ($action) {
            case 'stock':
                if (!isset($payload['quantity'])) {
                    throw new \InvalidArgumentException($this->t('api.error.invalid_payload', [], 'The provided data is invalid.'));
                }
                $quantity = (int) $payload['quantity'];
                $this->getProductsService()->updateStock($productId, $quantity);
                $product['quantity'] = $quantity;
                break;
            case 'attributes':
                if (!$this->getProductsService()->updateProduct($productId, $payload)) {
                    $this->jsonError(
                        'invalid_payload',
                        $this->t('products.error.invalid_payload', [], 'Invalid product payload.'),
                        400
                    );
                    return;
                }
                $product = $this->getProductsService()->getProductById($productId);
                break;
            default:
                throw new \InvalidArgumentException($this->t('products.error.invalid_action', [], 'Unsupported product action.'));
        }

        $this->renderJson([
            'data' => $product,
        ]);
    }

    private function getProductsService(): ProductsService
    {
        if ($this->productsService === null) {
            $this->productsService = new ProductsService();
        }

        return $this->productsService;
    }
}
