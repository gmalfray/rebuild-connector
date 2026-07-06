<?php

defined('_PS_VERSION_') || exit;

/**
 * Vérifie l'authenticité des callbacks signés envoyés par le hub push centralisé
 * (push.rebuild-it.fr) pour la récupération self-service d'une licence
 * (POST controller `hubkey`, cf. rebuild-it/docs/push-recover.md).
 *
 * Principe : le hub signe le payload JSON tel quel (RSA-2048, SHA-256, PKCS#1 v1.5) avant de
 * l'envoyer ; ce module vérifie avec la clé PUBLIQUE correspondante (aucun risque à la committer).
 *
 * IMPORTANT — la vérification porte sur la chaîne JSON BRUTE du payload, telle que reçue,
 * jamais sur une ré-sérialisation de l'objet décodé : l'ordre des clés, les espaces ou
 * l'échappement unicode produits par un json_encode() local ne reproduiraient pas forcément
 * l'octet exact signé côté hub, ce qui invaliderait silencieusement des signatures pourtant
 * légitimes. Le payload décodé (shop_url, license_key, issued_at) ne doit donc être lu qu'APRÈS
 * une vérification réussie sur la chaîne brute.
 */
final class HubKeyVerifier
{
    /**
     * Clé publique du hub (RSA-2048), dédiée à l'identité du hub pour ce callback de
     * récupération de licence. Distincte de la clé de service FCM (que le module n'a jamais).
     */
    public const HUB_SIGNING_PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAwp8A4RrlhQPh6iQddXLy
O1Y3kUGrkCftOTI1IAAwpCSVUjSbbwgMcrHO/GCR35e2IrpuQMyV5o9fH32fWdfa
i415OhBtDXFxI+274/0qyfc85ThiMenlG9znbmB0Wa6a6nXFcw9XUCMCzR3IeccX
llwP/VXxvxfCzaItbKLcERhJdB0mM/Oum74FXXiINSRtVWD9HWhhocXSIOR+ywaK
3avcm+HL1wJpyLSLr7rJThVvAj5WsuO/4mhdu4eX5etlLFs9Iko0fjXquhrc4yQ9
7SX3XNcryzlGW+K8kCkCLFbwv5SvRaOhoBdeySnQYTJCzsxG8IUZKBUeKNvN4iQb
VwIDAQAB
-----END PUBLIC KEY-----
PEM;

    /** Fenêtre de validité anti-rejeu autour de `issued_at` (± 5 minutes). */
    private const REPLAY_WINDOW_SECONDS = 300;

    private string $publicKeyPem;

    /**
     * @param string|null $publicKeyPem Clé publique PEM à utiliser pour la vérification.
     *                                  Par défaut la clé du hub réel ({@see HUB_SIGNING_PUBLIC_KEY}).
     *                                  Seam de test : permet aux tests unitaires d'injecter une paire
     *                                  RSA jetable pour produire des signatures valides sans exposer
     *                                  la clé privée réelle du hub (que ce module n'a jamais).
     */
    public function __construct(?string $publicKeyPem = null)
    {
        $this->publicKeyPem = $publicKeyPem ?? self::HUB_SIGNING_PUBLIC_KEY;
    }

    /**
     * Vérifie la signature RSA (SHA-256, PKCS#1 v1.5) sur la chaîne JSON brute du payload.
     *
     * @param string $payloadJson  Chaîne JSON brute EXACTE reçue du hub (jamais ré-encodée).
     * @param string $signatureB64 Signature en base64 STANDARD (pas base64url).
     */
    public function verifySignature(string $payloadJson, string $signatureB64): bool
    {
        if ($payloadJson === '' || $signatureB64 === '') {
            return false;
        }

        $signature = base64_decode($signatureB64, true);
        if ($signature === false) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($this->publicKeyPem);
        if ($publicKey === false) {
            return false;
        }

        return openssl_verify($payloadJson, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Décode le payload JSON (à n'appeler QU'APRÈS une vérification de signature réussie) et
     * retourne un tableau normalisé, ou null si le JSON est invalide ou incomplet.
     *
     * @return array{shop_url: string, license_key: string, issued_at: string}|null
     */
    public function decodePayload(string $payloadJson): ?array
    {
        $decoded = json_decode($payloadJson, true);
        if (!is_array($decoded)) {
            return null;
        }

        $shopUrl = $decoded['shop_url'] ?? null;
        $licenseKey = $decoded['license_key'] ?? null;
        $issuedAt = $decoded['issued_at'] ?? null;

        if (!is_string($shopUrl) || $shopUrl === ''
            || !is_string($licenseKey) || $licenseKey === ''
            || !is_string($issuedAt) || $issuedAt === ''
        ) {
            return null;
        }

        return [
            'shop_url' => $shopUrl,
            'license_key' => $licenseKey,
            'issued_at' => $issuedAt,
        ];
    }

    /**
     * Vérifie que le shop_url transporté par le payload correspond bien au domaine réel de cette
     * boutique (comparaison normalisée : schéma forcé en https, casse et slash de fin ignorés).
     * C'est la preuve de contrôle du domaine : seule la vraie boutique reçoit ce callback sur
     * son propre domaine.
     */
    public function shopUrlMatches(string $payloadShopUrl, string $actualShopUrl): bool
    {
        if ($actualShopUrl === '') {
            return false;
        }

        return $this->normalizeUrl($payloadShopUrl) === $this->normalizeUrl($actualShopUrl);
    }

    /**
     * Anti-rejeu : `issued_at` (ISO 8601 UTC) doit être dans une fenêtre de ± 5 minutes autour de
     * l'heure serveur actuelle.
     */
    public function isWithinValidityWindow(string $issuedAtIso, ?int $now = null): bool
    {
        $issuedAtTimestamp = strtotime($issuedAtIso);
        if ($issuedAtTimestamp === false) {
            return false;
        }

        $now = $now ?? time();

        return abs($now - $issuedAtTimestamp) <= self::REPLAY_WINDOW_SECONDS;
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $url = preg_replace('#^http://#i', 'https://', $url) ?? $url;
        if (stripos($url, 'https://') !== 0) {
            $url = 'https://' . ltrim($url, '/');
        }

        return rtrim(strtolower($url), '/');
    }
}
