<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class RebuildConnector extends Module
{
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

        return $this
            ->registerHook('actionValidateOrder')
            && $this->registerHook('actionOrderStatusPostUpdate');
    }

    public function uninstall(): bool
    {
        if (!parent::uninstall()) {
            return false;
        }

        return true;
    }

    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('submitRebuildconnectorModule')) {
            /** @var array<string, mixed> $settings */
            $settings = Tools::getAllValues();
            $ignoredKeys = [
                'submitRebuildconnectorModule',
                'controller',
                'configure',
                'module_name',
                'token',
                'ajax',
            ];

            foreach ($ignoredKeys as $ignoredKey) {
                if (isset($settings[$ignoredKey])) {
                    unset($settings[$ignoredKey]);
                }
            }

            $encodedSettings = json_encode($settings, JSON_UNESCAPED_UNICODE);
            Configuration::updateValue(
                'REBUILDCONNECTOR_SETTINGS',
                $encodedSettings !== false ? $encodedSettings : '{}'
            );
            $output .= $this->displayConfirmation($this->l('Settings updated.'));
        }

        $this->context->smarty->assign([
            'module_dir' => $this->_path,
        ]);

        return $output . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionValidateOrder(array $params): void
    {
        // TODO: trigger push notification for new order.
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionOrderStatusPostUpdate(array $params): void
    {
        // TODO: notify status update to mobile app.
    }
}
