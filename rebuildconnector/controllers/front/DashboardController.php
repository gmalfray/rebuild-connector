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
        $fromParam = trim((string) Tools::getValue('from', ''));
        $toParam = trim((string) Tools::getValue('to', ''));

        // --- Mode plage libre (from + to fournis) ---
        if ($fromParam !== '' || $toParam !== '') {
            if ($fromParam === '' || $toParam === '') {
                throw new \InvalidArgumentException(
                    $this->t('dashboard.error.from_to_required', [], 'Both "from" and "to" parameters are required when using date range mode.')
                );
            }

            $fromDate = \DateTimeImmutable::createFromFormat('Y-m-d', $fromParam);
            $toDate = \DateTimeImmutable::createFromFormat('Y-m-d', $toParam);

            // Vérification stricte du format (createFromFormat accepte des débordements comme 2025-13-01)
            if (
                $fromDate === false || $fromDate->format('Y-m-d') !== $fromParam
                || $toDate === false || $toDate->format('Y-m-d') !== $toParam
            ) {
                throw new \InvalidArgumentException(
                    $this->t('dashboard.error.invalid_date_format', [], 'Invalid date format. Use YYYY-MM-DD (e.g. 2025-06-01).')
                );
            }

            // from doit être <= to
            if ($fromDate > $toDate) {
                throw new \InvalidArgumentException(
                    $this->t('dashboard.error.from_after_to', [], '"from" must be earlier than or equal to "to".')
                );
            }

            // Borne max : 2 ans (730 jours) pour éviter les requêtes trop lourdes
            $diffDays = (int) $fromDate->diff($toDate)->days;
            if ($diffDays > 730) {
                throw new \InvalidArgumentException(
                    $this->t('dashboard.error.range_too_large', [], 'Date range must not exceed 730 days (2 years).')
                );
            }

            // On fixe les heures : 00:00:00 pour from, 23:59:59 pour to (fuseau boutique géré côté service)
            $customFrom = $fromDate->setTime(0, 0, 0);
            $customTo = $toDate->setTime(23, 59, 59);

            $metrics = $this->getDashboardService()->getMetrics('custom', DashboardService::LOW_STOCK_THRESHOLD, $customFrom, $customTo);
            $this->renderJson($metrics);
            return;
        }

        // --- Mode preset (comportement historique inchangé) ---
        $period = Tools::strtolower((string) Tools::getValue('period', 'month'));
        $allowed = ['today', 'day', 'week', 'month', 'quarter', 'year'];
        if (!in_array($period, $allowed, true)) {
            throw new \InvalidArgumentException(
                $this->t('dashboard.error.invalid_period', [], 'Invalid period. Allowed: today, week, month, quarter, year.')
            );
        }

        $metrics = $this->getDashboardService()->getMetrics($period);

        $this->renderJson($metrics);
    }

    private function getDashboardService(): DashboardService
    {
        if ($this->dashboardService === null) {
            $this->dashboardService = new DashboardService();
        }

        return $this->dashboardService;
    }
}
