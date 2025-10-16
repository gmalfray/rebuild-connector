<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/BaseApiController.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/DashboardService.php';

class RebuildconnectorDashboardModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    private ?DashboardService $dashboardService = null;

    public function initContent(): void
    {
        parent::initContent();

        try {
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($method !== 'GET') {
                header('Allow: GET');
                $this->jsonError(
                    'method_not_allowed',
                    $this->t('api.error.method_not_allowed', [], 'HTTP method not allowed.'),
                    405
                );
                return;
            }

            $this->requireAuth(['dashboard.read']);
            $this->handleGet();
        } catch (AuthenticationException $exception) {
            $this->jsonError(
                'unauthenticated',
                $this->t('api.error.unauthenticated', [], 'Authentication required.'),
                401
            );
        } catch (AuthorizationException $exception) {
            $this->jsonError(
                'forbidden',
                $this->t('api.error.forbidden', [], 'You do not have the required permissions.'),
                403
            );
        } catch (\InvalidArgumentException $exception) {
            $this->jsonError(
                'invalid_payload',
                $exception->getMessage(),
                400
            );
        } catch (\Throwable $exception) {
            $message = $this->isDevMode() ? $exception->getMessage() : $this->t('api.error.unexpected', [], 'Unexpected error occurred.');
            $this->jsonError('server_error', $message, 500);
        }
    }

    private function handleGet(): void
    {
        $period = (string) Tools::getValue('period', 'month');

        $metrics = $this->getDashboardService()->getMetrics($period);

        $this->renderJson([
            'data' => $metrics,
        ]);
    }

    private function getDashboardService(): DashboardService
    {
        if ($this->dashboardService === null) {
            $this->dashboardService = new DashboardService();
        }

        return $this->dashboardService;
    }
}
