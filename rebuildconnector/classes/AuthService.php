<?php

defined('_PS_VERSION_') || exit;

class AuthService
{
    /** @var JwtService */
    private $jwtService;

    public function __construct(JwtService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    public function authenticate(string $username, string $password): array
    {
        // TODO: validate credentials against PrestaShop.
        return $this->jwtService->createToken(['sub' => $username]);
    }
}
