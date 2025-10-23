<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param RebuildConnector $module
 */
function upgrade_module_1_1_2($module)
{
    return $module instanceof RebuildConnector;
}
