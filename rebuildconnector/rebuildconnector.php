<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/SettingsService.php';
require_once __DIR__ . '/classes/FcmService.php';
require_once __DIR__ . '/classes/Exceptions/AuthenticationException.php';
require_once __DIR__ . '/classes/JwtService.php';
require_once __DIR__ . '/classes/AuthService.php';

class RebuildConnector extends Module
{
    private ?FcmService $fcmService = null;
    private ?SettingsService $settingsService = null;
    private bool $settingsBootstrapped = false;

    public function __construct()
    {
        $this->name = 'rebuildconnector';
        $this->tab = 'administration';
        $this->version = '0.1.0';
        $this->author = 'Rebuild IT';
        $this->need_instance = 0;
        $this->bootstrap = true;

        $this->controllers = ['api', 'orders', 'products', 'customers', 'dashboard'];

        parent::__construct();

        $this->displayName = $this->l('Rebuild Connector');
        $this->description = $this->l('API connector between PrestaShop and the PrestaFlow mobile application.');
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
    }

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        $this->getSettingsService()->ensureDefaults();

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
            $messages[] = $this->l('JWT secret regenerated. Update mobile clients to keep them in sync.');
        } elseif (Tools::isSubmit('submitRebuildconnectorModule')) {
            $apiKey = trim((string) Tools::getValue('REBUILDCONNECTOR_API_KEY'));
            if ($apiKey === '') {
                $errors[] = $this->l('API key cannot be empty.');
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
                    $errors[] = $this->l('The FCM service account must contain valid JSON.');
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

            if ($errors === []) {
                $messages[] = $this->l('Settings updated.');
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
            'title' => $this->l('New order received'),
            'body' => sprintf('#%s - %s', $reference, $this->formatOrderAmount($order)),
        ];

        $data = [
            'event' => 'order.created',
            'order_id' => (string) $orderId,
            'reference' => $reference,
        ];

        $this->notifyDevices($notification, $data);
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
            'title' => $this->l('Order status updated'),
            'body' => sprintf('#%s - %s', $reference, $statusName),
        ];

        $data = [
            'event' => 'order.status.changed',
            'order_id' => (string) $orderId,
            'reference' => $reference,
            'status' => $statusName,
        ];

        $this->notifyDevices($notification, $data);
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

        return $this->l('Status updated');
    }

    /**
     * @param array<string, string> $notification
     * @param array<string, mixed> $data
     */
    private function notifyDevices(array $notification, array $data): void
    {
        $tokens = $this->getDeviceTokens();
        if ($tokens === []) {
            return;
        }

        $success = $this->getFcmService()->sendNotification($tokens, $notification, $data);
        if (!$success && defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
            error_log('[RebuildConnector] FCM notification failed.');
        }
    }

    /**
     * @return array<int, string>
     */
    private function getDeviceTokens(): array
    {
        return $this->getSettingsService()->getFcmDeviceTokens();
    }

    private function getFcmService(): FcmService
    {
        if ($this->fcmService === null) {
            $this->fcmService = new FcmService($this->getSettingsService());
        }

        return $this->fcmService;
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
}
