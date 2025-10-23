<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param RebuildConnector $module
 */
function upgrade_module_1_1_1($module)
{
    if (!$module instanceof RebuildConnector) {
        return false;
    }

    if (!$module->registerHook('moduleRoutes')) {
        return false;
    }

    return true;
}
