<?php

defined('_PS_VERSION_') || exit;

class JwtService
{
    private SettingsService $settingsService;

    public function __construct(?SettingsService $settingsService = null)
    {
        $this->settingsService = $settingsService ?: new SettingsService();
    }

    /**
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     */
    public function createToken(array $claims, ?int $ttl = null): array
    {
        $ttl = $ttl ?? $this->settingsService->getTokenTtl();
        $now = time();
        $expiresAt = $now + $ttl;

        $payload = array_merge($claims, [
            'iss' => $this->getIssuer(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $expiresAt,
        ]);

        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $token = $this->encode($header, $payload, $this->settingsService->getJwtSecret());

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
            'issued_at' => date(DATE_ATOM, $now),
            'expires_at' => date(DATE_ATOM, $expiresAt),
        ];
    }

    public function verifyToken(string $token): bool
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            return false;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;

        $header = $this->decodeSegment($encodedHeader);
        $payload = $this->decodeSegment($encodedPayload);
        if (!is_array($header) || !is_array($payload)) {
            return false;
        }

        if (!isset($header['alg']) || $header['alg'] !== 'HS256') {
            return false;
        }

        $signature = $this->base64UrlDecode($encodedSignature);
        if ($signature === null) {
            return false;
        }

        $expected = hash_hmac(
            'sha256',
            $encodedHeader . '.' . $encodedPayload,
            $this->settingsService->getJwtSecret(),
            true
        );

        if (!hash_equals($expected, $signature)) {
            return false;
        }

        $now = time();
        if (!isset($payload['exp']) || !is_numeric($payload['exp']) || (int) $payload['exp'] < $now) {
            return false;
        }

        if (isset($payload['nbf']) && is_numeric($payload['nbf']) && (int) $payload['nbf'] > $now) {
            return false;
        }

        if (isset($payload['iss']) && is_string($payload['iss']) && $payload['iss'] !== $this->getIssuer()) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     */
    private function encode(array $header, array $payload, string $secret): string
    {
        $headerJson = json_encode($header, JSON_UNESCAPED_SLASHES);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($headerJson === false || $payloadJson === false) {
            throw new \RuntimeException('Unable to encode JWT segments.');
        }

        $encodedHeader = $this->base64UrlEncode($headerJson);
        $encodedPayload = $this->base64UrlEncode($payloadJson);

        $signature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $secret, true);
        $encodedSignature = $this->base64UrlEncode($signature);

        return implode('.', [$encodedHeader, $encodedPayload, $encodedSignature]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeSegment(string $segment): ?array
    {
        $decoded = $this->base64UrlDecode($segment);
        if ($decoded === null) {
            return null;
        }

        $data = json_decode($decoded, true);
        return is_array($data) ? $data : null;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): ?string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    private function getIssuer(): string
    {
        return Tools::getShopDomainSsl(true);
    }
}
