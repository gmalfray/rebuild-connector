<?php

defined('_PS_VERSION_') || exit;

/**
 * Résout l'`id_lang` à utiliser pour localiser le contenu renvoyé par l'API (statuts de commande,
 * noms produits/catégories, transporteurs, etc.) à partir de l'en-tête HTTP `Accept-Language` envoyé
 * par l'app PrestaFlow sur chaque requête.
 *
 * Contrat (verrouillé avec l'app, ne pas dévier) :
 * - L'app envoie un tag de langue primaire simple (ex. `de`, `fr-FR,fr;q=0.9`) — on ne garde que le
 *   premier sous-tag primaire, normalisé en ISO 639-1 minuscule.
 * - Le tag doit correspondre à une langue **installée ET active pour la boutique courante** : on
 *   réutilise `Language::getLanguages(true, $idShop)`, l'API core déjà utilisée par PrestaShop pour ne
 *   proposer que les langues actives associées à la boutique (ex. sélecteur de langue du front) —
 *   plus sûr qu'un `Language::getIdByIso()` brut, qui ne filtre ni l'activation ni l'association
 *   boutique.
 * - Sinon (langue absente, non installée ou non active) → fallback sur `PS_LANG_DEFAULT`, le
 *   comportement historique. C'est la garantie anti-régression : une boutique n'ayant que le FR
 *   installé (ex. pensebonheur en prod) ne voit AUCUN changement de comportement.
 */
class LanguageResolver
{
    public static function resolveIdLang(?int $idShop = null): int
    {
        $primaryTag = self::extractPrimaryLanguageTag($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);

        if ($primaryTag !== null) {
            $idLang = self::findActiveIdLangByIso($primaryTag, $idShop);
            if ($idLang !== null) {
                return $idLang;
            }
        }

        return self::getDefaultIdLang();
    }

    /**
     * Extrait et normalise le sous-tag de langue primaire d'un en-tête `Accept-Language`.
     *
     * Exemples : `de` → `de` ; `fr-FR,fr;q=0.9` → `fr` ; `EN-us` → `en` ; `""`/absent → `null`.
     */
    public static function extractPrimaryLanguageTag(?string $header): ?string
    {
        if (!is_string($header) || trim($header) === '') {
            return null;
        }

        // On ne garde que la 1ère entrée (avant la 1ère virgule), on retire le poids `;q=...`,
        // puis le sous-tag région/script (après le 1er `-`).
        $firstEntry = explode(',', $header)[0];
        $withoutQuality = explode(';', $firstEntry)[0];
        $primarySubtag = explode('-', trim($withoutQuality))[0];
        $normalized = strtolower(trim($primarySubtag));

        return $normalized !== '' ? $normalized : null;
    }

    private static function findActiveIdLangByIso(string $isoCode, ?int $idShop): ?int
    {
        if (!class_exists('Language')) {
            return null;
        }

        $shopId = $idShop ?? self::getCurrentShopId();

        /** @var array<int, array<string, mixed>> $languages */
        $languages = Language::getLanguages(true, $shopId);
        foreach ($languages as $language) {
            $isoLang = isset($language['iso_code']) ? strtolower((string) $language['iso_code']) : '';
            if ($isoLang !== '' && $isoLang === $isoCode) {
                return isset($language['id_lang']) ? (int) $language['id_lang'] : null;
            }
        }

        return null;
    }

    private static function getCurrentShopId(): int
    {
        $context = Context::getContext();
        if ($context->shop instanceof Shop) {
            return (int) $context->shop->id;
        }

        return (int) Configuration::get('PS_SHOP_DEFAULT');
    }

    private static function getDefaultIdLang(): int
    {
        return (int) Configuration::get('PS_LANG_DEFAULT');
    }
}
