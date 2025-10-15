<?php

defined('_PS_VERSION_') || exit;

class RebuildconnectorApiModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    public function initContent(): void
    {
        parent::initContent();

        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Allow: POST');
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
            if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
                error_log('[RebuildConnector] Auth error: ' . $exception->getMessage());
            }

            $this->renderJson([
                'error' => 'server_error',
                'message' => $this->t('api.error.unexpected', [], 'Unexpected error during authentication.'),
            ], 500);

            return;
        }

        $response = [
            'token_type' => $token['token_type'] ?? 'Bearer',
            'access_token' => $token['token'] ?? '',
            'expires_in' => $token['expires_in'] ?? 0,
            'issued_at' => $token['issued_at'] ?? null,
            'expires_at' => $token['expires_at'] ?? null,
            'scopes' => $this->getSettingsService()->getScopes(),
        ];

        $this->renderJson($response, 200);
    }
}
