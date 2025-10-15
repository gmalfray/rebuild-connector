<?php

defined('_PS_VERSION_') || exit;

class AuthService
{
    private JwtService $jwtService;

    public function __construct(JwtService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    /**
     * @return array<string, mixed>
     */
    public function authenticate(string $username, string $password): array
    {
        // TODO: validate credentials against PrestaShop.
        return $this->jwtService->createToken(['sub' => $username]);
    }
}
