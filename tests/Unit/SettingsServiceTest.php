<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

final class SettingsServiceTest extends TestCase
{
    public function testSetAllowedIpRangesStoresNormalizedValues(): void
    {
        $service = new SettingsService();
        $service->setAllowedIpRanges("192.168.1.0/24\n10.0.0.1\n2001:db8::/48");

        $this->assertSame(
            ['192.168.1.0/24', '10.0.0.1', '2001:db8::/48'],
            $service->getAllowedIpRanges()
        );
    }

    public function testSetAllowedIpRangesRejectsInvalidInput(): void
    {
        $service = new SettingsService();

        $this->expectException(\InvalidArgumentException::class);
        $service->setAllowedIpRanges('invalid-range');
    }

    // =========================================================================
    // renderSecretPreview — le masquage doit TOUJOURS s'appliquer (m3).
    // Un secret court (≤ 8 caractères) ne doit JAMAIS être renvoyé en clair.
    // =========================================================================

    public function testRenderSecretPreviewFullyMasksShortSecret(): void
    {
        $preview = $this->invokeRenderSecretPreview('abc');

        $this->assertSame('•••', $preview);
        $this->assertStringNotContainsString('a', $preview);
    }

    public function testRenderSecretPreviewMasksEightCharSecretEntirely(): void
    {
        $preview = $this->invokeRenderSecretPreview('12345678');

        // Longueur ≤ 8 : masquage intégral, aucune fuite tête/queue.
        $this->assertSame('••••••••', $preview);
    }

    public function testRenderSecretPreviewKeepsHeadAndTailForLongSecret(): void
    {
        $preview = $this->invokeRenderSecretPreview('ABCD0000000000WXYZ');

        // > 8 caractères : 4 en tête + bullets + 4 en queue.
        $this->assertStringStartsWith('ABCD', $preview);
        $this->assertStringEndsWith('WXYZ', $preview);
        $this->assertStringNotContainsString('0', $preview);
    }

    public function testRenderSecretPreviewReturnsEmptyForEmptySecret(): void
    {
        $this->assertSame('', $this->invokeRenderSecretPreview(''));
    }

    // =========================================================================
    // getLabelShippedStateId — fallback par défaut (20) + override configurable.
    // =========================================================================

    public function testGetLabelShippedStateIdDefaultsTo20WhenUnset(): void
    {
        $service = new SettingsService();

        $this->assertSame(20, $service->getLabelShippedStateId());
    }

    public function testSetLabelShippedStateIdOverridesDefault(): void
    {
        $service = new SettingsService();
        $service->setLabelShippedStateId(30);

        $this->assertSame(30, $service->getLabelShippedStateId());
    }

    public function testSetLabelShippedStateIdRejectsNonPositiveValueByFallingBackToDefault(): void
    {
        $service = new SettingsService();
        $service->setLabelShippedStateId(0);

        $this->assertSame(20, $service->getLabelShippedStateId());
    }

    private function invokeRenderSecretPreview(string $secret): string
    {
        $method = new \ReflectionMethod(SettingsService::class, 'renderSecretPreview');
        $method->setAccessible(true);

        return (string) $method->invoke(new SettingsService(), $secret);
    }
}
