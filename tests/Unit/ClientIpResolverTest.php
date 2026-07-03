<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Tests unitaires pour ClientIpResolver (M3) : ne doit JAMAIS se fier au 1er élément de
 * X-Forwarded-For (falsifiable par le client) pour une décision de sécurité — contrairement à
 * Tools::getRemoteAddr() du core, qu'il remplace dans BaseApiController/RebuildConnector.
 */
final class ClientIpResolverTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalServer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
        unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        parent::tearDown();
    }

    public function testResolvesFromRemoteAddrWhenNoOtherHeaderPresent(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';

        $this->assertSame('203.0.113.10', ClientIpResolver::resolve());
    }

    public function testPrefersCfConnectingIpOverRemoteAddr(): void
    {
        // REMOTE_ADDR = IP interne de l'edge Cloudflare (vue par nginx en amont) : CF-Connecting-IP,
        // posé par Cloudflare lui-même, est la source fiable de l'IP client réelle.
        $_SERVER['REMOTE_ADDR'] = '198.51.100.5';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.20';

        $this->assertSame('203.0.113.20', ClientIpResolver::resolve());
    }

    public function testNeverTrustsXForwardedForFirstElement(): void
    {
        // M3 : un client malveillant peut poser n'importe quelle valeur dans X-Forwarded-For.
        // Sans CF-Connecting-IP, ClientIpResolver ne doit JAMAIS lire ce header — seul REMOTE_ADDR
        // (l'IP TCP réellement vue par le serveur) doit être utilisé, contrairement à
        // Tools::getRemoteAddr() du core.
        $_SERVER['REMOTE_ADDR'] = '198.51.100.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 198.51.100.5';

        $this->assertSame('198.51.100.5', ClientIpResolver::resolve());
    }

    public function testIgnoresInvalidCfConnectingIpAndFallsBackToRemoteAddr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.5';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';

        $this->assertSame('198.51.100.5', ClientIpResolver::resolve());
    }

    public function testReturnsNullWhenNoUsableIpAvailable(): void
    {
        $this->assertNull(ClientIpResolver::resolve());
    }

    public function testAcceptsIpv6Addresses(): void
    {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '2001:db8::1';

        $this->assertSame('2001:db8::1', ClientIpResolver::resolve());
    }
}
