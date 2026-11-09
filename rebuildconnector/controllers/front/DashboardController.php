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
                // @ : header() peut émettre un warning "headers already sent" hors contexte HTTP réel
                // (ex. exécution CLI PHPUnit où du texte a déjà été écrit sur stdout) ; sans impact
                // en production (le header Allow est informatif sur une 405).
                @header('Allow: GET');
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
        if ($this->isFreeConversionRequest()) {
            $this->handleFreeConversion();
            return;
        }

        if (trim((string) Tools::getValue('action', '')) === 'free-conversion-cohorts') {
            $this->handleFreeConversionCohorts();
            return;
        }

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

    private function isFreeConversionRequest(): bool
    {
        return trim((string) Tools::getValue('action', '')) === 'free-conversion';
    }

    /**
     * GET dashboard/free-conversion[?from=YYYY-MM-DD&to=YYYY-MM-DD] (fenêtre sur la date de 1ère commande).
     */
    private function handleFreeConversion(): void
    {
        $fromParam = trim((string) Tools::getValue('from', ''));
        $toParam = trim((string) Tools::getValue('to', ''));
        $from = null;
        $to = null;

        foreach (['from' => $fromParam, 'to' => $toParam] as $name => $value) {
            if ($value === '') {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new \InvalidArgumentException(
                    $this->t('dashboard.error.invalid_date_format', [], 'Invalid date format. Use YYYY-MM-DD (e.g. 2025-06-01).')
                );
            }
            if ($name === 'from') {
                $from = $date->setTime(0, 0, 0);
            } else {
                $to = $date->setTime(23, 59, 59);
            }
        }

        if ($from !== null && $to !== null && $from > $to) {
            throw new \InvalidArgumentException(
                $this->t('dashboard.error.from_after_to', [], '"from" must be earlier than or equal to "to".')
            );
        }

        $this->renderJson($this->getDashboardService()->getFreeConversion($from, $to));
    }

    /**
     * GET dashboard/free-conversion-cohorts[?months=N | ?from=YYYY-MM-DD&to=YYYY-MM-DD].
     * Fenêtre sur le mois de la 1re commande offerte. Défaut : 12 derniers mois (mois courant inclus).
     */
    private function handleFreeConversionCohorts(): void
    {
        $monthsParam = trim((string) Tools::getValue('months', ''));
        $fromParam = trim((string) Tools::getValue('from', ''));
        $toParam = trim((string) Tools::getValue('to', ''));

        if ($fromParam !== '' || $toParam !== '') {
            if ($fromParam === '' || $toParam === '') {
                throw new \InvalidArgumentException(
                    $this->t('dashboard.error.from_to_required', [], 'Both "from" and "to" parameters are required when using date range mode.')
                );
            }
            $dates = [];
            foreach (['from' => $fromParam, 'to' => $toParam] as $name => $value) {
                $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
                if ($date === false || $date->format('Y-m-d') !== $value) {
                    throw new \InvalidArgumentException(
                        $this->t('dashboard.error.invalid_date_format', [], 'Invalid date format. Use YYYY-MM-DD (e.g. 2025-06-01).')
                    );
                }
                $dates[$name] = $date;
            }
            if ($dates['from'] > $dates['to']) {
                throw new \InvalidArgumentException(
                    $this->t('dashboard.error.from_after_to', [], '"from" must be earlier than or equal to "to".')
                );
            }
            if ((int) $dates['from']->diff($dates['to'])->days > 1100) {
                throw new \InvalidArgumentException(
                    $this->t('dashboard.error.range_too_large', [], 'Date range is too large.')
                );
            }
            $from = $dates['from']->setTime(0, 0, 0);
            $to = $dates['to']->setTime(23, 59, 59);
        } else {
            $months = 12;
            if ($monthsParam !== '') {
                if (!ctype_digit($monthsParam) || (int) $monthsParam < 1 || (int) $monthsParam > 36) {
                    throw new \InvalidArgumentException(
                        $this->t('dashboard.error.invalid_months', [], '"months" must be an integer between 1 and 36.')
                    );
                }
                $months = (int) $monthsParam;
            }
            $now = new \DateTimeImmutable('now');
            $to = $now;
            $from = $now->modify('first day of this month')->setTime(0, 0, 0)->modify('-' . ($months - 1) . ' months');
        }

        $this->renderJson($this->getDashboardService()->getFreeConversionCohorts($from, $to));
    }

    private function getDashboardService(): DashboardService
    {
        if ($this->dashboardService === null) {
            $this->dashboardService = new DashboardService();
        }

        return $this->dashboardService;
    }
}
