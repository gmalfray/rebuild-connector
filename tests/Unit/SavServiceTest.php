<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * ⚠️ Ces tests ne touchent JAMAIS une vraie base ni un vrai service mail : `Db` et `Mail` sont
 * intégralement mockés par les stubs de `phpstan-bootstrap.php`. Aucun des 97 fils réels ni
 * aucune cliente réelle n'est jamais impliqué — conformément au mandat de tâche.
 */
final class SavServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Db::$testExecuteSResult = [];
        Db::$testGetValueResult = 0;
        Db::$testGetRowResult = false;
        Db::$insertedRows = [];
        Db::$updatedRows = [];
        Mail::$sentMails = [];
        Mail::$testSendResult = true;
        Configuration::$testValues = [];
    }

    protected function tearDown(): void
    {
        Configuration::$testValues = [];
        parent::tearDown();
    }

    public function testGetThreadsPaginatesWithHasNext(): void
    {
        // limit=1, 2 lignes renvoyées par le stub → has_next doit être vrai, une seule retenue.
        Db::$testExecuteSResult = [
            $this->threadRow(1, 'open'),
            $this->threadRow(2, 'closed'),
        ];

        $service = new SavService();
        $result = $service->getThreads(['limit' => 1]);

        $this->assertCount(1, $result['items']);
        $this->assertTrue($result['pagination']['has_next']);
        $this->assertSame(1, $result['pagination']['next_offset']);
    }

    public function testGetThreadSummaryReturnsFormattedThreadWithoutMessages(): void
    {
        Db::$testGetRowResult = $this->threadRow(128, 'pending2');

        $service = new SavService();
        $summary = $service->getThreadSummary(128);

        $this->assertNotNull($summary);
        $this->assertSame(128, $summary['id']);
        $this->assertSame('Camille Dupont', $summary['customer']['name']);
        $this->assertArrayNotHasKey('messages', $summary, 'getThreadSummary() ne doit renvoyer que le fil, pas ses messages.');
    }

    public function testGetThreadSummaryReturnsNullForUnknownThread(): void
    {
        Db::$testGetRowResult = false;

        $service = new SavService();

        $this->assertNull($service->getThreadSummary(999));
    }

    public function testReplyToUnknownThreadReturnsNull(): void
    {
        Db::$testGetRowResult = false;

        $service = new SavService();
        $result = $service->reply(999, 'Bonjour, voici la réponse.', null, '127.0.0.1', 'PHPUnit');

        $this->assertNull($result);
        $this->assertSame([], Db::$insertedRows, 'Aucune écriture ne doit avoir lieu pour un fil introuvable.');
    }

    public function testReplyRejectsEmptyMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $service = new SavService();
        $service->reply(1, '   ', null, '127.0.0.1', 'PHPUnit');
    }

    public function testReplyInsertsMessageUpdatesStatusAndSendsRealLookingEmail(): void
    {
        Db::$testGetRowResult = $this->threadRow(1, 'open');

        $service = new SavService();
        $result = $service->reply(1, 'Votre colis est en cours de préparation.', 7, '10.0.0.1', 'PrestaFlow/1.0');

        $this->assertNotNull($result);
        $this->assertCount(1, Db::$insertedRows);
        $this->assertSame('customer_message', Db::$insertedRows[0]['table']);
        $this->assertSame(7, Db::$insertedRows[0]['data']['id_employee']);
        $this->assertSame('Votre colis est en cours de préparation.', Db::$insertedRows[0]['data']['message']);

        $this->assertCount(1, Db::$updatedRows);
        $this->assertSame('customer_thread', Db::$updatedRows[0]['table']);
        $this->assertSame('pending1', Db::$updatedRows[0]['data']['status']);

        $this->assertTrue($result['email_sent']);
        $this->assertCount(1, Mail::$sentMails);
        $this->assertSame('sav_reply', Mail::$sentMails[0]['template']);
        $this->assertSame('cliente@example.com', Mail::$sentMails[0]['to']);
    }

    // =========================================================================
    // resolveReplyEmployee() — D1 (jamais id_employee = 0 pour une réponse boutique) et D2
    // (employee_name toujours renseigné dans la réponse de POST /sav/{id}/reply).
    // =========================================================================

    public function testReplyWithGlobalApiKeyTokenFallsBackToFirstActiveEmployee(): void
    {
        Db::$testGetRowResult = $this->threadRow(1, 'open');
        // Aucun réglage sav_fallback_employee_id configuré : repli sur le premier employé actif.
        Db::$testExecuteSResult = [
            ['id_employee' => 4, 'firstname' => 'Sophie', 'lastname' => 'Martin'],
        ];

        $service = new SavService();
        // $idEmployee = null : simule un jeton clé API globale (AuthService mode 1).
        $result = $service->reply(1, 'Votre colis part demain.', null, '10.0.0.1', 'PrestaFlow/1.0');

        $this->assertNotNull($result);
        $this->assertSame(4, Db::$insertedRows[0]['data']['id_employee'], 'Jamais id_employee = 0 pour une réponse boutique.');
        $this->assertSame('employee', $result['message']['author']);
        $this->assertSame('Sophie Martin', $result['message']['employee_name']);
    }

    public function testReplyWithGlobalApiKeyTokenPrefersConfiguredFallbackEmployee(): void
    {
        Db::$testGetRowResult = $this->threadRow(1, 'open');
        Db::$testExecuteSResult = [
            ['id_employee' => 9, 'firstname' => 'Camille', 'lastname' => 'Petit'],
        ];

        $settingsService = new SettingsService();
        $settingsService->setSavFallbackEmployeeId(9);

        $service = new SavService($settingsService);
        $result = $service->reply(1, 'Réponse.', null, '10.0.0.1', 'PrestaFlow/1.0');

        $this->assertNotNull($result);
        $this->assertSame(9, Db::$insertedRows[0]['data']['id_employee']);
        $this->assertSame('Camille Petit', $result['message']['employee_name']);
    }

    public function testReplyWithGlobalApiKeyTokenDegradesGracefullyWhenNoActiveEmployee(): void
    {
        Db::$testGetRowResult = $this->threadRow(1, 'open');
        Db::$testExecuteSResult = []; // aucun employé actif en base : cas limite extrême.

        $service = new SavService();
        $result = $service->reply(1, 'Réponse.', null, '10.0.0.1', 'PrestaFlow/1.0');

        $this->assertNotNull($result, 'Jamais d\'exception, même sans employé actif disponible.');
        $this->assertSame(0, Db::$insertedRows[0]['data']['id_employee']);
        $this->assertSame('customer', $result['message']['author']);
        $this->assertNull($result['message']['employee_name']);
    }

    public function testReplyWithNamedUserTokenReportsEmployeeName(): void
    {
        Db::$testGetRowResult = $this->threadRow(1, 'open');
        Db::$testExecuteSResult = [
            ['id_employee' => 7, 'firstname' => 'Julie', 'lastname' => 'Bernard'],
        ];

        $service = new SavService();
        $result = $service->reply(1, 'Votre colis est en cours de préparation.', 7, '10.0.0.1', 'PrestaFlow/1.0');

        $this->assertNotNull($result);
        $this->assertSame(7, Db::$insertedRows[0]['data']['id_employee']);
        $this->assertSame('employee', $result['message']['author']);
        $this->assertSame('Julie Bernard', $result['message']['employee_name']);
    }

    public function testReplySkipsEmailWhenThreadHasNoExploitableAddress(): void
    {
        $row = $this->threadRow(1, 'open');
        $row['email'] = 'not-an-email';
        Db::$testGetRowResult = $row;

        $service = new SavService();
        $result = $service->reply(1, 'Réponse.', null, '10.0.0.1', 'PrestaFlow/1.0');

        $this->assertNotNull($result);
        $this->assertFalse($result['email_sent']);
        $this->assertSame([], Mail::$sentMails, 'Aucun e-mail ne doit partir sans adresse exploitable.');
    }

    public function testChangeStatusRejectsUnknownValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $service = new SavService();
        $service->changeStatus(1, 'archived');
    }

    public function testChangeStatusOnUnknownThreadReturnsNull(): void
    {
        Db::$testGetRowResult = false;

        $service = new SavService();
        $result = $service->changeStatus(999, 'closed');

        $this->assertNull($result);
    }

    public function testChangeStatusUpdatesThread(): void
    {
        Db::$testGetRowResult = $this->threadRow(1, 'open');

        $service = new SavService();
        $result = $service->changeStatus(1, 'closed');

        $this->assertNotNull($result);
        $this->assertCount(1, Db::$updatedRows);
        $this->assertSame('closed', Db::$updatedRows[0]['data']['status']);
    }

    // =========================================================================
    // Définition « à traiter » (`to_process`) — cf. docblock de SavService. Dates volontairement
    // calculées relativement à « maintenant » (jamais une date absolue) pour rester valides quelle
    // que soit la date d'exécution des tests.
    // =========================================================================

    public function testToProcessExcludesClosedThread(): void
    {
        // Fil fermé, dernier message client, tout récent : exclu quand même — status prime.
        Db::$testGetRowResult = $this->threadRow(1, 'closed', 0, $this->recentDate());

        $service = new SavService();
        $summary = $service->getThreadSummary(1);

        $this->assertNotNull($summary);
        $this->assertFalse($summary['to_process'], 'Un fil fermé ne doit jamais être « à traiter ».');
    }

    public function testToProcessExcludesThreadAlreadyRepliedByEmployee(): void
    {
        // Fil non clos, mais le DERNIER message est de l'employé (id_employee > 0) : on attend la
        // cliente, ce n'est pas à la boutique d'agir.
        Db::$testGetRowResult = $this->threadRow(1, 'pending1', 7, $this->recentDate());

        $service = new SavService();
        $summary = $service->getThreadSummary(1);

        $this->assertNotNull($summary);
        $this->assertFalse(
            $summary['to_process'],
            'Un fil déjà répondu par un employé (dernier message pas de la cliente) ne doit pas être « à traiter ».'
        );
    }

    public function testToProcessExcludesThreadOlderThanWindow(): void
    {
        // Non clos, dernier message client, mais date_upd hors fenêtre de fraîcheur : c'est
        // exactement le cas des 81 fils dormants de 2021-2025 mesurés en prod.
        $tooOld = date('Y-m-d H:i:s', strtotime('-' . (SavService::TO_PROCESS_WINDOW_DAYS + 10) . ' days'));
        Db::$testGetRowResult = $this->threadRow(1, 'pending2', 0, $tooOld);

        $service = new SavService();
        $summary = $service->getThreadSummary(1);

        $this->assertNotNull($summary);
        $this->assertFalse($summary['to_process'], 'Un fil trop ancien (hors fenêtre) ne doit pas être « à traiter ».');
    }

    public function testToProcessIncludesLegitimateThread(): void
    {
        // Non clos, dernier message client, récent : c'est exactement le cas légitime.
        Db::$testGetRowResult = $this->threadRow(1, 'pending2', 0, $this->recentDate());

        $service = new SavService();
        $summary = $service->getThreadSummary(1);

        $this->assertNotNull($summary);
        $this->assertTrue($summary['to_process'], 'Un fil non clos, dernier message client, récent doit être « à traiter ».');
    }

    public function testToProcessExcludesThreadWithoutAnyMessage(): void
    {
        // Cas limite : aucun message rattaché (last_message_employee_id absent/NULL). On ne peut
        // pas confirmer que la cliente a écrit en dernier → pas « à traiter ».
        Db::$testGetRowResult = $this->threadRow(1, 'open', null, $this->recentDate());

        $service = new SavService();
        $summary = $service->getThreadSummary(1);

        $this->assertNotNull($summary);
        $this->assertFalse($summary['to_process']);
    }

    public function testUnreadFieldIsStillExposedAlongsideToProcess(): void
    {
        // Non-régression explicite du mandat : `unread` doit rester présent et inchangé.
        $row = $this->threadRow(1, 'pending2', 0, $this->recentDate());
        $row['unread_count'] = 2;
        Db::$testGetRowResult = $row;

        $service = new SavService();
        $summary = $service->getThreadSummary(1);

        $this->assertNotNull($summary);
        $this->assertArrayHasKey('unread', $summary);
        $this->assertTrue($summary['unread']);
        $this->assertArrayHasKey('to_process', $summary);
    }

    public function testGetToProcessCountReadsDbGetValue(): void
    {
        Db::$testGetValueResult = 2;

        $service = new SavService();

        $this->assertSame(2, $service->getToProcessCount());
    }

    public function testGetToProcessCountReturnsZeroWhenDbGetValueFails(): void
    {
        Db::$testGetValueResult = false;

        $service = new SavService();

        $this->assertSame(0, $service->getToProcessCount());
    }

    public function testGetThreadsWithToProcessFilterStillFormatsRows(): void
    {
        Db::$testExecuteSResult = [
            $this->threadRow(1, 'pending2', 0, $this->recentDate()),
        ];

        $service = new SavService();
        $result = $service->getThreads(['to_process' => true]);

        $this->assertCount(1, $result['items']);
        $this->assertTrue($result['items'][0]['to_process']);
    }

    /**
     * Date récente (bien à l'intérieur de la fenêtre de fraîcheur) — calculée relativement à
     * « maintenant » pour ne jamais dépendre de la date d'exécution des tests.
     */
    private function recentDate(): string
    {
        return date('Y-m-d H:i:s', strtotime('-1 day'));
    }

    /**
     * @return array<string, mixed>
     */
    private function threadRow(
        int $id,
        string $status,
        ?int $lastMessageEmployeeId = 0,
        ?string $dateUpd = '2026-08-01 10:00:00'
    ): array {
        return [
            'id_customer_thread' => $id,
            'id_customer' => 3,
            'id_order' => 0,
            'id_lang' => 1,
            'status' => $status,
            'email' => 'cliente@example.com',
            'date_add' => '2026-08-01 10:00:00',
            'date_upd' => $dateUpd,
            'firstname' => 'Camille',
            'lastname' => 'Dupont',
            'order_reference' => null,
            'last_message_at' => '2026-08-01 10:00:00',
            'last_message_employee_id' => $lastMessageEmployeeId,
            'unread_count' => 0,
        ];
    }
}
