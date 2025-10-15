<?php

defined('_PS_VERSION_') || exit;

class AuthService
{
    private JwtService $jwtService;
    private SettingsService $settingsService;

    public function __construct(?JwtService $jwtService = null, ?SettingsService $settingsService = null)
    {
        $this->settingsService = $settingsService ?: new SettingsService();
        $this->jwtService = $jwtService ?: new JwtService($this->settingsService);
    }

    /**
     * @return array<string, mixed>
     */
    public function authenticate(string $apiKey, ?string $shopUrl = null): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new AuthenticationException('Missing API key.');
        }

        $storedKey = $this->settingsService->getApiKey();
        if ($storedKey === null || $storedKey === '' || !hash_equals($storedKey, $apiKey)) {
            throw new AuthenticationException('Invalid API key.');
        }

        $claims = [
            'sub' => 'prestaflow',
            'scopes' => $this->settingsService->getScopes(),
            'shop_url' => $shopUrl !== null && $shopUrl !== ''
                ? $shopUrl
                : Tools::getShopDomainSsl(true),
        ];

        return $this->jwtService->createToken($claims, $this->settingsService->getTokenTtl());
    }
}
