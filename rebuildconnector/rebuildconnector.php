<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/UserService.php';
require_once __DIR__ . '/classes/SettingsService.php';
require_once __DIR__ . '/classes/FcmDeviceService.php';
require_once __DIR__ . '/classes/FcmService.php';
require_once __DIR__ . '/classes/PushHubService.php';
require_once __DIR__ . '/classes/RateLimiterService.php';
require_once __DIR__ . '/classes/WebhookService.php';
require_once __DIR__ . '/classes/AuditLogService.php';
require_once __DIR__ . '/classes/Exceptions/AuthenticationException.php';
require_once __DIR__ . '/classes/JwtService.php';
require_once __DIR__ . '/classes/AuthService.php';
require_once __DIR__ . '/classes/TranslationService.php';
require_once __DIR__ . '/classes/UpdateCheckService.php';
require_once __DIR__ . '/classes/ModuleUpdaterService.php';

class RebuildConnector extends Module
{
    private ?FcmService $fcmService = null;
    private ?PushHubService $pushHubService = null;
    private ?SettingsService $settingsService = null;
    private ?TranslationService $translationService = null;
    private ?FcmDeviceService $fcmDeviceService = null;
    private ?WebhookService $webhookService = null;
    private ?AuditLogService $auditLogService = null;
    private ?UpdateCheckService $updateCheckService = null;
    private ?ModuleUpdaterService $moduleUpdaterService = null;
    private bool $settingsBootstrapped = false;

    public function __construct()
    {
        $this->name = 'rebuildconnector';
        $this->tab = 'administration';
        $this->version = '1.7.0';
        $this->author = 'Rebuild IT';
        $this->need_instance = 0;
        $this->bootstrap = true;

        $this->controllers = ['api', 'orders', 'products', 'customers', 'dashboard', 'notifications', 'baskets', 'reports', 'invoice'];

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

        if (!UserService::install()) {
            return false;
        }

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
            ->registerHook('moduleRoutes')
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionOrderStatusPostUpdate');
    }

    public function uninstall(): bool
    {
        if (!parent::uninstall()) {
            return false;
        }

        Configuration::deleteByName('REBUILDCONNECTOR_SETTINGS');
        UserService::uninstall();
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
        $newUserApiKey = null;
        $newUserQrJson = null;
        $regeneratedAdminApiKey = null;
        $regeneratedAdminQrJson = null;

        $userService = new UserService();

        // --- Actions utilisateurs nommés ---
        if (Tools::isSubmit('rebuildconnector_create_user')) {
            $idEmployee = (int) Tools::getValue('rebuildconnector_user_employee', 0);
            $label = trim((string) Tools::getValue('rebuildconnector_user_label', ''));
            $scopesRaw = Tools::getValue('rebuildconnector_user_scopes', []);
            $scopes = is_array($scopesRaw) ? array_map('strval', $scopesRaw) : [];

            if ($idEmployee <= 0) {
                $errors[] = 'Veuillez sélectionner un employé.';
            } elseif ($label === '') {
                $errors[] = 'Le label est obligatoire.';
            } else {
                $created = $userService->createUser($idEmployee, $label, $scopes);
                $newUserApiKey = $created['api_key'];
                $shopBaseUrl = $this->getShopBaseUrl();
                $apiEndpoints = $this->getApiEndpoints();
                $qrData = [
                    'module'       => $this->name,
                    'version'      => 1,
                    'shopUrl'      => $shopBaseUrl,
                    'apiKey'       => $newUserApiKey,
                    'api_base_url' => $apiEndpoints['pretty'],
                    'user_id'      => $created['id_user'],
                    'label'        => $label,
                ];
                $encoded = json_encode($qrData);
                $newUserQrJson = is_string($encoded) ? $encoded : '{}';
                $messages[] = 'Utilisateur « ' . htmlspecialchars($label, ENT_QUOTES) . ' » créé. Conservez la clé API — elle ne sera plus affichée.';
            }
        } elseif (Tools::isSubmit('rebuildconnector_update_scopes')) {
            $idUser = (int) Tools::getValue('rebuildconnector_user_id', 0);
            $scopesRaw = Tools::getValue('rebuildconnector_user_scopes', []);
            $scopes = is_array($scopesRaw) ? array_map('strval', $scopesRaw) : [];
            if ($idUser > 0) {
                $userService->updateScopes($idUser, $scopes);
                $messages[] = 'Scopes mis à jour.';
            }
        } elseif (Tools::isSubmit('rebuildconnector_regenerate_user_key')) {
            $idUser = (int) Tools::getValue('rebuildconnector_user_id', 0);
            if ($idUser > 0) {
                $newApiKey = $userService->regenerateApiKey($idUser);
                $user = $userService->getUserById($idUser);
                $shopBaseUrl = $this->getShopBaseUrl();
                $apiEndpoints = $this->getApiEndpoints();
                $label = is_array($user) && isset($user['label']) ? (string) $user['label'] : '';
                $qrData = [
                    'module'       => $this->name,
                    'version'      => 1,
                    'shopUrl'      => $shopBaseUrl,
                    'apiKey'       => $newApiKey,
                    'api_base_url' => $apiEndpoints['pretty'],
                    'user_id'      => $idUser,
                    'label'        => $label,
                ];
                $encoded = json_encode($qrData);
                $newUserApiKey = $newApiKey;
                $newUserQrJson = is_string($encoded) ? $encoded : '{}';
                $messages[] = 'Clé API régénérée pour « ' . htmlspecialchars($label, ENT_QUOTES) . ' ». L\'ancienne clé est immédiatement invalide.';
            }
        } elseif (Tools::isSubmit('rebuildconnector_regenerate_admin_key')) {
            $newAdminKey = $settingsService->regenerateApiKey();
            $shopBaseUrl = $this->getShopBaseUrl();
            $apiEndpoints = $this->getApiEndpoints();
            $adminQrData = [
                'module'       => $this->name,
                'version'      => 1,
                'shopUrl'      => $shopBaseUrl,
                'apiKey'       => $newAdminKey,
                'api_base_url' => $apiEndpoints['pretty'],
                'api_legacy_url' => $apiEndpoints['legacy'],
            ];
            $encoded = json_encode($adminQrData);
            $regeneratedAdminApiKey = $newAdminKey;
            $regeneratedAdminQrJson = is_string($encoded) ? $encoded : '{}';
            $messages[] = 'Clé API Admin régénérée. L\'ancienne clé est immédiatement invalide. Scannez le nouveau QR.';
        } elseif (Tools::isSubmit('rebuildconnector_toggle_user')) {
            $idUser = (int) Tools::getValue('rebuildconnector_user_id', 0);
            $active = Tools::getValue('rebuildconnector_user_active') === '1';
            if ($idUser > 0) {
                $userService->setActive($idUser, $active);
                $messages[] = 'Utilisateur ' . ($active ? 'réactivé' : 'révoqué') . '.';
            }
        } elseif (Tools::isSubmit('rebuildconnector_delete_user')) {
            $idUser = (int) Tools::getValue('rebuildconnector_user_id', 0);
            if ($idUser > 0) {
                $userService->setActive($idUser, false);
                $messages[] = 'Utilisateur révoqué.';
            }
        } elseif (Tools::isSubmit('rebuildconnector_regenerate_secret')) {
            $settingsService->regenerateJwtSecret();
            $messages[] = $this->t('admin.message.secret_regenerated');
        } elseif (Tools::isSubmit('rebuildconnector_hub_sync_devices')) {
            $hub = $this->getPushHubService();
            if (!$hub->isEnabled()) {
                $errors[] = $this->t('admin.error.hub_not_configured', [], 'Le hub push n\'est pas configuré (URL + clé de licence requis).');
            } else {
                $syncResult = $hub->syncAllDevices($this->getFcmDeviceService());
                $messages[] = $this->t(
                    'admin.message.hub_sync_done',
                    [$syncResult['synced'], $syncResult['failed'], $syncResult['skipped']],
                    sprintf(
                        'Synchronisation hub terminée : %d device(s) relayé(s), %d échec(s), %d ignoré(s).',
                        $syncResult['synced'],
                        $syncResult['failed'],
                        $syncResult['skipped']
                    )
                );
            }
        } elseif (Tools::isSubmit('rebuildconnector_check_update')) {
            // Vérification manuelle forcée : bypass du cache edge (?nocache=1) ET du cache local.
            $freshUpdate = $this->getUpdateCheckService()->getAvailableUpdateFresh();
            if ($freshUpdate !== null) {
                $messages[] = sprintf(
                    'Mise à jour disponible : Rebuild Connector v%s est prêt à installer.',
                    htmlspecialchars($freshUpdate['latest'], ENT_QUOTES)
                );
            } else {
                $messages[] = 'Vous êtes à jour — Rebuild Connector v' . $this->version . ' est la dernière version disponible.';
            }
        } elseif (Tools::isSubmit('rebuildconnector_do_update')) {
            // Mise à jour en un clic — l'URL de téléchargement est toujours issue du service,
            // jamais d'un paramètre POST (protection SSRF).
            $updater = $this->getModuleUpdaterService();
            $result = $updater->performUpdate();
            if ($result['success']) {
                $messages[] = $result['message'];
            } else {
                $errors[] = $result['message'];
            }
        } elseif (Tools::isSubmit('submitRebuildconnectorModule')) {
            // Les sections (FCM / Sécurité / Scopes) sont des formulaires DISTINCTS partageant ce
            // submit. On ne traite donc chaque champ QUE s'il est réellement présent dans la requête,
            // pour ne pas écraser/effacer les autres sections. La clé API n'est plus ici (gérée par
            // la carte Admin via régénération).
            if (Tools::getValue('REBUILDCONNECTOR_TOKEN_TTL') !== false) {
                $settingsService->setTokenTtl((int) Tools::getValue('REBUILDCONNECTOR_TOKEN_TTL', 3600));
            }

            if (Tools::getValue('REBUILDCONNECTOR_SCOPES') !== false) {
                $settingsService->setScopesFromString((string) Tools::getValue('REBUILDCONNECTOR_SCOPES', ''));
            }

            if (Tools::getValue('REBUILDCONNECTOR_FCM_SERVICE_ACCOUNT') !== false) {
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
            }

            if (Tools::getValue('REBUILDCONNECTOR_FCM_DEVICE_TOKENS') !== false) {
                $settingsService->setFcmDeviceTokens((string) Tools::getValue('REBUILDCONNECTOR_FCM_DEVICE_TOKENS', ''));
            }

            if (Tools::getValue('REBUILDCONNECTOR_FCM_TOPICS') !== false) {
                $settingsService->setFcmTopics((string) Tools::getValue('REBUILDCONNECTOR_FCM_TOPICS', ''));
            }

            if (Tools::getValue('REBUILDCONNECTOR_SHIPPING_NOTIFICATION') !== false) {
                $settingsService->setShippingNotificationEnabled(Tools::getValue('REBUILDCONNECTOR_SHIPPING_NOTIFICATION') === '1');
            }

            if (Tools::getValue('REBUILDCONNECTOR_HUB_URL') !== false) {
                try {
                    $settingsService->setHubUrl((string) Tools::getValue('REBUILDCONNECTOR_HUB_URL', ''));
                } catch (\InvalidArgumentException $exception) {
                    $errors[] = $this->t('admin.error.invalid_hub_url', [], 'L\'URL du hub doit utiliser HTTPS.');
                }
            }

            if (Tools::getValue('REBUILDCONNECTOR_HUB_LICENSE_KEY') !== false || Tools::getValue('REBUILDCONNECTOR_HUB_LICENSE_KEY_CLEAR') !== false) {
                if (Tools::getValue('REBUILDCONNECTOR_HUB_LICENSE_KEY_CLEAR') === '1') {
                    $settingsService->clearHubLicenseKey();
                } else {
                    $hubLicenseKey = trim((string) Tools::getValue('REBUILDCONNECTOR_HUB_LICENSE_KEY'));
                    if ($hubLicenseKey !== '') {
                        $settingsService->setHubLicenseKey($hubLicenseKey);
                    }
                }
            }

            if (Tools::getValue('REBUILDCONNECTOR_WEBHOOK_URL') !== false) {
                $webhookUrl = trim((string) Tools::getValue('REBUILDCONNECTOR_WEBHOOK_URL'));
                if ($webhookUrl !== '' && !Validate::isUrl($webhookUrl)) {
                    $errors[] = $this->t('admin.error.invalid_webhook_url');
                } else {
                    $settingsService->setWebhookUrl($webhookUrl);
                }
            }

            if (Tools::getValue('REBUILDCONNECTOR_WEBHOOK_SECRET') !== false || Tools::getValue('REBUILDCONNECTOR_WEBHOOK_SECRET_CLEAR') !== false) {
                $webhookSecret = trim((string) Tools::getValue('REBUILDCONNECTOR_WEBHOOK_SECRET'));
                if (Tools::getValue('REBUILDCONNECTOR_WEBHOOK_SECRET_CLEAR') === '1') {
                    $settingsService->clearWebhookSecret();
                } elseif ($webhookSecret !== '') {
                    $settingsService->setWebhookSecret($webhookSecret);
                }
            }

            if (Tools::getValue('REBUILDCONNECTOR_ALLOWED_IPS') !== false) {
                try {
                    $settingsService->setAllowedIpRanges((string) Tools::getValue('REBUILDCONNECTOR_ALLOWED_IPS', ''));
                } catch (\InvalidArgumentException $exception) {
                    $errors[] = $this->t('admin.error.invalid_ip_range');
                }
            }

            if (Tools::getValue('REBUILDCONNECTOR_RATE_LIMIT_ENABLED') !== false) {
                $settingsService->setRateLimitEnabled(Tools::getValue('REBUILDCONNECTOR_RATE_LIMIT_ENABLED') === '1');
            }

            if (Tools::getValue('REBUILDCONNECTOR_RATE_LIMIT') !== false) {
                $rateLimitRaw = Tools::getValue('REBUILDCONNECTOR_RATE_LIMIT', 60);
                if (!is_numeric($rateLimitRaw) || (int) $rateLimitRaw <= 0) {
                    $errors[] = $this->t('admin.error.invalid_rate_limit');
                } else {
                    $settingsService->setRateLimit((int) $rateLimitRaw);
                }
            }

            if (Tools::getValue('REBUILDCONNECTOR_ENV_OVERRIDES') !== false) {
                try {
                    $settingsService->setEnvOverrides(trim((string) Tools::getValue('REBUILDCONNECTOR_ENV_OVERRIDES')));
                } catch (\InvalidArgumentException $exception) {
                    $errors[] = $this->t('admin.error.invalid_env_overrides');
                }
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

        $settingsForTemplate = $settingsService->exportForTemplate();
        $apiEndpoints = $this->getApiEndpoints();
        $shopBaseUrl = $this->getShopBaseUrl();

        $settingsForTemplate['api_pretty_url'] = $apiEndpoints['pretty'];
        $settingsForTemplate['api_legacy_url'] = $apiEndpoints['legacy'];
        $settingsForTemplate['shop_url'] = $shopBaseUrl;

        // Données pour la section utilisateurs nommés
        $employees = [];
        if (class_exists('Employee')) {
            $rawEmployees = Employee::getEmployees();
            if (is_array($rawEmployees)) {
                $employees = $rawEmployees;
            }
        }

        $fcmProjectId = $settingsService->getFcmProjectId();
        $users = $userService->listUsers();

        // Pré-décoder les scopes de chaque utilisateur pour éviter json_decode dans Smarty
        foreach ($users as &$user) {
            $rawScopes = isset($user['scopes']) && is_string($user['scopes']) ? $user['scopes'] : '[]';
            $decoded = json_decode($rawScopes, true);
            $user['scopes_array'] = is_array($decoded) ? $decoded : [];
        }
        unset($user);

        $updateInfo = $this->getUpdateCheckService()->getAvailableUpdate();

        $this->context->smarty->assign([
            'module_dir'                 => $this->_path,
            'settings'                   => $settingsForTemplate,
            'i18n'                       => $this->getTranslationService()->getAdminFormStrings($this->getCurrentLocale()),
            'users'                      => $users,
            'users_count'                => count($users),
            'available_scopes'           => $userService->getAllScopes(),
            'role_presets'               => UserService::getRolePresets(),
            'employees'                  => $employees,
            'new_user_api_key'           => $newUserApiKey,
            'new_user_qr_json'           => $newUserQrJson,
            'regenerated_admin_api_key'  => $regeneratedAdminApiKey,
            'regenerated_admin_qr_json'  => $regeneratedAdminQrJson,
            'fcm_project_id'             => $fcmProjectId,
            'module_version'             => $this->version,
            'update_info'                => $updateInfo,
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
        $category = isset($data['event']) && is_string($data['event']) ? $data['event'] : '';
        $fallbackTokens = $this->getSettingsService()->getFcmDeviceTokens();

        // Mode hub centralisé : on relaie l'envoi au hub, qui détient le compte de service FCM
        // et cible ses propres devices. Le FCM direct ci-dessous sert de fallback si le hub est
        // injoignable (réseau / HTTP non 2xx), pour ne pas perdre la notification.
        $hub = $this->getPushHubService();
        if ($hub->isEnabled() && $hub->notify($category, $notification, $data)) {
            $this->recordAudit('notifications.dispatch', [
                'event' => $category !== '' ? $category : null,
                'success' => true,
                'transport' => 'hub',
                'category' => $category !== '' ? $category : null,
            ]);

            return;
        }

        // Ciblage par catégorie d'événement :
        // - Si la catégorie est connue, on cible les appareils abonnés à cette catégorie
        //   (appareils avec topics vide inclus, pour rétrocompatibilité).
        // - Si la catégorie est vide/inconnue, on cible tous les tokens (comportement legacy).
        if ($category !== '') {
            $primaryTokens = $this->getFcmDeviceService()->getTokensForCategory($category);
        } else {
            $topics = $this->getSettingsService()->getFcmTopics();
            $primaryTokens = $this->getFcmDeviceService()->getTokens($topics);
        }

        if ($primaryTokens === [] && $fallbackTokens === []) {
            return;
        }

        $success = $this
            ->getFcmService()
            ->sendNotification($primaryTokens, $notification, $data, [], $fallbackTokens);

        $this->recordAudit('notifications.dispatch', [
            'event' => $category !== '' ? $category : null,
            'success' => $success,
            'transport' => $hub->isEnabled() ? 'fcm_direct_fallback' : 'fcm_direct',
            'primary_tokens' => count($primaryTokens),
            'fallback_tokens' => count($fallbackTokens),
            'category' => $category !== '' ? $category : null,
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

    private function getPushHubService(): PushHubService
    {
        if ($this->pushHubService === null) {
            $this->pushHubService = new PushHubService($this->getSettingsService());
        }

        return $this->pushHubService;
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

    private function getUpdateCheckService(): UpdateCheckService
    {
        if ($this->updateCheckService === null) {
            $this->updateCheckService = new UpdateCheckService($this->version);
        }

        return $this->updateCheckService;
    }

    private function getModuleUpdaterService(): ModuleUpdaterService
    {
        if ($this->moduleUpdaterService === null) {
            $this->moduleUpdaterService = new ModuleUpdaterService($this->getUpdateCheckService());
        }

        return $this->moduleUpdaterService;
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

    /**
     * Déclare les routes "pretty URLs" utilisées par l'application PrestaFlow.
     *
     * @return array<string, array<string, mixed>>
     */
    public function hookModuleRoutes(): array
    {
        $module = $this->name;
        $baseRule = 'module/' . $module . '/api';

        return [
            'module-' . $module . '-api-root' => [
                'controller' => 'api',
                'rule' => $baseRule,
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                ],
            ],
            'module-' . $module . '-api-login' => [
                'controller' => 'api',
                'rule' => $baseRule . '/connector/login',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                ],
            ],
            'module-' . $module . '-api-orders' => [
                'controller' => 'orders',
                'rule' => $baseRule . '/orders',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                ],
            ],
            'module-' . $module . '-api-orders-statuses' => [
                'controller' => 'orders',
                'rule' => $baseRule . '/orders/statuses',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                    'action' => 'statuses',
                ],
            ],
            'module-' . $module . '-api-orders-id' => [
                'controller' => 'orders',
                'rule' => $baseRule . '/orders/{id}',
                'keywords' => [
                    'id' => [
                        'regexp' => '[0-9]+',
                        'param' => 'id',
                    ],
                ],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                ],
            ],
            'module-' . $module . '-api-orders-action' => [
                'controller' => 'orders',
                'rule' => $baseRule . '/orders/{id}/{action}',
                'keywords' => [
                    'id' => [
                        'regexp' => '[0-9]+',
                        'param' => 'id',
                    ],
                    'action' => [
                        'regexp' => '(status|shipping|invoice|shipping-label)',
                        'param' => 'action',
                    ],
                ],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                ],
            ],
            'module-' . $module . '-api-orders-invoice' => [
                'controller' => 'invoice',
                'rule' => $baseRule . '/orders/{id}/invoice',
                'keywords' => [
                    'id' => [
                        'regexp' => '[0-9]+',
                        'param' => 'id',
                    ],
                ],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                ],
            ],
            'module-' . $module . '-api-products' => [
                'controller' => 'products',
                'rule' => $baseRule . '/products',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                ],
            ],
            'module-' . $module . '-api-products-id' => [
                'controller' => 'products',
                'rule' => $baseRule . '/products/{id}',
                'keywords' => [
                    'id' => [
                        'regexp' => '[0-9]+',
                        'param' => 'id',
                    ],
                ],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                ],
            ],
            'module-' . $module . '-api-products-stock' => [
                'controller' => 'products',
                'rule' => $baseRule . '/products/{id}/stock',
                'keywords' => [
                    'id' => [
                        'regexp' => '[0-9]+',
                        'param' => 'id',
                    ],
                ],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                    'action' => 'stock',
                ],
            ],
            'module-' . $module . '-api-baskets' => [
                'controller' => 'baskets',
                'rule' => $baseRule . '/baskets',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                ],
            ],
            'module-' . $module . '-api-baskets-id' => [
                'controller' => 'baskets',
                'rule' => $baseRule . '/baskets/{id}',
                'keywords' => [
                    'id' => [
                        'regexp' => '[0-9]+',
                        'param' => 'id',
                    ],
                ],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                ],
            ],
            'module-' . $module . '-api-dashboard-metrics' => [
                'controller' => 'dashboard',
                'rule' => $baseRule . '/dashboard/metrics',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                ],
            ],
            'module-' . $module . '-api-customers' => [
                'controller' => 'customers',
                'rule' => $baseRule . '/customers',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                ],
            ],
            'module-' . $module . '-api-customers-stats' => [
                'controller' => 'customers',
                'rule' => $baseRule . '/customers/stats',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                    'action' => 'stats',
                ],
            ],
            'module-' . $module . '-api-customers-id' => [
                'controller' => 'customers',
                'rule' => $baseRule . '/customers/{id}',
                'keywords' => [
                    'id' => [
                        'regexp' => '[0-9]+',
                        'param' => 'id',
                    ],
                ],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                ],
            ],
            'module-' . $module . '-api-customers-top' => [
                'controller' => 'reports',
                'rule' => $baseRule . '/customers/top',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                    'resource' => 'bestcustomers',
                ],
            ],
            'module-' . $module . '-api-reports-bestsellers' => [
                'controller' => 'reports',
                'rule' => $baseRule . '/reports/bestsellers',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                    'resource' => 'bestsellers',
                ],
            ],
            'module-' . $module . '-api-reports-bestcustomers' => [
                'controller' => 'reports',
                'rule' => $baseRule . '/reports/bestcustomers',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                    'resource' => 'bestcustomers',
                ],
            ],
            'module-' . $module . '-api-notifications-devices' => [
                'controller' => 'notifications',
                'rule' => $baseRule . '/notifications/devices',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                ],
            ],
            'module-' . $module . '-api-notifications-devices-token' => [
                'controller' => 'notifications',
                'rule' => $baseRule . '/notifications/devices/{token}',
                'keywords' => [
                    'token' => [
                        'regexp' => '[A-Za-z0-9._:-]+',
                        'param' => 'token',
                    ],
                ],
                'params' => [
                    'fc' => 'module',
                    'module' => $module,
                ],
            ],
        ];
    }

    /**
     * @return array{pretty: string, legacy: string}
     */
    private function getApiEndpoints(): array
    {
        $link = $this->context !== null ? $this->context->link : null;

        if ($link === null) {
            return [
                'pretty' => '',
                'legacy' => '',
            ];
        }

        $legacyUrl = $link->getModuleLink($this->name, 'api', [], true);
        $baseLink = $link->getBaseLink(null, true);
        $baseLink = rtrim($baseLink, '/');
        $baseUri = trim(__PS_BASE_URI__, '/');

        if ($baseUri !== '') {
            $baseLink .= '/' . $baseUri;
        }

        $prettyUrl = $baseLink . '/module/' . $this->name . '/api';

        return [
            'pretty' => $prettyUrl,
            'legacy' => $legacyUrl,
        ];
    }

    private function getShopBaseUrl(): string
    {
        $baseDomain = Tools::getShopDomainSsl(true);
        $baseDomain = is_string($baseDomain) ? trim($baseDomain) : '';
        if ($baseDomain === '') {
            return '';
        }

        $baseDomain = preg_replace('#^http://#i', 'https://', $baseDomain);
        if (strpos($baseDomain, 'https://') !== 0) {
            $baseDomain = 'https://' . ltrim($baseDomain, '/');
        }

        $baseDomain = rtrim($baseDomain, '/');
        $baseUri = trim(__PS_BASE_URI__, '/');

        if ($baseUri !== '') {
            $baseDomain .= '/' . $baseUri;
        }

        return $baseDomain;
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
