<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/SettingsService.php';
require_once __DIR__ . '/classes/FcmDeviceService.php';
require_once __DIR__ . '/classes/FcmService.php';
require_once __DIR__ . '/classes/RateLimiterService.php';
require_once __DIR__ . '/classes/WebhookService.php';
require_once __DIR__ . '/classes/AuditLogService.php';
require_once __DIR__ . '/classes/Exceptions/AuthenticationException.php';
require_once __DIR__ . '/classes/JwtService.php';
require_once __DIR__ . '/classes/AuthService.php';
require_once __DIR__ . '/classes/TranslationService.php';

class RebuildConnector extends Module
{
    private ?FcmService $fcmService = null;
    private ?SettingsService $settingsService = null;
    private ?TranslationService $translationService = null;
    private ?FcmDeviceService $fcmDeviceService = null;
    private ?WebhookService $webhookService = null;
    private ?AuditLogService $auditLogService = null;
    private bool $settingsBootstrapped = false;

    public function __construct()
    {
        $this->name = 'rebuildconnector';
        $this->tab = 'administration';
        $this->version = '0.2.0';
        $this->author = 'Rebuild IT';
        $this->need_instance = 0;
        $this->bootstrap = true;

        $this->controllers = ['api', 'orders', 'products', 'customers', 'dashboard', 'notifications', 'baskets'];

        parent::__construct();

        $this->displayName = $this->t('module.name', [], 'Rebuild Connector');
        $this->description = $this->t('module.description', [], 'API connector between PrestaShop and the PrestaFlow mobile application.');
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
    }

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        $this->getSettingsService()->ensureDefaults();

        if (!FcmDeviceService::install()) {
            return false;
        }

        if (!RateLimiterService::install()) {
            return false;
        }

        if (!AuditLogService::install()) {
            return false;
        }

        return $this
            ->registerHook('actionValidateOrder')
            && $this->registerHook('actionOrderStatusPostUpdate');
    }

    public function uninstall(): bool
    {
        if (!parent::uninstall()) {
            return false;
        }

        Configuration::deleteByName('REBUILDCONNECTOR_SETTINGS');
        FcmDeviceService::uninstall();
        RateLimiterService::uninstall();
        AuditLogService::uninstall();

        return true;
    }

    public function getContent(): string
    {
        $output = '';
        $settingsService = $this->getSettingsService();
        $messages = [];
        $errors = [];

        if (Tools::isSubmit('rebuildconnector_regenerate_secret')) {
            $settingsService->regenerateJwtSecret();
            $messages[] = $this->t('admin.message.secret_regenerated');
        } elseif (Tools::isSubmit('submitRebuildconnectorModule')) {
            $apiKey = trim((string) Tools::getValue('REBUILDCONNECTOR_API_KEY'));
            if ($apiKey === '') {
                $errors[] = $this->t('admin.error.api_key_empty');
            } else {
                $settingsService->setApiKey($apiKey);
            }

            $tokenTtl = (int) Tools::getValue('REBUILDCONNECTOR_TOKEN_TTL', 3600);
            $settingsService->setTokenTtl($tokenTtl);

            $scopes = (string) Tools::getValue('REBUILDCONNECTOR_SCOPES', '');
            $settingsService->setScopesFromString($scopes);

            $serviceAccount = trim((string) Tools::getValue('REBUILDCONNECTOR_FCM_SERVICE_ACCOUNT'));
            if ($serviceAccount !== '') {
                $decoded = json_decode($serviceAccount, true);
                if (!is_array($decoded)) {
                    $errors[] = $this->t('admin.error.invalid_service_account');
                } else {
                    $settingsService->setFcmServiceAccount(
                        json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    );
                }
            } else {
                $settingsService->setFcmServiceAccount(null);
            }

            $deviceTokens = (string) Tools::getValue('REBUILDCONNECTOR_FCM_DEVICE_TOKENS', '');
            $settingsService->setFcmDeviceTokens($deviceTokens);

            $topics = (string) Tools::getValue('REBUILDCONNECTOR_FCM_TOPICS', '');
            $settingsService->setFcmTopics($topics);

            $webhookUrl = trim((string) Tools::getValue('REBUILDCONNECTOR_WEBHOOK_URL'));
            if ($webhookUrl !== '' && !Validate::isUrl($webhookUrl)) {
                $errors[] = $this->t('admin.error.invalid_webhook_url');
            } else {
                $settingsService->setWebhookUrl($webhookUrl);
            }

            $webhookSecret = trim((string) Tools::getValue('REBUILDCONNECTOR_WEBHOOK_SECRET'));
            $webhookSecretClear = Tools::getValue('REBUILDCONNECTOR_WEBHOOK_SECRET_CLEAR') === '1';
            if ($webhookSecretClear) {
                $settingsService->clearWebhookSecret();
            } elseif ($webhookSecret !== '') {
                $settingsService->setWebhookSecret($webhookSecret);
            }

            $allowedIpRanges = (string) Tools::getValue('REBUILDCONNECTOR_ALLOWED_IPS', '');
            try {
                $settingsService->setAllowedIpRanges($allowedIpRanges);
            } catch (\InvalidArgumentException $exception) {
                $errors[] = $this->t('admin.error.invalid_ip_range');
            }

            $rateLimitEnabled = Tools::getValue('REBUILDCONNECTOR_RATE_LIMIT_ENABLED') === '1';
            $settingsService->setRateLimitEnabled($rateLimitEnabled);

            $rateLimitRaw = Tools::getValue('REBUILDCONNECTOR_RATE_LIMIT', 60);
            if (!is_numeric($rateLimitRaw) || (int) $rateLimitRaw <= 0) {
                $errors[] = $this->t('admin.error.invalid_rate_limit');
            } else {
                $settingsService->setRateLimit((int) $rateLimitRaw);
            }

            $envOverrides = trim((string) Tools::getValue('REBUILDCONNECTOR_ENV_OVERRIDES'));
            try {
                $settingsService->setEnvOverrides($envOverrides);
            } catch (\InvalidArgumentException $exception) {
                $errors[] = $this->t('admin.error.invalid_env_overrides');
            }

            if ($errors === []) {
                $messages[] = $this->t('admin.message.settings_updated');
        }
        }

        foreach ($errors as $error) {
            $output .= $this->displayError($error);
        }

        foreach ($messages as $message) {
            $output .= $this->displayConfirmation($message);
        }

        $this->context->smarty->assign([
            'module_dir' => $this->_path,
            'settings' => $settingsService->exportForTemplate(),
            'i18n' => $this->getTranslationService()->getAdminFormStrings($this->getCurrentLocale()),
        ]);

        return $output . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionValidateOrder(array $params): void
    {
        if (!isset($params['order']) || !is_object($params['order'])) {
            return;
        }

        $order = $params['order'];
        if (!isset($order->id)) {
            return;
        }

        $orderId = (int) $order->id;
        $reference = isset($order->reference) && is_string($order->reference)
            ? $order->reference
            : sprintf('%06d', $orderId);

        $notification = [
            'title' => $this->t('notifications.order_new_title'),
            'body' => $this->t('notifications.order_summary', [$reference, $this->formatOrderAmount($order)], sprintf('#%s - %s', $reference, $this->formatOrderAmount($order))),
        ];

        $data = [
            'event' => 'order.created',
            'order_id' => (string) $orderId,
            'reference' => $reference,
        ];

        $this->recordAudit('order.created', [
            'order_id' => $orderId,
            'reference' => $reference,
        ]);

        $this->notifyDevices($notification, $data);
        $this->dispatchWebhook('order.created', $data);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionOrderStatusPostUpdate(array $params): void
    {
        $orderId = isset($params['id_order']) ? (int) $params['id_order'] : 0;
        if (isset($params['order']) && is_object($params['order']) && isset($params['order']->id)) {
            $orderId = (int) $params['order']->id;
        }

        if ($orderId <= 0) {
            return;
        }

        $reference = $this->extractReference($params['order'] ?? null, $orderId);
        $statusName = $this->resolveOrderStatusName($params['newOrderStatus'] ?? null);

        $notification = [
            'title' => $this->t('notifications.order_status_title'),
            'body' => $this->t('notifications.order_summary', [$reference, $statusName], sprintf('#%s - %s', $reference, $statusName)),
        ];

        $data = [
            'event' => 'order.status.changed',
            'order_id' => (string) $orderId,
            'reference' => $reference,
            'status' => $statusName,
        ];

        $this->recordAudit('order.status.changed', [
            'order_id' => $orderId,
            'reference' => $reference,
            'status' => $statusName,
        ]);

        $this->notifyDevices($notification, $data);
        $this->dispatchWebhook('order.status.changed', $data);
    }

    /**
     * @param object|null $order
     */
    private function formatOrderAmount($order): string
    {
        $amount = 0.0;
        if (is_object($order) && isset($order->total_paid)) {
            $amount = (float) $order->total_paid;
        }

        if (class_exists('Tools')) {
            try {
                if (is_object($order) && isset($order->id_currency) && class_exists('Currency')) {
                    $currency = new Currency((int) $order->id_currency);
                    return Tools::displayPrice($amount, $currency);
                }

                return Tools::displayPrice($amount);
            } catch (\Exception $exception) {
                // Fallback below if PrestaShop helpers are unavailable.
            }
        }

        return number_format($amount, 2, ',', ' ');
    }

    /**
     * @param object|null $order
     */
    private function extractReference($order, int $fallbackId): string
    {
        if (is_object($order) && isset($order->reference) && is_string($order->reference)) {
            return $order->reference;
        }

        return sprintf('%06d', $fallbackId);
    }

    /**
     * @param mixed $status
     */
    private function resolveOrderStatusName($status): string
    {
        if (is_string($status) && $status !== '') {
            return $status;
        }

        if (is_object($status) && isset($status->name)) {
            $name = $status->name;
            if (is_string($name) && $name !== '') {
                return $name;
            }

            if (is_array($name)) {
                $first = reset($name);
                if (is_string($first) && $first !== '') {
                    return $first;
                }
            }
        }

        return $this->t('notifications.status_updated_generic');
    }

    /**
     * @param array<string, string> $notification
     * @param array<string, mixed> $data
     */
    private function notifyDevices(array $notification, array $data): void
    {
        $topics = $this->getSettingsService()->getFcmTopics();
        $primaryTokens = $this->getFcmDeviceService()->getTokens($topics);
        $fallbackTokens = $this->getSettingsService()->getFcmDeviceTokens();

        if ($topics === [] && $primaryTokens === [] && $fallbackTokens === []) {
            return;
        }

        $success = $this
            ->getFcmService()
            ->sendNotification($primaryTokens, $notification, $data, $topics, $fallbackTokens);

        $this->recordAudit('notifications.dispatch', [
            'event' => $data['event'] ?? null,
            'success' => $success,
            'primary_tokens' => count($primaryTokens),
            'fallback_tokens' => count($fallbackTokens),
            'topics' => $topics,
        ]);

        if (!$success && $this->isDevMode()) {
            error_log('[RebuildConnector] FCM notification failed (all channels).');
        }
    }

    private function getFcmService(): FcmService
    {
        if ($this->fcmService === null) {
            $this->fcmService = new FcmService($this->getSettingsService());
        }

        return $this->fcmService;
    }

    private function getWebhookService(): WebhookService
    {
        if ($this->webhookService === null) {
            $this->webhookService = new WebhookService($this->getSettingsService());
        }

        return $this->webhookService;
    }

    private function getAuditLogService(): AuditLogService
    {
        if ($this->auditLogService === null) {
            $this->auditLogService = new AuditLogService();
        }

        return $this->auditLogService;
    }

    private function getFcmDeviceService(): FcmDeviceService
    {
        if ($this->fcmDeviceService === null) {
            $this->fcmDeviceService = new FcmDeviceService();
        }

        return $this->fcmDeviceService;
    }

    private function getSettingsService(): SettingsService
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

    private function getTranslationService(): TranslationService
    {
        if ($this->translationService === null) {
            $this->translationService = new TranslationService();
        }

        return $this->translationService;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function dispatchWebhook(string $event, array $data): void
    {
        $this->getWebhookService()->dispatch($event, $data);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function recordAudit(string $event, array $context = []): void
    {
        $this->getAuditLogService()->record($event, array_merge($context, [
            'ip' => Tools::getRemoteAddr(),
        ]));
    }

    /**
     * @param array<int, mixed> $parameters
     */
    private function t(string $key, array $parameters = [], ?string $fallback = null): string
    {
        return $this->getTranslationService()->translate($key, $this->getCurrentLocale(), $parameters, $fallback);
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

    private function isDevMode(): bool
    {
        if (!defined('_PS_MODE_DEV_')) {
            return false;
        }

        return (bool) constant('_PS_MODE_DEV_');
    }
}
