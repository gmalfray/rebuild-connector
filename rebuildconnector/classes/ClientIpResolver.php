<?php

defined('_PS_VERSION_') || exit;

/**
 * Résout l'adresse IP cliente à partir d'une source fiable, pour toute décision de sécurité
 * (allowlist IP, rate-limiting, audit).
 *
 * Ne PAS utiliser `Tools::getRemoteAddr()` (core) pour ces décisions : cette méthode se fie au
 * 1ᵉʳ élément de l'en-tête `X-Forwarded-For` dès que REMOTE_ADDR est une IP privée/loopback — or ce
 * header est entièrement contrôlé par le client HTTP (un attaquant peut y injecter n'importe quelle
 * valeur), ce qui permet de spoofer l'IP vue par le module et de contourner l'allowlist IP ou le
 * rate-limiting.
 *
 * On privilégie `CF-Connecting-IP` (posé par l'edge Cloudflare, non falsifiable par le client une
 * fois le trafic passé par Cloudflare) puis, à défaut, `REMOTE_ADDR` (l'adresse TCP réellement vue par
 * le serveur, seule source non falsifiable en l'absence de proxy de confiance connu).
 */
class ClientIpResolver
{
    public static function resolve(): ?string
    {
        $cfConnectingIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null;
        if (is_string($cfConnectingIp) && $cfConnectingIp !== '' && self::isValidIp($cfConnectingIp)) {
            return $cfConnectingIp;
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        if (is_string($remoteAddr) && $remoteAddr !== '' && self::isValidIp($remoteAddr)) {
            return $remoteAddr;
        }

        return null;
    }

    private static function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}
