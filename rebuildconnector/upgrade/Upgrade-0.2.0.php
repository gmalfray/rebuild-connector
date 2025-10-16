<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/FcmDeviceService.php';

/**
 * @param RebuildConnector $module
 */
function upgrade_module_0_2_0($module)
{
    return FcmDeviceService::install();
}
