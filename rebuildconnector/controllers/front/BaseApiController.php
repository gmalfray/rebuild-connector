<?php

defined('_PS_VERSION_') || exit;

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/SettingsService.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/JwtService.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/AuthService.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/Exceptions/AuthenticationException.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/Exceptions/AuthorizationException.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/TranslationService.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/RateLimiterService.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/AuditLogService.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/WebhookService.php';

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
    private ?TranslationService $translationService = null;
    private ?RateLimiterService $rateLimiterService = null;
    private ?AuditLogService $auditLogService = null;
    private ?WebhookService $webhookService = null;
    /** @var array<string, bool> */
    private array $rateLimitHits = [];
    private ?string $clientIp = null;
    private bool $auditRecorded = false;

    public function init(): void
    {
        parent::init();
        $this->clientIp = $this->resolveClientIp();
        $this->enforceIpAllowlist();
        $this->enforceRateLimit();
    }

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
            throw new \InvalidArgumentException($this->t('api.error.read_body', [], 'Unable to read request body.'));
        }

        if ($rawBody === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException($this->t('api.error.invalid_json', [], 'Request body must be valid JSON.'));
        }

        return $decoded;
    }

    /**
     * @param array<int, string> $requiredScopes
     * @return array<string, mixed>
     *
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    protected function requireAuth(array $requiredScopes = []): array
    {
        $payload = $this->validateBearerToken();
        if ($payload === null) {
            throw new AuthenticationException('unauthenticated');
        }

        $payloadScopes = [];
        if (isset($payload['scopes']) && is_array($payload['scopes'])) {
            foreach ($payload['scopes'] as $scope) {
                if (is_string($scope) && $scope !== '') {
                    $payloadScopes[] = $scope;
                }
            }
        }

        if ($requiredScopes !== []) {
            foreach ($requiredScopes as $requiredScope) {
                if (!in_array($requiredScope, $payloadScopes, true)) {
                    throw new AuthorizationException('forbidden');
                }
            }
        }

        $tokenIdentifier = $this->buildTokenRateLimitIdentifier($payload);
        if ($tokenIdentifier !== null) {
            $this->enforceRateLimit($tokenIdentifier);
        }

        if (!$this->auditRecorded) {
            $this->auditRecorded = true;
            $this->recordAuditEvent('api.request', [
                'token_subject' => isset($payload['sub']) && is_string($payload['sub']) ? $payload['sub'] : null,
                'scopes' => $payloadScopes,
                'method' => isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '',
                'path' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
            ]);
        }

        return $payload;
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

    /**
     * @param array<int, mixed> $parameters
     */
    protected function t(string $key, array $parameters = [], ?string $fallback = null): string
    {
        return $this->getTranslationService()->translate($key, $this->getCurrentLocale(), $parameters, $fallback);
    }

    private function getTranslationService(): TranslationService
    {
        if ($this->translationService === null) {
            $this->translationService = new TranslationService();
        }

        return $this->translationService;
    }

    private function getCurrentLocale(): string
    {
        $context = Context::getContext();
        if ($context->language instanceof Language) {
            $code = $context->language->iso_code;
            if (is_string($code) && $code !== '') {
                return $code;
            }
        }

        return 'en';
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

    protected function isDevMode(): bool
    {
        if (!defined('_PS_MODE_DEV_')) {
            return false;
        }

        return (bool) constant('_PS_MODE_DEV_');
    }

    protected function jsonError(string $error, string $message, int $statusCode): void
    {
        $this->renderJson([
            'error' => $error,
            'message' => $message,
        ], $statusCode);
    }

    private function enforceIpAllowlist(): void
    {
        $allowedRanges = $this->getSettingsService()->getAllowedIpRanges();
        if ($allowedRanges === []) {
            return;
        }

        $ip = $this->clientIp;
        if ($ip === null) {
            return;
        }

        foreach ($allowedRanges as $range) {
            if ($this->ipMatchesRange($ip, $range)) {
                return;
            }
        }

        $this->recordAuditEvent('security.ip_denied', ['ip' => $ip]);
        $this->logSecurityIncident('ip_denied', ['ip' => $ip]);
        $this->jsonError(
            'forbidden',
            $this->t('api.error.forbidden_ip', [], 'Access denied from your IP address.'),
            403
        );
        exit;
    }

    private function enforceRateLimit(?string $identifier = null): void
    {
        if (!$this->getSettingsService()->isRateLimitEnabled()) {
            return;
        }

        $identifier = $identifier ?? $this->getDefaultRateLimitIdentifier();
        if ($identifier === null) {
            return;
        }

        if (isset($this->rateLimitHits[$identifier])) {
            return;
        }

        $limit = $this->getSettingsService()->getRateLimit();
        if (!$this->getRateLimiterService()->isAllowed($identifier, $limit)) {
            $this->recordAuditEvent('security.rate_limited', [
                'identifier' => $identifier,
                'limit' => $limit,
            ]);
            $this->logSecurityIncident('rate_limited', ['identifier' => $identifier, 'limit' => $limit]);
            $this->jsonError(
                'too_many_requests',
                $this->t('api.error.rate_limited', [], 'Too many requests. Please try again later.'),
                429
            );
            exit;
        }

        $this->rateLimitHits[$identifier] = true;
    }

    private function getDefaultRateLimitIdentifier(): ?string
    {
        if ($this->clientIp === null) {
            return null;
        }

        return 'ip:' . $this->clientIp;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildTokenRateLimitIdentifier(array $payload): ?string
    {
        $identifier = '';

        if (isset($payload['jti']) && is_string($payload['jti']) && $payload['jti'] !== '') {
            $identifier = (string) $payload['jti'];
        } elseif (isset($payload['sub']) && is_string($payload['sub']) && $payload['sub'] !== '') {
            $identifier = (string) $payload['sub'];
        }

        if ($identifier === '') {
            return null;
        }

        $suffix = $this->clientIp !== null ? '@' . $this->clientIp : '';

        return 'token:' . $identifier . $suffix;
    }

    private function getRateLimiterService(): RateLimiterService
    {
        if ($this->rateLimiterService === null) {
            $this->rateLimiterService = new RateLimiterService();
        }

        return $this->rateLimiterService;
    }

    private function resolveClientIp(): ?string
    {
        $ip = Tools::getRemoteAddr();
        if ($ip === '') {
            return null;
        }

        return $ip;
    }

    private function ipMatchesRange(string $ip, string $range): bool
    {
        $ipBinary = @inet_pton($ip);
        if ($ipBinary === false) {
            return false;
        }

        if (strpos($range, '/') === false) {
            return $ip === $range;
        }

        [$subnet, $maskBitsRaw] = explode('/', $range, 2);
        $subnetBinary = @inet_pton($subnet);
        if ($subnetBinary === false || $maskBitsRaw === '') {
            return false;
        }

        $maskBits = (int) $maskBitsRaw;
        if ($maskBits < 0) {
            return false;
        }

        $length = strlen($ipBinary);
        if ($length !== strlen($subnetBinary)) {
            return false;
        }

        $fullBytes = intdiv($maskBits, 8);
        $remainingBits = $maskBits % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        if (!isset($ipBinary[$fullBytes], $subnetBinary[$fullBytes])) {
            return false;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;
        $ipByte = ord($ipBinary[$fullBytes]);
        $subnetByte = ord($subnetBinary[$fullBytes]);

        return ($ipByte & $mask) === ($subnetByte & $mask);
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function recordAuditEvent(string $event, array $context = []): void
    {
        $context['ip'] = $context['ip'] ?? $this->clientIp;
        $this->getAuditLogService()->record($event, $context);
    }

    protected function dispatchWebhookEvent(string $event, array $payload = []): void
    {
        $event = trim($event);
        if ($event === '') {
            return;
        }

        $this->getWebhookService()->dispatch($event, $payload);
    }

    private function getAuditLogService(): AuditLogService
    {
        if ($this->auditLogService === null) {
            $this->auditLogService = new AuditLogService();
        }

        return $this->auditLogService;
    }

    private function getWebhookService(): WebhookService
    {
        if ($this->webhookService === null) {
            $this->webhookService = new WebhookService($this->getSettingsService());
        }

        return $this->webhookService;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logSecurityIncident(string $type, array $context = []): void
    {
        if (!$this->isDevMode()) {
            return;
        }

        $message = '[RebuildConnector] Security ' . $type . ': ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        error_log($message);
    }
}
