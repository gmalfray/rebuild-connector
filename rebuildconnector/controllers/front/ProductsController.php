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
                    $authPayload = $this->requireAuth(['products.write']);
                    $this->handlePatch($authPayload);
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
            $message = $this->isDevMode() ? $exception->getMessage() : $this->t('api.error.unexpected', [], 'Unexpected error occurred.');
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

    /**
     * @param array<string, mixed> $authPayload
     */
    private function handlePatch(array $authPayload = []): void
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
                $this->recordAuditEvent('products.stock.updated', [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'token_subject' => $authPayload['sub'] ?? null,
                ]);
                $this->dispatchWebhookEvent('product.stock.updated', [
                    'product_id' => (string) $productId,
                    'quantity' => $quantity,
                ]);
                break;
            case 'attributes':
                if (array_key_exists('active', $payload)) {
                    $normalizedActive = $this->normalizeBooleanValue($payload['active']);
                    if ($normalizedActive === null) {
                        throw new \InvalidArgumentException(
                            $this->t('products.error.invalid_active', [], 'The active field is invalid.')
                        );
                    }
                    $payload['active'] = $normalizedActive;
                }

                if (array_key_exists('price_tax_excl', $payload)) {
                    $rawPrice = $payload['price_tax_excl'];
                    if (!is_numeric($rawPrice)) {
                        throw new \InvalidArgumentException(
                            $this->t('products.error.invalid_price', [], 'The price_tax_excl field must be numeric.')
                        );
                    }
                    $payload['price_tax_excl'] = (float) $rawPrice;
                }

                if (!$this->getProductsService()->updateProduct($productId, $payload)) {
                    $this->jsonError(
                        'invalid_payload',
                        $this->t('products.error.invalid_payload', [], 'Invalid product payload.'),
                        400
                    );
                    return;
                }
                $changes = [];
                if (array_key_exists('active', $payload)) {
                    $changes['active'] = (bool) $payload['active'];
                }
                if (array_key_exists('price_tax_excl', $payload)) {
                    $changes['price_tax_excl'] = (float) $payload['price_tax_excl'];
                }
                $product = $this->getProductsService()->getProductById($productId);
                $this->recordAuditEvent('products.attributes.updated', [
                    'product_id' => $productId,
                    'changes' => $changes,
                    'token_subject' => $authPayload['sub'] ?? null,
                ]);
                $this->dispatchWebhookEvent('product.attributes.updated', [
                    'product_id' => (string) $productId,
                    'changes' => $changes,
                ]);
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

    /**
     * @param mixed $value
     */
    private function normalizeBooleanValue($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            if ($value === 1) {
                return true;
            }
            if ($value === 0) {
                return false;
            }

            return null;
        }

        if (is_float($value)) {
            if ((int) $value === 1) {
                return true;
            }
            if ((int) $value === 0) {
                return false;
            }

            return null;
        }

        if (is_string($value)) {
            $normalized = Tools::strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return null;
    }
}
