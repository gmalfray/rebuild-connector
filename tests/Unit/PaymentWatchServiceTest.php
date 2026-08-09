<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Le contrat qui compte : alerter quand la BOUTIQUE ne peut plus encaisser, et se taire quand
 * c'est le moyen de paiement d'un client qui a échoué. Les lignes de journal utilisées ici sont
 * calquées sur celles produites en production pendant la panne du 07→09/08/2026.
 */
final class PaymentWatchServiceTest extends TestCase
{
    private PaymentWatchService $service;

    protected function setUp(): void
    {
        $this->service = new PaymentWatchService();
    }

    /** Ligne réelle de la panne : exception SQL levée à la création de la commande PayPal. */
    private function technicalLine(int $cart = 20981): string
    {
        return '{"message":"CreateOrder - Exception 42","context":{"exception":{"class":"PrestaShopException",'
            . '"message":"SQLSTATE[42S22]: Column not found: 1054 Unknown column \'date_add\' in \'field list\'"},'
            . '"id_cart":' . $cart . '},"level":400,"level_name":"ERROR","channel":"ps_checkout"}';
    }

    /** Carte refusée : ligne en ERROR elle aussi, mais l'incident appartient au client. */
    private function declinedLine(int $cart): string
    {
        return '{"message":"Customer canceled payment","context":{"FundingSource":"card","id_cart":' . $cart
            . ',"reason":"card_fields_error","error":"INSTRUMENT_DECLINED"},'
            . '"level":400,"level_name":"ERROR","channel":"ps_checkout"}';
    }

    public function testAbandonSimpleNestPasUneErreur(): void
    {
        // Un abandon sans erreur est journalisé en INFO par ps_checkout : il ne doit jamais
        // atteindre l'analyse.
        $info = '{"message":"Customer canceled payment","context":{"id_cart":42,"error":null},'
            . '"level":200,"level_name":"INFO","channel":"ps_checkout"}';

        self::assertSame([], $this->service->extractErrorLines($info));
    }

    public function testEnvoiDuSuiviColisEstDuBruitIgnore(): void
    {
        // Échouait déjà régulièrement boutique parfaitement saine : ne doit alerter à aucun niveau.
        $noise = '[2026-07-18 19:30:19] ps_checkout.ERROR: ADD API call failed for order 6529: Unprocessable Entity';

        self::assertSame([], $this->service->extractErrorLines($noise));
    }

    public function testUneExceptionSqlEstClasseeTechnique(): void
    {
        $lines = $this->service->extractErrorLines($this->technicalLine());
        $analysis = $this->service->analyse($lines);

        self::assertSame(1, $analysis['errors']);
        self::assertSame(1, $analysis['technical']);
        self::assertSame([20981], $analysis['carts']);
        self::assertStringContainsString('SQLSTATE', $analysis['reason']);
    }

    public function testUneSeuleCarteRefuseeNAlertePas(): void
    {
        $lines = $this->service->extractErrorLines($this->declinedLine(99001));
        $analysis = $this->service->analyse($lines);

        self::assertSame(1, $analysis['errors']);
        self::assertSame(0, $analysis['technical'], 'Un refus bancaire ne relève pas de la boutique');

        $verdict = $this->service->decide($analysis['technical'], 1, 0, 1000);
        self::assertSame(PaymentWatchService::KIND_NONE, $verdict);
    }

    public function testUneSeuleErreurTechniqueAlerteImmediatement(): void
    {
        // Le 08/08, la panne a commencé sur UN panier à 00h44 : attendre un volume aurait coûté
        // une journée de ventes.
        $verdict = $this->service->decide(1, 1, 0, 1000);

        self::assertSame(PaymentWatchService::KIND_TECHNICAL, $verdict);
    }

    public function testTroisPaniersEnEchecDeclenchentLeFiletVolumetrique(): void
    {
        $verdict = $this->service->decide(0, PaymentWatchService::VOLUME_CARTS, 0, 1000);

        self::assertSame(PaymentWatchService::KIND_VOLUME, $verdict);
    }

    public function testDeuxPaniersNeSuffisentPas(): void
    {
        $verdict = $this->service->decide(0, 2, 0, 1000);

        self::assertSame(PaymentWatchService::KIND_NONE, $verdict);
    }

    public function testLeCooldownEmpecheLeMartelagePendantUnePanneLongue(): void
    {
        $now = 100000;
        $recent = $now - (PaymentWatchService::COOLDOWN_SECONDS - 60);

        self::assertSame(PaymentWatchService::KIND_NONE, $this->service->decide(5, 3, $recent, $now));

        $old = $now - (PaymentWatchService::COOLDOWN_SECONDS + 60);
        self::assertSame(PaymentWatchService::KIND_TECHNICAL, $this->service->decide(5, 3, $old, $now));
    }

    public function testLesPaniersSortisDeLaFenetreSontOublies(): void
    {
        $now = 100000;
        $known = [
            ['cart' => 1, 'at' => $now - (PaymentWatchService::VOLUME_WINDOW + 10)], // périmé
            ['cart' => 2, 'at' => $now - 60],
        ];

        $merged = $this->service->mergeFailingCarts($known, [3], $now);
        $carts = array_column($merged, 'cart');

        sort($carts);
        self::assertSame([2, 3], $carts);
    }

    public function testUnMemePanierNestComptePasDeuxFois(): void
    {
        $now = 100000;
        $known = [['cart' => 7, 'at' => $now - 60]];

        $merged = $this->service->mergeFailingCarts($known, [7, 7], $now);

        self::assertCount(1, $merged, 'Un client qui réessaie ne doit pas gonfler le compteur');
    }

    public function testPlusieursPaniersDistinctsRefusesFinissentParAlerter(): void
    {
        // Le filet doit rattraper une panne dont la signature technique nous est inconnue.
        $chunk = implode("\n", [
            $this->declinedLine(1),
            $this->declinedLine(2),
            $this->declinedLine(3),
        ]);

        $analysis = $this->service->analyse($this->service->extractErrorLines($chunk));
        self::assertSame(0, $analysis['technical']);
        self::assertCount(3, $analysis['carts']);

        $verdict = $this->service->decide($analysis['technical'], count($analysis['carts']), 0, 1000);
        self::assertSame(PaymentWatchService::KIND_VOLUME, $verdict);
    }

    public function testFormatTexteAncienEgalementReconnu(): void
    {
        // ps_checkout a journalisé en texte avant de passer au JSON : les deux doivent marcher.
        $legacy = '[2026-08-08 10:24:26] ps_checkout.ERROR: CreateOrder - Exception 42 '
            . 'SQLSTATE[42S22]: Column not found';

        $lines = $this->service->extractErrorLines($legacy);
        self::assertCount(1, $lines);
        self::assertSame(1, $this->service->analyse($lines)['technical']);
    }
}
