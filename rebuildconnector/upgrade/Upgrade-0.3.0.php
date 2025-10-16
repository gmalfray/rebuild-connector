<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/RateLimiterService.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/AuditLogService.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/SettingsService.php';

/**
 * @param RebuildConnector $module
 */
function upgrade_module_0_3_0($module): bool
{
    $rateLimitInstalled = RateLimiterService::install();
    $auditLogInstalled = AuditLogService::install();

    $settingsService = new SettingsService();
    $settingsService->ensureDefaults();

    $scopes = $settingsService->getScopes();
    $scopes[] = 'baskets.read';
    $scopes[] = 'reports.read';
    $settingsService->setScopes($scopes);

    $settings = $settingsService->all();
    if (!array_key_exists('shipping_notification_enabled', $settings)) {
        $settingsService->setShippingNotificationEnabled(false);
    }

    return $rateLimitInstalled && $auditLogInstalled;
}
