<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class HubKeyVerifierTest extends TestCase
{
    private static string $privateKeyPem;
    private static string $publicKeyPem;

    public static function setUpBeforeClass(): void
    {
        // Paire RSA jetable générée pour les tests — jamais la clé privée réelle du hub, que ce
        // module n'a jamais (voir HubKeyVerifier::HUB_SIGNING_PUBLIC_KEY, qui n'embarque que la
        // clé PUBLIQUE réelle).
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($resource, 'Impossible de générer la paire RSA de test.');

        openssl_pkey_export($resource, $privateKeyPem);
        self::$privateKeyPem = $privateKeyPem;

        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);
        self::$publicKeyPem = $details['key'];
    }

    private function sign(string $payloadJson): string
    {
        openssl_sign($payloadJson, $signature, self::$privateKeyPem, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    private function makeVerifier(): HubKeyVerifier
    {
        return new HubKeyVerifier(self::$publicKeyPem);
    }

    public function testVerifySignatureAcceptsValidSignature(): void
    {
        $payload = '{"shop_url":"https://boutique.example","license_key":"rbk_new","issued_at":"2026-07-06T10:00:00Z"}';
        $signature = $this->sign($payload);

        $this->assertTrue($this->makeVerifier()->verifySignature($payload, $signature));
    }

    public function testVerifySignatureRejectsTamperedPayload(): void
    {
        $payload = '{"shop_url":"https://boutique.example","license_key":"rbk_new","issued_at":"2026-07-06T10:00:00Z"}';
        $signature = $this->sign($payload);

        // Un octet modifié après signature (ex. ré-encodage) doit invalider la vérification —
        // c'est exactement le risque documenté : ne jamais ré-encoder avant de vérifier.
        $tampered = '{"shop_url":"https://attacker.example","license_key":"rbk_new","issued_at":"2026-07-06T10:00:00Z"}';

        $this->assertFalse($this->makeVerifier()->verifySignature($tampered, $signature));
    }

    public function testVerifySignatureRejectsSignatureFromAnotherKey(): void
    {
        $otherResource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($otherResource, $otherPrivateKeyPem);

        $payload = '{"shop_url":"https://boutique.example","license_key":"rbk_new","issued_at":"2026-07-06T10:00:00Z"}';
        openssl_sign($payload, $signature, $otherPrivateKeyPem, OPENSSL_ALGO_SHA256);

        $this->assertFalse($this->makeVerifier()->verifySignature($payload, base64_encode($signature)));
    }

    public function testVerifySignatureRejectsEmptyInputs(): void
    {
        $verifier = $this->makeVerifier();
        $this->assertFalse($verifier->verifySignature('', 'c2lnbmF0dXJl'));
        $this->assertFalse($verifier->verifySignature('{"a":1}', ''));
    }

    public function testVerifySignatureRejectsInvalidBase64(): void
    {
        // base64_decode(..., true) doit rejeter un base64url (contient - ou _) : le contrat exige
        // du base64 standard.
        $verifier = $this->makeVerifier();
        $this->assertFalse($verifier->verifySignature('{"a":1}', 'not-valid-base64-!!!'));
    }

    public function testDecodePayloadReturnsNormalizedArray(): void
    {
        $payload = '{"shop_url":"https://boutique.example","license_key":"rbk_new","issued_at":"2026-07-06T10:00:00Z"}';

        $decoded = $this->makeVerifier()->decodePayload($payload);

        $this->assertSame([
            'shop_url' => 'https://boutique.example',
            'license_key' => 'rbk_new',
            'issued_at' => '2026-07-06T10:00:00Z',
        ], $decoded);
    }

    /**
     * @dataProvider malformedPayloadProvider
     */
    public function testDecodePayloadRejectsMalformedPayloads(string $payload): void
    {
        $this->assertNull($this->makeVerifier()->decodePayload($payload));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedPayloadProvider(): array
    {
        return [
            'not json' => ['not-json-at-all'],
            'json but not object' => ['[1,2,3]'],
            'missing license_key' => ['{"shop_url":"https://a.example","issued_at":"2026-07-06T10:00:00Z"}'],
            'missing shop_url' => ['{"license_key":"rbk_new","issued_at":"2026-07-06T10:00:00Z"}'],
            'missing issued_at' => ['{"shop_url":"https://a.example","license_key":"rbk_new"}'],
            'empty shop_url' => ['{"shop_url":"","license_key":"rbk_new","issued_at":"2026-07-06T10:00:00Z"}'],
            'non-string license_key' => ['{"shop_url":"https://a.example","license_key":42,"issued_at":"2026-07-06T10:00:00Z"}'],
        ];
    }

    /**
     * @dataProvider shopUrlProvider
     */
    public function testShopUrlMatches(string $payloadUrl, string $actualUrl, bool $expected): void
    {
        $this->assertSame($expected, $this->makeVerifier()->shopUrlMatches($payloadUrl, $actualUrl));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function shopUrlProvider(): array
    {
        return [
            'exact match' => ['https://boutique.example', 'https://boutique.example', true],
            'trailing slash tolerated' => ['https://boutique.example/', 'https://boutique.example', true],
            'scheme http normalized to https' => ['http://boutique.example', 'https://boutique.example', true],
            'case-insensitive' => ['https://Boutique.Example', 'https://boutique.example', true],
            'different domain' => ['https://attacker.example', 'https://boutique.example', false],
            'actual shop url unknown/empty' => ['https://boutique.example', '', false],
        ];
    }

    public function testIsWithinValidityWindowAcceptsFreshTimestamp(): void
    {
        $now = 1_800_000_000;
        $issuedAt = gmdate('Y-m-d\TH:i:s\Z', $now);

        $this->assertTrue($this->makeVerifier()->isWithinValidityWindow($issuedAt, $now));
    }

    public function testIsWithinValidityWindowAcceptsBoundary(): void
    {
        $now = 1_800_000_000;
        $issuedAt = gmdate('Y-m-d\TH:i:s\Z', $now - 300);

        $this->assertTrue($this->makeVerifier()->isWithinValidityWindow($issuedAt, $now));
    }

    public function testIsWithinValidityWindowRejectsStaleTimestamp(): void
    {
        $now = 1_800_000_000;
        $issuedAt = gmdate('Y-m-d\TH:i:s\Z', $now - 301);

        $this->assertFalse($this->makeVerifier()->isWithinValidityWindow($issuedAt, $now));
    }

    public function testIsWithinValidityWindowRejectsFutureTimestamp(): void
    {
        // Anti-rejeu symétrique : un issued_at trop loin dans le futur (horloge désynchronisée,
        // ou tentative de préparer un rejeu tardif) est rejeté tout autant qu'un timestamp trop vieux.
        $now = 1_800_000_000;
        $issuedAt = gmdate('Y-m-d\TH:i:s\Z', $now + 301);

        $this->assertFalse($this->makeVerifier()->isWithinValidityWindow($issuedAt, $now));
    }

    public function testIsWithinValidityWindowRejectsUnparsableDate(): void
    {
        $this->assertFalse($this->makeVerifier()->isWithinValidityWindow('not-a-date'));
    }
}
