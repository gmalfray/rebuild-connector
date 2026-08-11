<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * ⚠️ Mocke intégralement `Db`, `Module` et `RbReview` (stubs `phpstan-bootstrap.php`) — aucune
 * base réelle, aucun vrai avis, aucun vrai e-mail. `RbReview::notifyRejection()` est elle-même un
 * stub qui ne fait qu'enregistrer l'appel (cf. contrainte du mandat de tâche).
 */
final class RbReviewsBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Db::$testExecuteSResult = [];
        Db::$testGetValueResult = 0;
        Module::$testInstalledModules = ['rbreviews' => true];
        Module::$testEnabledModules = ['rbreviews' => true];
        Module::$testInstanceByName = new Module();
        RbReview::$updateCalls = [];
        RbReview::$notifyRejectionCalls = [];
        RbReview::$testNotifyRejectionResult = true;
    }

    public function testTrashRejectsReasonShorterThanTenCharacters(): void
    {
        $bridge = new RbReviewsBridge();

        $this->expectException(\InvalidArgumentException::class);
        $bridge->trash(1, 1, 'court');
    }

    public function testTrashOnUnknownReviewReturnsNullWithoutWriting(): void
    {
        Db::$testGetValueResult = 0; // "n'existe pas" pour la requête d'appartenance.

        $bridge = new RbReviewsBridge();
        $result = $bridge->trash(999, 1, 'Contenu hors sujet, sans rapport avec le produit.');

        $this->assertNull($result);
        $this->assertSame([], RbReview::$updateCalls, 'Aucune écriture ne doit avoir lieu pour un avis introuvable.');
        $this->assertSame([], RbReview::$notifyRejectionCalls);
    }

    public function testTrashSetsFieldsThenNotifiesAuthor(): void
    {
        Db::$testGetValueResult = 1; // l'avis appartient bien à cette boutique.

        $bridge = new RbReviewsBridge();
        $result = $bridge->trash(42, 1, 'Le contenu ne concerne pas ce produit, avis hors sujet.');

        $this->assertNotNull($result);
        $this->assertCount(1, RbReview::$updateCalls);
        $this->assertTrue($result['author_notified']);
        $this->assertSame([42], RbReview::$notifyRejectionCalls);
        $this->assertTrue($result['review']['deleted']);
        $this->assertFalse($result['review']['validated']);
        $this->assertSame('Le contenu ne concerne pas ce produit, avis hors sujet.', $result['review']['rejection_reason']);
    }

    public function testTrashSurvivesNotificationFailure(): void
    {
        Db::$testGetValueResult = 1;
        RbReview::$testNotifyRejectionResult = false;

        $bridge = new RbReviewsBridge();
        $result = $bridge->trash(42, 1, 'Motif suffisamment long pour passer la validation.');

        $this->assertNotNull($result, 'La mise en corbeille doit réussir même si la notification échoue.');
        $this->assertFalse($result['author_notified']);
    }

    public function testTrashWithoutLoadedModuleInstanceDoesNotNotify(): void
    {
        Db::$testGetValueResult = 1;
        Module::$testInstanceByName = false;

        $bridge = new RbReviewsBridge();
        $result = $bridge->trash(42, 1, 'Motif suffisamment long pour passer la validation.');

        $this->assertNotNull($result);
        $this->assertFalse($result['author_notified']);
        $this->assertSame([], RbReview::$notifyRejectionCalls);
    }

    public function testPublishOnUnknownReviewReturnsNull(): void
    {
        Db::$testGetValueResult = 0;

        $bridge = new RbReviewsBridge();
        $result = $bridge->publish(999, 1);

        $this->assertNull($result);
    }

    public function testPublishSetsValidated(): void
    {
        Db::$testGetValueResult = 1;

        $bridge = new RbReviewsBridge();
        $result = $bridge->publish(42, 1);

        $this->assertNotNull($result);
        $this->assertTrue($result['validated']);
        $this->assertCount(1, RbReview::$updateCalls);
    }

    public function testReplyRejectsEmptyReply(): void
    {
        Db::$testGetValueResult = 1;
        $bridge = new RbReviewsBridge();

        $this->expectException(\InvalidArgumentException::class);
        $bridge->reply(42, 1, '   ');
    }

    public function testReplySetsPublicReply(): void
    {
        Db::$testGetValueResult = 1;

        $bridge = new RbReviewsBridge();
        $result = $bridge->reply(42, 1, 'Merci pour votre retour !');

        $this->assertNotNull($result);
        $this->assertSame('Merci pour votre retour !', $result['reply']);
    }

    public function testGetPendingReviewsPaginates(): void
    {
        Db::$testExecuteSResult = [
            $this->pendingRow(1),
            $this->pendingRow(2),
        ];

        $bridge = new RbReviewsBridge();
        $result = $bridge->getPendingReviews(1, 0, 1);

        $this->assertCount(1, $result['items']);
        $this->assertTrue($result['pagination']['has_next']);
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingRow(int $id): array
    {
        return [
            'id_rbreviews_review' => $id,
            'id_product' => 10,
            'display_name' => 'Client Test',
            'email' => 'client@example.com',
            'title' => 'Titre',
            'content' => 'Contenu',
            'grade' => 4,
            'verified_buyer' => 1,
            'date_add' => '2026-08-01 10:00:00',
            'product_name' => 'Bougie parfumée',
        ];
    }
}
