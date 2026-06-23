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
     * Authentifie une clé API.
     *
     * Mode 1 (legacy) : clé globale stockée dans REBUILDCONNECTOR_SETTINGS.
     * Mode 2 (multi-user) : utilisateur nommé dans rebuildconnector_user.
     *
     * @return array<string, mixed>
     * @throws AuthenticationException
     */
    public function authenticate(string $apiKey, ?string $shopUrl = null): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new AuthenticationException('Missing API key.');
        }

        $shopUrlResolved = ($shopUrl !== null && $shopUrl !== '')
            ? $shopUrl
            : Tools::getShopDomainSsl(true);

        // Mode 1 : clé API globale Admin (hash bcrypt, avec migration lazy transparente)
        if ($this->settingsService->verifyApiKey($apiKey)) {
            $jti = bin2hex(random_bytes(16));
            $claims = [
                'sub'         => 'prestaflow',
                'id_user'     => null,
                'id_employee' => null,
                'scopes'      => $this->settingsService->getScopes(),
                'shop_url'    => $shopUrlResolved,
                'jti'         => $jti,
            ];
            return $this->jwtService->createToken($claims, $this->settingsService->getTokenTtl());
        }

        // Mode 2 : utilisateur nommé
        require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/UserService.php';
        $userService = new UserService();
        $user = $userService->findByApiKey($apiKey);
        if ($user === null) {
            throw new AuthenticationException('Invalid API key.');
        }

        $scopes = json_decode((string) ($user['scopes'] ?? '[]'), true);
        if (!is_array($scopes)) {
            $scopes = [];
        }

        $jti = bin2hex(random_bytes(16));
        $claims = [
            'sub'         => 'user:' . $user['id_user'],
            'id_user'     => (int) $user['id_user'],
            'id_employee' => (int) $user['id_employee'],
            'scopes'      => $scopes,
            'shop_url'    => $shopUrlResolved,
            'jti'         => $jti,
        ];
        return $this->jwtService->createToken($claims, $this->settingsService->getTokenTtl());
    }
}
