<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/FcmDeviceService.php';

/**
 * @param RebuildConnector $module
 * @return bool
 */
function upgrade_module_0_2_0($module): bool
{
    return FcmDeviceService::install();
}
