<?php

defined('_PS_VERSION_') || exit;

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/CustomersService.php';

class RebuildconnectorCustomersModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    private ?CustomersService $customersService = null;

    public function initContent(): void
    {
        parent::initContent();

        $method = Tools::strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            if ($method !== 'GET') {
                header('Allow: GET');
                $this->jsonError(
                    'method_not_allowed',
                    $this->t('api.error.method_not_allowed', [], 'HTTP method not allowed.'),
                    405
                );
                return;
            }

            $this->requireAuth(['customers.read']);
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
            $message = _PS_MODE_DEV_ ? $exception->getMessage() : $this->t('api.error.unexpected', [], 'Unexpected error during authentication.');
            $this->jsonError('server_error', $message, 500);
        }
    }

    private function handleGet(): void
    {
        $customerId = (int) Tools::getValue('id_customer', (int) Tools::getValue('id', 0));
        if ($customerId > 0) {
            $customer = $this->getCustomersService()->getCustomerById($customerId);
            if ($customer === []) {
                $this->jsonError(
                    'not_found',
                    $this->t('customers.error.not_found', [], 'Customer not found.'),
                    404
                );
                return;
            }

            $this->renderJson([
                'data' => $customer,
            ]);

            return;
        }

        $filters = [
            'limit' => Tools::getValue('limit'),
            'offset' => Tools::getValue('offset'),
            'search' => Tools::getValue('search'),
            'email' => Tools::getValue('email'),
        ];

        $idsParam = Tools::getValue('ids');
        if (is_string($idsParam) && $idsParam !== '') {
            $filters['ids'] = array_filter(array_map('intval', explode(',', $idsParam)));
        } elseif (is_array($idsParam)) {
            $filters['ids'] = array_filter(array_map('intval', $idsParam));
        }

        $customers = $this->getCustomersService()->getCustomers($filters);

        $this->renderJson([
            'data' => $customers,
        ]);
    }

    private function getCustomersService(): CustomersService
    {
        if ($this->customersService === null) {
            $this->customersService = new CustomersService();
        }

        return $this->customersService;
    }
}
