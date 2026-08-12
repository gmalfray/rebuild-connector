<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/BaseApiController.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/ProductsService.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/EmployeeResolverService.php';

class RebuildconnectorProductsModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    private ?ProductsService $productsService = null;
    private ?EmployeeResolverService $employeeResolverService = null;

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
                    // L'action (stock ou attributes) détermine le scope requis : `stock.write` pour
                    // le stock, `products.write` pour le reste. Le corps est donc décodé UNE FOIS
                    // ici, avant l'authentification, pour connaître l'action à autoriser ; handlePatch()
                    // le reçoit déjà décodé plutôt que de le relire.
                    $payload = $this->decodeRequestBody();
                    $action = $this->resolvePatchAction($payload);
                    $requiredScope = $action === 'stock' ? 'stock.write' : 'products.write';
                    $authPayload = $this->requireAuth([$requiredScope]);
                    $this->handlePatch($authPayload, $payload, $action);
                    break;
                default:
                    // @ : header() peut émettre un warning "headers already sent" hors contexte HTTP réel
                    // (ex. exécution CLI PHPUnit où du texte a déjà été écrit sur stdout) ; sans impact
                    // en production (le header Allow est informatif sur une 405).
                    @header('Allow: GET, PATCH');
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
        // Tools::getValue retourne false quand le paramètre est absent : dans ce cas on ne filtre
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
     * @param array<string, mixed> $payload
     */
    private function handlePatch(array $authPayload, array $payload, string $action): void
    {
        $productId = (int) Tools::getValue('id_product', (int) Tools::getValue('id', 0));
        if ($productId <= 0) {
            throw new \InvalidArgumentException($this->t('products.error.not_found', [], 'Product not found.'));
        }

        $product = $this->getProductsService()->getProductById($productId);
        if ($product === []) {
            $this->jsonError(
                'not_found',
                $this->t('products.error.not_found', [], 'Product not found.'),
                404
            );
            return;
        }

        $resultingQuantity = null;

        switch ($action) {
            case 'stock':
                $hasQuantity = array_key_exists('quantity', $payload) && $payload['quantity'] !== null;
                $hasDelta = array_key_exists('delta', $payload) && $payload['delta'] !== null;

                if ($hasQuantity && $hasDelta) {
                    throw new \InvalidArgumentException(
                        $this->t(
                            'products.error.stock_quantity_and_delta',
                            [],
                            'Provide either quantity or delta, not both.'
                        )
                    );
                }

                if (!$hasQuantity && !$hasDelta) {
                    throw new \InvalidArgumentException($this->t('api.error.invalid_payload', [], 'The provided data is invalid.'));
                }

                $combinationId = 0;
                if (array_key_exists('combination_id', $payload) && $payload['combination_id'] !== null) {
                    if (!is_numeric($payload['combination_id'])) {
                        throw new \InvalidArgumentException(
                            $this->t(
                                'products.error.invalid_combination_id',
                                [],
                                'The combination_id field must be numeric.'
                            )
                        );
                    }
                    $combinationId = (int) $payload['combination_id'];
                    if ($combinationId < 0) {
                        throw new \InvalidArgumentException(
                            $this->t(
                                'products.error.invalid_combination_id',
                                [],
                                'The combination_id field must be numeric.'
                            )
                        );
                    }
                }

                // Même résolution que pour l'attribution d'une réponse SAV (EmployeeResolverService,
                // extrait de SavService) : un utilisateur nommé porte son propre id_employee dans le
                // JWT, une clé API globale retombe sur l'employé de repli configuré en BO (ou, à
                // défaut, le premier employé actif). Sert à tracer QUI a fait ce mouvement de stock.
                $idEmployee = isset($authPayload['id_employee']) ? (int) $authPayload['id_employee'] : null;
                $employeeIdentity = $this->getEmployeeResolverService()->resolve($idEmployee);

                if ($hasDelta) {
                    if (!is_numeric($payload['delta'])) {
                        throw new \InvalidArgumentException(
                            $this->t('products.error.invalid_delta', [], 'The delta field must be numeric.')
                        );
                    }
                    $delta = (int) $payload['delta'];

                    // applyStockDelta() ne renvoie null ici que si combination_id est fourni (> 0)
                    // mais n'appartient pas au produit visé (le produit lui-même a déjà été validé
                    // plus haut) : il ne faut pas incrémenter le stock d'une déclinaison étrangère à
                    // cette commande PATCH.
                    $resultingQuantity = $this->getProductsService()->applyStockDelta($productId, $delta, $combinationId, $employeeIdentity);
                    if ($resultingQuantity === null) {
                        $this->jsonError(
                            'invalid_payload',
                            $this->t(
                                'products.error.invalid_combination',
                                [],
                                'The combination_id field does not belong to this product.'
                            ),
                            400
                        );
                        return;
                    }
                } else {
                    $quantity = (int) $payload['quantity'];

                    // updateStock() ne renvoie false ici que si combination_id est fourni (> 0) mais
                    // n'appartient pas au produit visé (le produit lui-même a déjà été validé plus haut) :
                    // il ne faut pas écraser le stock d'une déclinaison étrangère à cette commande PATCH.
                    if (!$this->getProductsService()->updateStock($productId, $quantity, $combinationId, $employeeIdentity)) {
                        $this->jsonError(
                            'invalid_payload',
                            $this->t(
                                'products.error.invalid_combination',
                                [],
                                'The combination_id field does not belong to this product.'
                            ),
                            400
                        );
                        return;
                    }

                    $resultingQuantity = $quantity;
                }

                $this->recordAuditEvent('products.stock.updated', [
                    'product_id' => $productId,
                    'quantity' => $resultingQuantity,
                    'delta' => $hasDelta ? $delta : null,
                    'combination_id' => $combinationId > 0 ? $combinationId : null,
                    'token_subject' => $authPayload['sub'] ?? null,
                ]);
                $this->dispatchWebhookEvent('product.stock.updated', [
                    'product_id' => (string) $productId,
                    'quantity' => $resultingQuantity,
                    'combination_id' => $combinationId > 0 ? $combinationId : null,
                ]);
                break;
            case 'attributes':
                if (array_key_exists('combination_id', $payload) && $payload['combination_id'] !== null) {
                    if (!is_numeric($payload['combination_id'])) {
                        throw new \InvalidArgumentException(
                            $this->t(
                                'products.error.invalid_combination_id',
                                [],
                                'The combination_id field must be numeric.'
                            )
                        );
                    }
                    $payload['combination_id'] = (int) $payload['combination_id'];
                    if ($payload['combination_id'] <= 0) {
                        throw new \InvalidArgumentException(
                            $this->t(
                                'products.error.invalid_combination_id',
                                [],
                                'The combination_id field must be numeric.'
                            )
                        );
                    }
                }

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
                if (array_key_exists('combination_id', $payload)) {
                    $changes['combination_id'] = (int) $payload['combination_id'];
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

        $responsePayload = ['product' => $product];
        if ($action === 'stock') {
            // Quantité résultante exposée explicitement : pour une déclinaison, la fiche produit
            // rechargée ci-dessus n'expose que la quantité niveau produit (matched_combination est
            // toujours null sur ce endpoint, résolu par id_product et non par barcode), donc pas
            // fiable pour afficher le stock réel d'un id_product_attribute précis sans relire.
            $responsePayload['quantity'] = $resultingQuantity;
        }

        $this->renderJson($responsePayload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolvePatchAction(array $payload): string
    {
        $action = Tools::getValue('action');
        if ($action === false && isset($payload['action'])) {
            $action = (string) $payload['action'];
        }
        $action = Tools::strtolower((string) $action);
        if ($action === '') {
            if (isset($payload['quantity']) || isset($payload['delta'])) {
                $action = 'stock';
            } else {
                $action = 'attributes';
            }
        }

        return $action;
    }

    private function getEmployeeResolverService(): EmployeeResolverService
    {
        if ($this->employeeResolverService === null) {
            $this->employeeResolverService = new EmployeeResolverService();
        }

        return $this->employeeResolverService;
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
