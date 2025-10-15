<?php

defined('_PS_VERSION_') || exit;

class JwtService
{
    public function createToken(array $claims): array
    {
        // TODO: implement JWT creation.
        return [
            'token' => 'placeholder',
            'expires_in' => 3600,
        ];
    }

    public function verifyToken(string $token): bool
    {
        // TODO: implement JWT verification.
        return true;
    }
}
