<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * hookActionObjectRbReviewAddAfter (notification push "avis en modération", événement
 * `review.pending`) : vérifie le cablage du toggle BO (désactivé par défaut), le filtre
 * validated/deleted (seul un avis fraîchement entré en modération notifie), la garde de
 * disponibilité rbreviews, et l'absence totale de risque avec l'import Etsy (qui n'atteint jamais
 * ce hook — écrit par Db::insert() direct, jamais ObjectModel::add(), donc simplement pas
 * simulable ici : c'est justement la garantie testée par ReviewsBridgeFactoryTest/RbReviewsBridgeTest
 * côté écriture).
 *
 * Même discipline que StockLowAlertHookTest/SavMessageAlertHookTest : hub jamais configuré
 * (REBUILDCONNECTOR_HUB_URL_OVERRIDE → port fermé), signal observable = l'audit `review.pending`.
 */
final class ReviewPendingAlertHookTest extends TestCase
{
    private RebuildConnector $module;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('REBUILDCONNECTOR_HUB_URL_OVERRIDE')) {
            define('REBUILDCONNECTOR_HUB_URL_OVERRIDE', 'http://127.0.0.1:1');
        }

        Db::$insertedRows = [];
        Configuration::$testValues = [];
        Module::$testInstalledModules = ['rbreviews' => true];
        Module::$testEnabledModules = ['rbreviews' => true];

        $this->module = new RebuildConnector();
    }

    protected function tearDown(): void
    {
        Db::$insertedRows = [];
        Configuration::$testValues = [];
        Module::$testInstalledModules = [];
        Module::$testEnabledModules = [];
        parent::tearDown();
    }

    public function testDisabledByDefaultDoesNotNotify(): void
    {
        // Toggle review_pending_alerts_enabled non configuré → isReviewPendingAlertsEnabled()
        // renvoie false par défaut (volume faible, cf. docs/app-avis-sav.md).
        $this->module->hookActionObjectRbReviewAddAfter([
            'object' => $this->pendingReview(57),
        ]);

        $this->assertNull($this->findReviewPendingAudit(), 'Le toggle désactivé par défaut ne doit jamais notifier.');
    }

    public function testEnabledAndPendingReviewRecordsAudit(): void
    {
        $this->enableReviewPendingAlerts();

        $this->module->hookActionObjectRbReviewAddAfter([
            'object' => $this->pendingReview(57),
        ]);

        $audit = $this->findReviewPendingAudit();
        $this->assertNotNull($audit, 'Un avis natif fraîchement entré en modération doit notifier.');
        $this->assertSame(57, $audit['review_id'] ?? null);
    }

    public function testEnabledAndAlreadyValidatedNeverNotifies(): void
    {
        $this->enableReviewPendingAlerts();

        // Modération désactivée sur la boutique (RBREVIEWS_MODERATE=0) : l'avis arrive déjà publié.
        $review = $this->pendingReview(58);
        $review->validated = 1;

        $this->module->hookActionObjectRbReviewAddAfter(['object' => $review]);

        $this->assertNull($this->findReviewPendingAudit(), 'Un avis déjà publié à la création ne doit jamais notifier.');
    }

    public function testEnabledAndDeletedNeverNotifies(): void
    {
        $this->enableReviewPendingAlerts();

        $review = $this->pendingReview(59);
        $review->deleted = 1;

        $this->module->hookActionObjectRbReviewAddAfter(['object' => $review]);

        $this->assertNull($this->findReviewPendingAudit(), 'Un avis déjà en corbeille ne doit jamais notifier.');
    }

    public function testEnabledButRbreviewsUnavailableNeverNotifies(): void
    {
        $this->enableReviewPendingAlerts();
        Module::$testInstalledModules = [];
        Module::$testEnabledModules = [];

        $this->module->hookActionObjectRbReviewAddAfter([
            'object' => $this->pendingReview(60),
        ]);

        $this->assertNull($this->findReviewPendingAudit(), 'rbreviews indisponible (désinstallé) ne doit jamais notifier.');
    }

    public function testMissingObjectOrIdIsIgnoredSafely(): void
    {
        $this->enableReviewPendingAlerts();

        try {
            $this->module->hookActionObjectRbReviewAddAfter([]);
            $this->module->hookActionObjectRbReviewAddAfter(['object' => 'not-an-object']);

            $withoutId = new \stdClass();
            $withoutId->validated = 0;
            $withoutId->deleted = 0;
            $this->module->hookActionObjectRbReviewAddAfter(['object' => $withoutId]);
        } catch (\Throwable $exception) {
            $this->fail('hookActionObjectRbReviewAddAfter ne doit jamais lever d\'exception : ' . $exception->getMessage());
        }

        $this->assertNull($this->findReviewPendingAudit());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function enableReviewPendingAlerts(): void
    {
        Configuration::$testValues['REBUILDCONNECTOR_SETTINGS'] = json_encode([
            'review_pending_alerts_enabled' => true,
        ]);
    }

    private function pendingReview(int $id): \stdClass
    {
        $object = new \stdClass();
        $object->id = $id;
        $object->validated = 0;
        $object->deleted = 0;
        $object->grade = 4;
        $object->display_name = 'Claire M.';

        return $object;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findReviewPendingAudit(): ?array
    {
        foreach (Db::$insertedRows as $row) {
            if ($row['table'] !== AuditLogService::TABLE_NAME) {
                continue;
            }

            if (($row['data']['event'] ?? null) !== 'review.pending') {
                continue;
            }

            $context = json_decode((string) ($row['data']['context'] ?? '{}'), true);

            return array_merge(['event' => $row['data']['event']], is_array($context) ? $context : []);
        }

        return null;
    }
}
