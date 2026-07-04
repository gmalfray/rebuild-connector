<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/BaseApiController.php';

class RebuildconnectorApiModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    /** Limite de tentatives de login par minute et par IP (indépendante du rate-limit global). */
    private const LOGIN_RATE_LIMIT = 5;

    public function initContent(): void
    {
        parent::initContent();

        // Rate-limiting spécifique à l'endpoint de login, indépendant de l'auth.
        $this->enforceLoginRateLimit();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            // @ : header() peut émettre un warning "headers already sent" hors contexte HTTP réel
            // (ex. exécution CLI PHPUnit où du texte a déjà été écrit sur stdout) ; sans impact
            // en production (le header Allow est informatif sur une 405).
            @header('Allow: POST');
            $this->renderJson([
                'error' => 'method_not_allowed',
                'message' => $this->t('api.error.method_not_allowed', [], 'This endpoint only accepts POST requests.'),
            ], 405);

            return;
        }

        try {
            $payload = $this->decodeRequestBody();
        } catch (\InvalidArgumentException $exception) {
            $this->renderJson([
                'error' => 'invalid_request',
                'message' => $exception->getMessage(),
            ], 400);

            return;
        }

        $apiKey = isset($payload['api_key']) ? trim((string) $payload['api_key']) : '';
        $shopUrl = isset($payload['shop_url']) ? trim((string) $payload['shop_url']) : null;

        if ($apiKey === '') {
            $this->renderJson([
                'error' => 'invalid_request',
                'message' => $this->t('api.error.api_key_required', [], 'The api_key field is required.'),
            ], 400);

            return;
        }

        try {
            $token = $this->getAuthService()->authenticate($apiKey, $shopUrl);
        } catch (AuthenticationException $exception) {
            $this->renderJson([
                'error' => 'unauthorized',
                'message' => $this->t('api.error.auth_failed', [], 'Authentication failed. Check your API key.'),
            ], 401);

            return;
        } catch (\Throwable $exception) {
            if ($this->isDevMode()) {
                error_log('[RebuildConnector] Auth error: ' . $exception->getMessage());
            }

            $this->renderJson([
                'error' => 'server_error',
                'message' => $this->t('api.error.unexpected', [], 'Unexpected error occurred.'),
            ], 500);

            return;
        }

        $rawToken = $token['token'] ?? '';
        $response = [
            'token_type' => $token['token_type'] ?? 'Bearer',
            'access_token' => $rawToken,
            'token' => $rawToken,
            'expires_in' => $token['expires_in'] ?? 0,
            'issued_at' => $token['issued_at'] ?? null,
            'expires_at' => $token['expires_at'] ?? null,
            'scopes' => isset($token['scopes']) && is_array($token['scopes']) ? $token['scopes'] : $this->getSettingsService()->getScopes(),
        ];

        $this->renderJson($response, 200);
    }

    /**
     * Rate-limiting dédié à l'endpoint de login : 5 tentatives/minute/IP,
     * appliqué avant toute vérification d'authentification.
     */
    private function enforceLoginRateLimit(): void
    {
        $ip = $this->getClientIp();
        if ($ip === null) {
            return;
        }

        $identifier = 'login:' . $ip;

        if (!$this->getRateLimiter()->isAllowed($identifier, self::LOGIN_RATE_LIMIT)) {
            $this->recordAuditEvent('security.login_rate_limited', ['ip' => $ip]);
            $this->jsonError(
                'too_many_requests',
                $this->t('api.error.rate_limited', [], 'Too many requests. Please try again later.'),
                429
            );
            exit;
        }
    }
}
