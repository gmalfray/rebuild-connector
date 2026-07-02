<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/BaseApiController.php';
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
                'product' => $product,
            ]);

            return;
        }

        $filters = [];

        $limitRaw = Tools::getValue('limit');
        if ($limitRaw !== false && $limitRaw !== '') {
            $filters['limit'] = $limitRaw;
        }

        $offsetRaw = Tools::getValue('offset');
        if ($offsetRaw !== false && $offsetRaw !== '') {
            $filters['offset'] = $offsetRaw;
        }

        // Le filtre "active" n'est appliqué que si le paramètre est explicitement fourni.
        // Tools::getValue retourne false quand le paramètre est absent — dans ce cas on ne filtre
        // pas sur active afin de retourner tous les produits (actifs + inactifs).
        $activeRaw = Tools::getValue('active');
        if ($activeRaw !== false && $activeRaw !== '') {
            $filters['active'] = $activeRaw;
        }

        $searchRaw = Tools::getValue('search');
        if ($searchRaw !== false && $searchRaw !== '') {
            $filters['search'] = $searchRaw;
        }

        $barcodeRaw = Tools::getValue('barcode');
        if ($barcodeRaw !== false && $barcodeRaw !== '') {
            $filters['barcode'] = $barcodeRaw;
        }

        $stockRaw = Tools::getValue('stock');
        if ($stockRaw !== false && $stockRaw !== '') {
            $filters['stock'] = $stockRaw;
        }

        $idsParam = Tools::getValue('ids');
        if (is_string($idsParam) && $idsParam !== '') {
            $filters['ids'] = array_filter(array_map('intval', explode(',', $idsParam)));
        } elseif (is_array($idsParam)) {
            $filters['ids'] = array_filter(array_map('intval', $idsParam));
        }

        $validStockValues = ['in_stock', 'out_of_stock', 'low_stock'];
        if (!empty($filters['stock']) && !in_array($filters['stock'], $validStockValues, true)) {
            throw new \InvalidArgumentException(
                $this->t(
                    'products.error.invalid_stock_filter',
                    [],
                    'Valeur du filtre stock invalide. Valeurs acceptées : in_stock, out_of_stock, low_stock.'
                )
            );
        }

        $products = $this->getProductsService()->getProducts($filters);
        $total = $this->getProductsService()->countProducts($filters);

        $this->renderJson([
            'products' => $products,
            'total' => $total,
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

                if (array_key_exists('ean13', $payload)) {
                    $rawEan13 = $payload['ean13'];
                    if (!is_string($rawEan13)) {
                        throw new \InvalidArgumentException(
                            $this->t('products.error.invalid_ean13', [], 'The ean13 field must be a string.')
                        );
                    }
                    $ean13 = trim($rawEan13);
                    if ($ean13 !== '' && !preg_match('/^[0-9]{1,13}$/', $ean13)) {
                        throw new \InvalidArgumentException(
                            $this->t(
                                'products.error.invalid_ean13_format',
                                [],
                                'The ean13 field must contain 1 to 13 digits, or be empty to clear it.'
                            )
                        );
                    }
                    $payload['ean13'] = $ean13;
                }

                if (array_key_exists('name', $payload)) {
                    $rawName = $payload['name'];
                    if (!is_string($rawName)) {
                        throw new \InvalidArgumentException(
                            $this->t('products.error.invalid_name', [], 'The name field must be a string.')
                        );
                    }
                    $name = trim($rawName);
                    if ($name === '' || !Validate::isCatalogName($name)) {
                        throw new \InvalidArgumentException(
                            $this->t('products.error.invalid_name', [], 'The name field is invalid.')
                        );
                    }
                    $payload['name'] = $name;
                }

                if (array_key_exists('description', $payload)) {
                    $rawDescription = $payload['description'];
                    if (!is_string($rawDescription) || !Validate::isCleanHtml($rawDescription)) {
                        throw new \InvalidArgumentException(
                            $this->t('products.error.invalid_description', [], 'The description field is invalid.')
                        );
                    }
                    $payload['description'] = $rawDescription;
                }

                if (array_key_exists('description_short', $payload)) {
                    $rawDescriptionShort = $payload['description_short'];
                    if (!is_string($rawDescriptionShort) || !Validate::isCleanHtml($rawDescriptionShort)) {
                        throw new \InvalidArgumentException(
                            $this->t(
                                'products.error.invalid_description_short',
                                [],
                                'The description_short field is invalid.'
                            )
                        );
                    }
                    $payload['description_short'] = $rawDescriptionShort;
                }

                if (array_key_exists('reference', $payload)) {
                    $rawReference = $payload['reference'];
                    if (!is_string($rawReference)) {
                        throw new \InvalidArgumentException(
                            $this->t('products.error.invalid_reference', [], 'The reference field must be a string.')
                        );
                    }
                    $reference = trim($rawReference);
                    if (Tools::strlen($reference) > 64 || !Validate::isReference($reference)) {
                        throw new \InvalidArgumentException(
                            $this->t('products.error.invalid_reference', [], 'The reference field is invalid.')
                        );
                    }
                    $payload['reference'] = $reference;
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
                if (array_key_exists('ean13', $payload)) {
                    $changes['ean13'] = (string) $payload['ean13'];
                }
                if (array_key_exists('name', $payload)) {
                    $changes['name'] = (string) $payload['name'];
                }
                if (array_key_exists('description', $payload)) {
                    $changes['description'] = (string) $payload['description'];
                }
                if (array_key_exists('description_short', $payload)) {
                    $changes['description_short'] = (string) $payload['description_short'];
                }
                if (array_key_exists('reference', $payload)) {
                    $changes['reference'] = (string) $payload['reference'];
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

        $product = $this->getProductsService()->getProductById($productId);

        $this->renderJson([
            'product' => $product,
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
