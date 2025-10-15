<?php

defined('_PS_VERSION_') || exit;

abstract class RebuildconnectorBaseApiModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;
    /** @var bool */
    public $display_header = false;
    /** @var bool */
    public $display_footer = false;

    private ?SettingsService $settingsService = null;
    private bool $settingsBootstrapped = false;
    private ?JwtService $jwtService = null;
    private ?AuthService $authService = null;

    /**
     * @param array<string, mixed> $payload
     */
    protected function renderJson(array $payload, int $statusCode = 200): void
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        $body = json_encode($payload);
        $this->ajaxRender($body === false ? '{}' : $body);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeRequestBody(): array
    {
        $rawBody = Tools::file_get_contents('php://input');
        if ($rawBody === false) {
            throw new \InvalidArgumentException($this->l('Unable to read request body.'));
        }

        if ($rawBody === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException($this->l('Request body must be valid JSON.'));
        }

        return $decoded;
    }

    protected function getSettingsService(): SettingsService
    {
        if ($this->settingsService === null) {
            $this->settingsService = new SettingsService();
        }

        if (!$this->settingsBootstrapped) {
            $this->settingsService->ensureDefaults();
            $this->settingsBootstrapped = true;
        }

        return $this->settingsService;
    }

    protected function getJwtService(): JwtService
    {
        if ($this->jwtService === null) {
            $this->jwtService = new JwtService($this->getSettingsService());
        }

        return $this->jwtService;
    }

    protected function getAuthService(): AuthService
    {
        if ($this->authService === null) {
            $this->authService = new AuthService($this->getJwtService(), $this->getSettingsService());
        }

        return $this->authService;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function validateBearerToken(): ?array
    {
        $header = $this->getAuthorizationHeader();
        if ($header === null || stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            return null;
        }

        if (!$this->getJwtService()->verifyToken($token)) {
            return null;
        }

        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            return null;
        }

        $payload = $this->base64JsonDecode($segments[1]);
        return is_array($payload) ? $payload : null;
    }

    protected function getAuthorizationHeader(): ?string
    {
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return (string) $_SERVER['HTTP_AUTHORIZATION'];
        }

        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $name => $value) {
                if (strtolower($name) === 'authorization') {
                    return (string) $value;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function base64JsonDecode(string $segment): ?array
    {
        $remainder = strlen($segment) % 4;
        if ($remainder !== 0) {
            $segment .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        $data = json_decode($decoded, true);
        return is_array($data) ? $data : null;
    }
}
