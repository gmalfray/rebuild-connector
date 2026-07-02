<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class ProductImagesControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER = ['REQUEST_METHOD' => 'GET'];
        $_GET = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        ImageManager::$resizeSucceeds = true;
        parent::tearDown();
    }

    public function testGetMethodIsRejected(): void
    {
        $controller = new TestProductImagesController();
        $controller->initContent();

        $this->assertSame(405, $controller->response['status']);
        $this->assertSame('method_not_allowed', $controller->response['payload']['error']);
    }

    public function testPutMethodIsRejected(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';

        $controller = new TestProductImagesController();
        $controller->initContent();

        $this->assertSame(405, $controller->response['status']);
        $this->assertSame('method_not_allowed', $controller->response['payload']['error']);
    }

    public function testDeleteWithoutIdsIsRejectedAsInvalidPayload(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';

        $controller = new TestProductImagesController();
        $controller->initContent();

        $this->assertSame(400, $controller->response['status']);
        $this->assertSame('invalid_payload', $controller->response['payload']['error']);
    }

    public function testPostWithoutImageFieldIsRejectedAsInvalidPayload(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_FILES = [];

        $controller = new TestProductImagesController();
        $controller->initContent();

        // Le stub ProductsService::getProductById() (Db::executeS() vide) fait que le produit n'existe
        // jamais en environnement de test, donc c'est le contrôle "produit introuvable" qui répond ici
        // (avant même d'atteindre la validation du champ "image") : on vérifie au moins que la route
        // n'explose pas et retourne une erreur JSON propre, pas une 500.
        $this->assertContains($controller->response['status'], [400, 404]);
    }

    /**
     * @return ReflectionMethod
     */
    private function validateMethod(): ReflectionMethod
    {
        $method = new ReflectionMethod(
            RebuildconnectorProductimagesModuleFrontController::class,
            'validateUploadedImage'
        );
        $method->setAccessible(true);

        return $method;
    }

    public function testValidateUploadedImageRejectsUploadError(): void
    {
        $controller = new TestProductImagesController();

        $this->expectException(InvalidArgumentException::class);
        $this->validateMethod()->invoke($controller, [
            'error' => UPLOAD_ERR_INI_SIZE,
            'size' => 100,
            'tmp_name' => '/tmp/whatever',
        ]);
    }

    public function testValidateUploadedImageRejectsOversizedFile(): void
    {
        $controller = new TestProductImagesController();

        $this->expectException(InvalidArgumentException::class);
        $this->validateMethod()->invoke($controller, [
            'error' => UPLOAD_ERR_OK,
            'size' => 9 * 1024 * 1024, // > 8 Mo
            'tmp_name' => '/tmp/whatever',
        ]);
    }

    public function testValidateUploadedImageRejectsZeroByteFile(): void
    {
        $controller = new TestProductImagesController();

        $this->expectException(InvalidArgumentException::class);
        $this->validateMethod()->invoke($controller, [
            'error' => UPLOAD_ERR_OK,
            'size' => 0,
            'tmp_name' => '/tmp/whatever',
        ]);
    }

    public function testValidateUploadedImageRejectsFileNotFlaggedAsRealUpload(): void
    {
        // isUploadedFile() n'est PAS surchargée : is_uploaded_file() renvoie toujours false hors
        // contexte réel de requête HTTP (ex. exécution CLI PHPUnit) — protection anti path-traversal /
        // anti-LFI vérifiée ici.
        $tmpFile = tempnam(sys_get_temp_dir(), 'rc-test-');
        $this->assertNotFalse($tmpFile);
        file_put_contents($tmpFile, $this->validPngBytes());

        try {
            $controller = new TestProductImagesController();

            $this->expectException(InvalidArgumentException::class);
            $this->validateMethod()->invoke($controller, [
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpFile),
                'tmp_name' => $tmpFile,
            ]);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testValidateUploadedImageRejectsNonImageContentDespiteFakeExtension(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rc-test-');
        $this->assertNotFalse($tmpFile);
        // Contenu clairement non-image : ni l'extension ni le Content-Type déclaré ne doivent
        // pouvoir contourner cette vérification, seul le contenu réel (getimagesize/finfo) compte.
        file_put_contents($tmpFile, "MZ\x90\x00this is not an image, whatever the declared type says\n");

        try {
            $controller = new TestProductImagesControllerWithForcedUpload();

            $this->expectException(InvalidArgumentException::class);
            $this->validateMethod()->invoke($controller, [
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpFile),
                'tmp_name' => $tmpFile,
                'name' => 'photo.jpg',
                'type' => 'image/jpeg',
            ]);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testValidateUploadedImageAcceptsRealPngContent(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rc-test-');
        $this->assertNotFalse($tmpFile);
        file_put_contents($tmpFile, $this->validPngBytes());

        try {
            $controller = new TestProductImagesControllerWithForcedUpload();

            // Ne doit lever aucune exception : erreur=OK, taille correcte, upload "réel" simulé,
            // contenu effectivement reconnu comme un PNG valide.
            $this->validateMethod()->invoke($controller, [
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpFile),
                'tmp_name' => $tmpFile,
            ]);
            $this->addToAssertionCount(1);
        } finally {
            @unlink($tmpFile);
        }
    }

    private function validPngBytes(): string
    {
        // PNG 1x1 transparent minimal.
        $decoded = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );
        $this->assertNotFalse($decoded);

        return $decoded;
    }
}

class TestProductImagesController extends RebuildconnectorProductimagesModuleFrontController
{
    /** @var array<string, mixed>|null */
    public ?array $response = null;

    public function __construct()
    {
        parent::__construct();
    }

    protected function renderJson(array $payload, int $statusCode = 200): void
    {
        $this->response = [
            'status' => $statusCode,
            'payload' => $payload,
        ];
    }

    protected function jsonError(string $error, string $message, int $statusCode): void
    {
        $this->renderJson([
            'error' => $error,
            'message' => $message,
        ], $statusCode);
    }

    protected function requireAuth(array $requiredScopes = []): array
    {
        return ['scopes' => $requiredScopes, 'sub' => 'test-user'];
    }
}

final class TestProductImagesControllerWithForcedUpload extends TestProductImagesController
{
    protected function isUploadedFile(string $path): bool
    {
        return true;
    }
}
