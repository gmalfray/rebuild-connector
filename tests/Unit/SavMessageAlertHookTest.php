<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * hookActionObjectCustomerMessageAddAfter (notification push "nouveau message SAV", événement
 * `sav.message`) : vérifie le cablage du toggle BO (actif par défaut), le filtre id_employee
 * (jamais de notif pour une réponse d'employé — BO natif ou app), et le branchement sur
 * `SavService::getThreadSummary()`, sans base réelle (Db/Configuration sont les doubles de test
 * de phpstan-bootstrap.php).
 *
 * L'envoi effectif (hub push) est hors périmètre ici : le hub n'est jamais configuré dans ces
 * tests (REBUILDCONNECTOR_HUB_URL_OVERRIDE pointe vers un port fermé, comme StockLowAlertHookTest),
 * donc notifyDevices() court-circuite avant tout appel réseau. Le signal observable retenu est
 * l'audit `sav.message` (recordAudit()), écrit de façon SYNCHRONE avant le différé
 * runAfterResponse() — un proxy fiable de "le module a décidé de notifier".
 */
final class SavMessageAlertHookTest extends TestCase
{
    private RebuildConnector $module;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('REBUILDCONNECTOR_HUB_URL_OVERRIDE')) {
            define('REBUILDCONNECTOR_HUB_URL_OVERRIDE', 'http://127.0.0.1:1');
        }

        Db::$testGetRowResult = false;
        Db::$insertedRows = [];
        Configuration::$testValues = [];

        $this->module = new RebuildConnector();
    }

    protected function tearDown(): void
    {
        Db::$testGetRowResult = false;
        Db::$insertedRows = [];
        Configuration::$testValues = [];
        parent::tearDown();
    }

    public function testEnabledByDefaultCustomerMessageRecordsAudit(): void
    {
        // Toggle sav_message_alerts_enabled non configuré → isSavMessageAlertsEnabled() renvoie
        // true par défaut (contrairement à stock_low, actif ici dès l'installation en place).
        $this->givenThread(128);

        $this->module->hookActionObjectCustomerMessageAddAfter([
            'object' => $this->customerMessage(128, 0, 'Bonjour, où en est ma commande ?'),
        ]);

        $audit = $this->findSavMessageAudit();
        $this->assertNotNull($audit, 'Un message client doit notifier même sans réglage explicite (actif par défaut).');
        $this->assertSame(128, $audit['thread_id'] ?? null);
    }

    public function testDisabledSettingNeverNotifies(): void
    {
        $this->disableSavMessageAlerts();
        $this->givenThread(128);

        $this->module->hookActionObjectCustomerMessageAddAfter([
            'object' => $this->customerMessage(128, 0, 'Bonjour !'),
        ]);

        $this->assertNull($this->findSavMessageAudit(), 'Le toggle désactivé ne doit jamais notifier.');
    }

    public function testEmployeeReplyFromBackOfficeNeverNotifies(): void
    {
        $this->givenThread(128);

        // id_employee > 0 : réponse saisie depuis le BO natif PrestaShop (AdminCustomerThreadsController),
        // qui insère elle aussi via ObjectModel::add() → NE DOIT JAMAIS notifier (auto-notification).
        $this->module->hookActionObjectCustomerMessageAddAfter([
            'object' => $this->customerMessage(128, 42, 'Votre colis part demain.'),
        ]);

        $this->assertNull($this->findSavMessageAudit(), 'Une réponse d\'employé ne doit jamais déclencher sav.message.');
    }

    public function testUnknownThreadNeverNotifies(): void
    {
        Db::$testGetRowResult = false; // fetchThreadRow() ne trouve rien.

        $this->module->hookActionObjectCustomerMessageAddAfter([
            'object' => $this->customerMessage(999, 0, 'Message orphelin'),
        ]);

        $this->assertNull($this->findSavMessageAudit(), 'Un fil introuvable (autre boutique / supprimé) ne doit jamais notifier.');
    }

    public function testMissingObjectOrThreadIdIsIgnoredSafely(): void
    {
        try {
            $this->module->hookActionObjectCustomerMessageAddAfter([]);
            $this->module->hookActionObjectCustomerMessageAddAfter(['object' => 'not-an-object']);

            $withoutThreadId = new \stdClass();
            $withoutThreadId->id_employee = 0;
            $this->module->hookActionObjectCustomerMessageAddAfter(['object' => $withoutThreadId]);
        } catch (\Throwable $exception) {
            $this->fail('hookActionObjectCustomerMessageAddAfter ne doit jamais lever d\'exception : ' . $exception->getMessage());
        }

        $this->assertNull($this->findSavMessageAudit());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function disableSavMessageAlerts(): void
    {
        Configuration::$testValues['REBUILDCONNECTOR_SETTINGS'] = json_encode([
            'sav_message_alerts_enabled' => false,
        ]);
    }

    private function givenThread(int $idThread): void
    {
        Db::$testGetRowResult = [
            'id_customer_thread' => $idThread,
            'id_customer' => 7,
            'id_order' => 0,
            'id_lang' => 1,
            'status' => 'pending2',
            'email' => 'client@example.com',
            'date_add' => '2026-08-11 10:00:00',
            'date_upd' => '2026-08-11 10:00:00',
            'firstname' => 'Marie',
            'lastname' => 'Dupont',
            'order_reference' => null,
            'last_message_at' => '2026-08-11 10:00:00',
            'unread_count' => 1,
        ];
    }

    private function customerMessage(int $idThread, int $idEmployee, string $message): \stdClass
    {
        $object = new \stdClass();
        $object->id_customer_thread = $idThread;
        $object->id_employee = $idEmployee;
        $object->message = $message;

        return $object;
    }

    /**
     * Reconstitue le contexte d'audit décodé (AuditLogService::record() sérialise le contexte en
     * JSON dans la colonne `context`, à part de la colonne `event`) : `['event' => ..., ...context]`.
     *
     * @return array<string, mixed>|null
     */
    private function findSavMessageAudit(): ?array
    {
        foreach (Db::$insertedRows as $row) {
            if ($row['table'] !== AuditLogService::TABLE_NAME) {
                continue;
            }

            if (($row['data']['event'] ?? null) !== 'sav.message') {
                continue;
            }

            $context = json_decode((string) ($row['data']['context'] ?? '{}'), true);

            return array_merge(['event' => $row['data']['event']], is_array($context) ? $context : []);
        }

        return null;
    }
}
