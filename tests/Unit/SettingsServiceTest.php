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

    public function testSetEnvOverridesParsesKeyValuePairs(): void
    {
        $service = new SettingsService();
        $service->setEnvOverrides("# comment\nFOO=bar\nBAR=baz qux");

        $this->assertSame(
            ['FOO' => 'bar', 'BAR' => 'baz qux'],
            $service->getEnvOverrides()
        );
    }

    public function testSetEnvOverridesRejectsInvalidKey(): void
    {
        $service = new SettingsService();

        $this->expectException(\InvalidArgumentException::class);
        $service->setEnvOverrides('foo=bar');
    }
}
