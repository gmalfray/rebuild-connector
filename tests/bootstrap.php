<?php

declare(strict_types=1);

require_once __DIR__ . '/../phpstan-bootstrap.php';

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '1.7.0.0');
}

if (!defined('_PS_MODULE_DIR_')) {
    define('_PS_MODULE_DIR_', __DIR__ . '/../rebuildconnector/');
}

// Simple autoloader for module classes.
spl_autoload_register(static function (string $class): void {
    $map = [
        'SettingsService' => __DIR__ . '/../rebuildconnector/classes/SettingsService.php',
        'FcmService' => __DIR__ . '/../rebuildconnector/classes/FcmService.php',
        'FcmDeviceService' => __DIR__ . '/../rebuildconnector/classes/FcmDeviceService.php',
        'OrdersService' => __DIR__ . '/../rebuildconnector/classes/OrdersService.php',
        'CustomersService' => __DIR__ . '/../rebuildconnector/classes/CustomersService.php',
        'TranslationService' => __DIR__ . '/../rebuildconnector/classes/TranslationService.php',
        'JwtService' => __DIR__ . '/../rebuildconnector/classes/JwtService.php',
        'AuthService' => __DIR__ . '/../rebuildconnector/classes/AuthService.php',
        'DashboardService' => __DIR__ . '/../rebuildconnector/classes/DashboardService.php',
        'RateLimiterService' => __DIR__ . '/../rebuildconnector/classes/RateLimiterService.php',
        'AuditLogService' => __DIR__ . '/../rebuildconnector/classes/AuditLogService.php',
        'WebhookService' => __DIR__ . '/../rebuildconnector/classes/WebhookService.php',
        'BasketsService' => __DIR__ . '/../rebuildconnector/classes/BasketsService.php',
        'ReportsService' => __DIR__ . '/../rebuildconnector/classes/ReportsService.php',
        'ProductsService' => __DIR__ . '/../rebuildconnector/classes/ProductsService.php',
        'AuthenticationException' => __DIR__ . '/../rebuildconnector/classes/Exceptions/AuthenticationException.php',
        'AuthorizationException' => __DIR__ . '/../rebuildconnector/classes/Exceptions/AuthorizationException.php',
        'RebuildconnectorBaseApiModuleFrontController' => __DIR__ . '/../rebuildconnector/controllers/front/BaseApiController.php',
        'RebuildconnectorApiModuleFrontController' => __DIR__ . '/../rebuildconnector/controllers/front/ApiController.php',
        'RebuildconnectorCustomersModuleFrontController' => __DIR__ . '/../rebuildconnector/controllers/front/CustomersController.php',
        'RebuildconnectorOrdersModuleFrontController' => __DIR__ . '/../rebuildconnector/controllers/front/OrdersController.php',
        'RebuildconnectorProductsModuleFrontController' => __DIR__ . '/../rebuildconnector/controllers/front/ProductsController.php',
        'RebuildconnectorDashboardModuleFrontController' => __DIR__ . '/../rebuildconnector/controllers/front/DashboardController.php',
        'RebuildconnectorBasketsModuleFrontController' => __DIR__ . '/../rebuildconnector/controllers/front/BasketsController.php',
        'RebuildconnectorReportsModuleFrontController' => __DIR__ . '/../rebuildconnector/controllers/front/ReportsController.php',
    ];

    if (isset($map[$class]) && file_exists($map[$class])) {
        require_once $map[$class];
    }
});

require_once __DIR__ . '/../rebuildconnector/rebuildconnector.php';
