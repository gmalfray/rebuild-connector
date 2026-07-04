<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/BaseApiController.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/ReportsService.php';

class RebuildconnectorReportsModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    private ?ReportsService $reportsService = null;

    public function initContent(): void
    {
        parent::initContent();

        $method = Tools::strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
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

            $this->requireAuth(['reports.read']);
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
        $resource = Tools::strtolower((string) Tools::getValue('resource'));
        $filters = [
            // On valide/caste AVANT de passer à ReportsService::sanitizeLimit(?int) : Tools::getValue()
            // renvoie string|false et un `?limit=abc` provoquerait sinon un TypeError → 500. Ici une
            // valeur non numérique lève InvalidArgumentException → réponse HTTP 400 propre.
            'limit' => $this->parseLimit(Tools::getValue('limit')),
            'date_from' => Tools::getValue('date_from'),
            'date_to' => Tools::getValue('date_to'),
        ];

        switch ($resource) {
            case 'bestsellers':
            case 'best-sellers':
                $this->renderJson([
                    'products' => $this->getReportsService()->getBestSellers($filters),
                ]);
                return;
            case 'bestcustomers':
            case 'best-customers':
                $this->renderJson([
                    'customers' => $this->getReportsService()->getBestCustomers($filters),
                ]);
                return;
            default:
                throw new \InvalidArgumentException($this->t('reports.error.unknown_resource', [], 'Unknown report resource.'));
        }
    }

    /**
     * Normalise le paramètre `limit` reçu en query string.
     *
     * @param mixed $value valeur brute issue de Tools::getValue() (string|false)
     * @return int|null null si absent (ReportsService appliquera sa valeur par défaut)
     * @throws \InvalidArgumentException si la valeur est présente mais non numérique
     */
    private function parseLimit($value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException($this->t('reports.error.invalid_limit', [], 'Limit must be a positive integer.'));
        }

        return (int) $value;
    }

    private function getReportsService(): ReportsService
    {
        if ($this->reportsService === null) {
            $this->reportsService = new ReportsService();
        }

        return $this->reportsService;
    }
}
