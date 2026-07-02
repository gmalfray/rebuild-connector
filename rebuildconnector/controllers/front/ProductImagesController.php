<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/BaseApiController.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/ProductsService.php';

/**
 * Upload / suppression des images d'un produit.
 *
 * POST   .../api/products/{id}/images            (multipart/form-data, champ "image") -> 201 {product}
 * DELETE .../api/products/{id}/images/{imageId}                                        -> 200 {product}
 *
 * Séparé de RebuildconnectorProductsModuleFrontController car le multipart/upload de fichier se prête
 * mal au dispatch JSON habituel (decodeRequestBody attend un corps JSON).
 */
class RebuildconnectorProductimagesModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    /**
     * Taille max acceptée pour une image produit (8 Mo).
     */
    private const MAX_IMAGE_SIZE_BYTES = 8 * 1024 * 1024;

    /**
     * Types d'images acceptés : constantes IMAGETYPE_* (getimagesize) => MIME attendu.
     *
     * @var array<int, string>
     */
    private const ALLOWED_IMAGE_TYPES = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    private ?ProductsService $productsService = null;

    public function initContent(): void
    {
        parent::initContent();

        $method = Tools::strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            switch ($method) {
                case 'POST':
                    $authPayload = $this->requireAuth(['products.write']);
                    $this->handlePost($authPayload);
                    break;
                case 'DELETE':
                    $authPayload = $this->requireAuth(['products.write']);
                    $this->handleDelete($authPayload);
                    break;
                default:
                    // @ : header() peut émettre un warning "headers already sent" hors contexte HTTP réel
                    // (ex. exécution CLI PHPUnit où du texte a déjà été écrit sur stdout) ; sans impact
                    // en production (le header Allow est informatif sur une 405).
                    @header('Allow: POST, DELETE');
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

    /**
     * @param array<string, mixed> $authPayload
     */
    private function handlePost(array $authPayload): void
    {
        $productId = (int) Tools::getValue('id_product', (int) Tools::getValue('id', 0));
        if ($productId <= 0) {
            throw new \InvalidArgumentException($this->t('products.error.not_found', [], 'Product not found.'));
        }

        $existingProduct = $this->getProductsService()->getProductById($productId);
        if ($existingProduct === []) {
            $this->jsonError(
                'not_found',
                $this->t('products.error.not_found', [], 'Product not found.'),
                404
            );
            return;
        }

        $file = $this->getUploadedFile();
        $this->validateUploadedImage($file);

        $tmpName = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';

        try {
            $product = $this->getProductsService()->addProductImage($productId, $file);
        } finally {
            if ($tmpName !== '' && is_file($tmpName)) {
                @unlink($tmpName);
            }
        }

        if ($product === []) {
            $this->jsonError(
                'server_error',
                $this->t('products.error.image_upload_failed', [], 'Unable to add the product image.'),
                500
            );
            return;
        }

        $this->recordAuditEvent('products.image.created', [
            'product_id' => $productId,
            'token_subject' => $authPayload['sub'] ?? null,
        ]);
        $this->dispatchWebhookEvent('product.image.created', [
            'product_id' => (string) $productId,
        ]);

        $this->renderJson(['product' => $product], 201);
    }

    /**
     * @param array<string, mixed> $authPayload
     */
    private function handleDelete(array $authPayload): void
    {
        $productId = (int) Tools::getValue('id_product', (int) Tools::getValue('id', 0));
        $imageId = (int) Tools::getValue('id_image', (int) Tools::getValue('imageId', 0));

        if ($productId <= 0 || $imageId <= 0) {
            throw new \InvalidArgumentException($this->t('products.error.not_found', [], 'Product not found.'));
        }

        $existingProduct = $this->getProductsService()->getProductById($productId);
        if ($existingProduct === []) {
            $this->jsonError(
                'not_found',
                $this->t('products.error.not_found', [], 'Product not found.'),
                404
            );
            return;
        }

        if (!$this->getProductsService()->deleteProductImage($productId, $imageId)) {
            $this->jsonError(
                'not_found',
                $this->t('products.error.image_not_found', [], 'Image not found for this product.'),
                404
            );
            return;
        }

        $this->recordAuditEvent('products.image.deleted', [
            'product_id' => $productId,
            'image_id' => $imageId,
            'token_subject' => $authPayload['sub'] ?? null,
        ]);
        $this->dispatchWebhookEvent('product.image.deleted', [
            'product_id' => (string) $productId,
            'image_id' => (string) $imageId,
        ]);

        $product = $this->getProductsService()->getProductById($productId);

        $this->renderJson(['product' => $product]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getUploadedFile(): array
    {
        if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
            throw new \InvalidArgumentException(
                $this->t('products.error.image_missing', [], 'The "image" file field is required.')
            );
        }

        /** @var array<string, mixed> $file */
        $file = $_FILES['image'];

        return $file;
    }

    /**
     * Valide le fichier uploadé sans jamais faire confiance au nom de fichier ou au type MIME déclarés
     * par le client : le contenu réel est inspecté via getimagesize()/finfo.
     *
     * @param array<string, mixed> $file
     */
    private function validateUploadedImage(array $file): void
    {
        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException(
                $this->t('products.error.image_upload_error', [], 'The image upload failed.')
            );
        }

        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size <= 0 || $size > self::MAX_IMAGE_SIZE_BYTES) {
            throw new \InvalidArgumentException(
                $this->t(
                    'products.error.image_too_large',
                    [],
                    'The image exceeds the maximum allowed size (8 MB).'
                )
            );
        }

        $tmpName = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($tmpName === '' || !$this->isUploadedFile($tmpName)) {
            throw new \InvalidArgumentException(
                $this->t('products.error.image_upload_error', [], 'The image upload failed.')
            );
        }

        // Le contenu réel du fichier est inspecté (pas l'extension ni le Content-Type déclarés par le client).
        $info = @getimagesize($tmpName);
        if ($info === false || !array_key_exists($info[2], self::ALLOWED_IMAGE_TYPES)) {
            throw new \InvalidArgumentException(
                $this->t(
                    'products.error.image_invalid_type',
                    [],
                    'Unsupported image format. Allowed formats: JPEG, PNG, WEBP.'
                )
            );
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $sniffedMime = finfo_file($finfo, $tmpName);
                finfo_close($finfo);

                $expectedMime = self::ALLOWED_IMAGE_TYPES[$info[2]];
                if ($sniffedMime !== false && $sniffedMime !== $expectedMime) {
                    throw new \InvalidArgumentException(
                        $this->t(
                            'products.error.image_invalid_type',
                            [],
                            'Unsupported image format. Allowed formats: JPEG, PNG, WEBP.'
                        )
                    );
                }
            }
        }
    }

    /**
     * Enveloppe de `is_uploaded_file()`, surchargeable dans les tests : en dehors d'une vraie requête
     * HTTP multipart (ex. exécution CLI PHPUnit), `is_uploaded_file()` renvoie toujours `false`.
     */
    protected function isUploadedFile(string $path): bool
    {
        return is_uploaded_file($path);
    }

    private function getProductsService(): ProductsService
    {
        if ($this->productsService === null) {
            $this->productsService = new ProductsService();
        }

        return $this->productsService;
    }
}
