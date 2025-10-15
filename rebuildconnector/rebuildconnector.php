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

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        return $this
            ->registerHook('actionValidateOrder')
            && $this->registerHook('actionOrderStatusPostUpdate');
    }

    public function uninstall()
    {
        if (!parent::uninstall()) {
            return false;
        }

        return true;
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitRebuildconnectorModule')) {
            // TODO: persist configuration settings.
            $output .= $this->displayConfirmation($this->l('Settings updated.'));
        }

        $this->context->smarty->assign([
            'module_dir' => $this->_path,
        ]);

        return $output . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    public function hookActionValidateOrder(array $params)
    {
        // TODO: trigger push notification for new order.
    }

    public function hookActionOrderStatusPostUpdate(array $params)
    {
        // TODO: notify status update to mobile app.
    }
}
