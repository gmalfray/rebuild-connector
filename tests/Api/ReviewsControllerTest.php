<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * ⚠️ `FakeReviewsBridge` remplace ENTIÈREMENT le pont : ces tests ne touchent jamais rbreviews ni
 * une vraie base — conformément au mandat de tâche.
 */
final class ReviewsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER = ['REQUEST_METHOD' => 'GET'];
        $_GET = [];
        $_POST = [];
    }

    public function testUnavailableBridgeReturns409OnGet(): void
    {
        $controller = new TestReviewsController();
        $controller->injectFakeBridge(new FakeReviewsBridge(false));
        $controller->initContent();

        $this->assertSame(409, $controller->response['status']);
        $this->assertSame('reviews_unavailable', $controller->response['payload']['error']);
    }

    public function testUnavailableBridgeReturns409OnPost(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
        $_GET = ['id' => 1, 'action' => 'publish'];

        $controller = new TestReviewsController();
        $controller->injectFakeBridge(new FakeReviewsBridge(false));
        $controller->initContent();

        $this->assertSame(409, $controller->response['status']);
    }

    public function testListReturnsReviewsEnvelope(): void
    {
        $controller = new TestReviewsController();
        $controller->injectFakeBridge(new FakeReviewsBridge(true));
        $controller->initContent();

        $this->assertSame(200, $controller->response['status']);
        $this->assertArrayHasKey('reviews', $controller->response['payload']);
        $this->assertArrayHasKey('pagination', $controller->response['payload']);
    }

    public function testPublishUnknownReviewReturns404(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
        $_GET = ['id' => 999, 'action' => 'publish'];

        $bridge = new FakeReviewsBridge(true);
        $bridge->publishResult = null;

        $controller = new TestReviewsController();
        $controller->injectFakeBridge($bridge);
        $controller->initContent();

        $this->assertSame(404, $controller->response['status']);
    }

    public function testPublishSuccess(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
        $_GET = ['id' => 42, 'action' => 'publish'];

        $controller = new TestReviewsController();
        $controller->injectFakeBridge(new FakeReviewsBridge(true));
        $controller->initContent();

        $this->assertSame(200, $controller->response['status']);
        $this->assertArrayHasKey('review', $controller->response['payload']);
    }

    public function testTrashRejectsShortReasonBeforeAnyWrite(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
        $_GET = ['id' => 42, 'action' => 'trash'];

        $bridge = new FakeReviewsBridge(true);
        $controller = new TestReviewsController();
        $controller->decodeBodyOverride = ['reason' => 'court'];
        $controller->injectFakeBridge($bridge);
        $controller->initContent();

        $this->assertSame(422, $controller->response['status']);
        $this->assertSame('invalid_rejection_reason', $controller->response['payload']['error']);
        $this->assertSame(0, $bridge->trashCallCount, 'Aucune écriture ne doit être tentée avant validation du motif.');
    }

    public function testTrashRejectsMissingReason(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
        $_GET = ['id' => 42, 'action' => 'trash'];

        $bridge = new FakeReviewsBridge(true);
        $controller = new TestReviewsController();
        $controller->decodeBodyOverride = [];
        $controller->injectFakeBridge($bridge);
        $controller->initContent();

        $this->assertSame(422, $controller->response['status']);
        $this->assertSame(0, $bridge->trashCallCount);
    }

    public function testTrashSuccessWithValidReason(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
        $_GET = ['id' => 42, 'action' => 'trash'];

        $controller = new TestReviewsController();
        $controller->decodeBodyOverride = ['reason' => 'Contenu hors sujet, sans rapport avec le produit vendu.'];
        $bridge = new FakeReviewsBridge(true);
        $controller->injectFakeBridge($bridge);
        $controller->initContent();

        $this->assertSame(200, $controller->response['status']);
        $this->assertSame(1, $bridge->trashCallCount);
        $this->assertArrayHasKey('author_notified', $controller->response['payload']);
    }

    public function testReplyRejectsEmptyBody(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
        $_GET = ['id' => 42, 'action' => 'reply'];

        $controller = new TestReviewsController();
        $controller->decodeBodyOverride = ['reply' => '   '];
        $controller->injectFakeBridge(new FakeReviewsBridge(true));
        $controller->initContent();

        $this->assertSame(400, $controller->response['status']);
    }

    public function testUnsupportedActionReturns400(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
        $_GET = ['id' => 42, 'action' => 'delete'];

        $controller = new TestReviewsController();
        $controller->injectFakeBridge(new FakeReviewsBridge(true));
        $controller->initContent();

        $this->assertSame(400, $controller->response['status']);
    }
}

final class TestReviewsController extends RebuildconnectorReviewsModuleFrontController
{
    /** @var array<string, mixed>|null */
    public ?array $response = null;
    /** @var array<string, mixed> */
    public array $decodeBodyOverride = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function injectFakeBridge(ReviewsBridgeInterface $bridge): void
    {
        $property = new \ReflectionProperty(RebuildconnectorReviewsModuleFrontController::class, 'reviewsBridge');
        $property->setAccessible(true);
        $property->setValue($this, $bridge);
    }

    protected function renderJson(array $payload, int $statusCode = 200): void
    {
        $this->response = ['status' => $statusCode, 'payload' => $payload];
    }

    protected function jsonError(string $error, string $message, int $statusCode): void
    {
        $this->renderJson(['error' => $error, 'message' => $message], $statusCode);
    }

    protected function requireAuth(array $requiredScopes = []): array
    {
        return ['scopes' => $requiredScopes, 'sub' => 'test'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeRequestBody(): array
    {
        return $this->decodeBodyOverride;
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function recordAuditEvent(string $event, array $context = []): void
    {
        // no-op en test
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function dispatchWebhookEvent(string $event, array $payload = []): void
    {
        // no-op en test
    }
}

final class FakeReviewsBridge implements ReviewsBridgeInterface
{
    public int $trashCallCount = 0;
    /** @var array<string, mixed>|null */
    public ?array $publishResult = ['id' => 42, 'validated' => true];
    /** @var array<string, mixed>|null */
    public ?array $replyResult = ['id' => 42, 'reply' => 'Merci !'];

    private bool $available;

    public function __construct(bool $available)
    {
        $this->available = $available;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function getPendingReviews(int $limit, int $offset, int $idShop): array
    {
        return [
            'items' => [['id' => 1, 'grade' => 5]],
            'pagination' => ['limit' => $limit, 'offset' => $offset, 'count' => 1, 'has_next' => false, 'next_offset' => null],
        ];
    }

    public function publish(int $idReview, int $idShop): ?array
    {
        return $this->publishResult;
    }

    public function trash(int $idReview, int $idShop, string $reason): ?array
    {
        ++$this->trashCallCount;

        return ['review' => ['id' => $idReview, 'deleted' => true, 'rejection_reason' => $reason], 'author_notified' => true];
    }

    public function reply(int $idReview, int $idShop, string $reply): ?array
    {
        return $this->replyResult;
    }
}
