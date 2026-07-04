<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/**
 * Tests unitaires pour LanguageResolver : résolution de l'id_lang à partir de l'en-tête
 * `Accept-Language` envoyé par l'app PrestaFlow (contrat verrouillé, cf. LanguageResolver).
 *
 * Garantie anti-régression : une boutique n'ayant que le FR installé (ex. pensebonheur en prod,
 * simulé par le stub par défaut de Language::getLanguages()) ne doit JAMAIS voir son comportement
 * changer, quel que soit l'en-tête envoyé.
 */
final class LanguageResolverTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalServer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
        Language::$testLanguages = null;
        Configuration::$testValues = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        Language::$testLanguages = null;
        Configuration::$testValues = [];
        parent::tearDown();
    }

    // --- extractPrimaryLanguageTag() -----------------------------------------------------------

    public function testExtractPrimaryLanguageTagFromSimpleTag(): void
    {
        $this->assertSame('de', LanguageResolver::extractPrimaryLanguageTag('de'));
    }

    public function testExtractPrimaryLanguageTagStripsQualityAndRegion(): void
    {
        // Format réel envoyé par certains navigateurs/OS : région + poids qualité.
        $this->assertSame('de', LanguageResolver::extractPrimaryLanguageTag('de-DE,de;q=0.9'));
        $this->assertSame('fr', LanguageResolver::extractPrimaryLanguageTag('fr-FR,fr;q=0.9,en;q=0.8'));
    }

    public function testExtractPrimaryLanguageTagNormalizesCase(): void
    {
        $this->assertSame('en', LanguageResolver::extractPrimaryLanguageTag('EN-us'));
    }

    public function testExtractPrimaryLanguageTagReturnsNullWhenAbsentOrEmpty(): void
    {
        $this->assertNull(LanguageResolver::extractPrimaryLanguageTag(null));
        $this->assertNull(LanguageResolver::extractPrimaryLanguageTag(''));
        $this->assertNull(LanguageResolver::extractPrimaryLanguageTag('   '));
    }

    // --- resolveIdLang() ------------------------------------------------------------------------

    public function testResolvesToMatchingActiveLanguage(): void
    {
        Language::$testLanguages = [
            ['id_lang' => 1, 'iso_code' => 'fr'],
            ['id_lang' => 2, 'iso_code' => 'de'],
        ];
        Configuration::$testValues['PS_LANG_DEFAULT'] = 1;
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de';

        $this->assertSame(2, LanguageResolver::resolveIdLang());
    }

    public function testResolvesToMatchingActiveLanguageWithFullHeaderFormat(): void
    {
        // Format complet type navigateur : région + poids qualité, plusieurs langues.
        Language::$testLanguages = [
            ['id_lang' => 1, 'iso_code' => 'fr'],
            ['id_lang' => 2, 'iso_code' => 'de'],
        ];
        Configuration::$testValues['PS_LANG_DEFAULT'] = 1;
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9,en;q=0.8';

        $this->assertSame(2, LanguageResolver::resolveIdLang());
    }

    public function testFallsBackToShopDefaultWhenTagNotInstalled(): void
    {
        // Boutique n'ayant que le FR installé/actif (ex. pensebonheur en prod) : un header `de`
        // ne doit JAMAIS résoudre vers une langue inexistante, il doit retomber sur la langue
        // par défaut de la boutique — comportement historique inchangé.
        Language::$testLanguages = [
            ['id_lang' => 1, 'iso_code' => 'fr'],
        ];
        Configuration::$testValues['PS_LANG_DEFAULT'] = 1;
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de';

        $this->assertSame(1, LanguageResolver::resolveIdLang());
    }

    public function testFallsBackToShopDefaultWhenHeaderAbsent(): void
    {
        Language::$testLanguages = [
            ['id_lang' => 1, 'iso_code' => 'fr'],
            ['id_lang' => 2, 'iso_code' => 'de'],
        ];
        Configuration::$testValues['PS_LANG_DEFAULT'] = 1;
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);

        $this->assertSame(1, LanguageResolver::resolveIdLang());
    }

    public function testFallsBackToShopDefaultWhenHeaderEmpty(): void
    {
        Language::$testLanguages = [
            ['id_lang' => 1, 'iso_code' => 'fr'],
            ['id_lang' => 2, 'iso_code' => 'de'],
        ];
        Configuration::$testValues['PS_LANG_DEFAULT'] = 1;
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = '';

        $this->assertSame(1, LanguageResolver::resolveIdLang());
    }

    public function testMatchIsCaseInsensitiveOnStoredIsoCode(): void
    {
        Language::$testLanguages = [
            ['id_lang' => 1, 'iso_code' => 'FR'],
            ['id_lang' => 2, 'iso_code' => 'DE'],
        ];
        Configuration::$testValues['PS_LANG_DEFAULT'] = 1;
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de';

        $this->assertSame(2, LanguageResolver::resolveIdLang());
    }
}
