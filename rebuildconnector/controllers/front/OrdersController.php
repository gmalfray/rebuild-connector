<?php

defined('_PS_VERSION_') || exit;

require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/OrdersService.php';

class RebuildconnectorOrdersModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    private ?OrdersService $ordersService = null;

    public function initContent(): void
    {
        parent::initContent();

        $method = Tools::strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            switch ($method) {
                case 'GET':
                    $this->requireAuth(['orders.read']);
                    $this->handleGet();
                    break;
                case 'PATCH':
                    $authPayload = $this->requireAuth(['orders.write']);
                    $this->handlePatch($authPayload);
                    break;
                default:
                    header('Allow: GET, PATCH');
                    $this->jsonError(
                        'method_not_allowed',
                        $this->t('api.error.method_not_allowed', [], 'HTTP method not allowed.'),
                        405
                    );
                    return;
            }
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
        $orderId = (int) Tools::getValue('id_order', (int) Tools::getValue('id', 0));
        if ($orderId > 0) {
            $order = $this->getOrdersService()->getOrderById($orderId);
            if ($order === []) {
                $this->jsonError(
                    'not_found',
                    $this->t('orders.error.not_found', [], 'Order not found.'),
                    404
                );
                return;
            }

            $this->renderJson([
                'data' => $order,
            ]);

            return;
        }

        $filters = [
            'limit' => Tools::getValue('limit'),
            'offset' => Tools::getValue('offset'),
            'customer_id' => Tools::getValue('customer_id'),
            'status' => Tools::getValue('status'),
            'date_from' => Tools::getValue('date_from'),
            'date_to' => Tools::getValue('date_to'),
            'search' => Tools::getValue('search'),
        ];

        $orders = $this->getOrdersService()->getOrders($filters);

        $this->renderJson([
            'data' => $orders,
        ]);
    }

    /**
     * @param array<string, mixed> $authPayload
     */
    private function handlePatch(array $authPayload = []): void
    {
        $orderId = (int) Tools::getValue('id_order', (int) Tools::getValue('id', 0));
        if ($orderId <= 0) {
            throw new \InvalidArgumentException($this->t('orders.error.not_found', [], 'Order not found.'));
        }

        $payload = $this->decodeRequestBody();
        $action = Tools::getValue('action');
        if ($action === null && isset($payload['action'])) {
            $action = (string) $payload['action'];
        }
        $action = Tools::strtolower((string) $action);
        if ($action === '') {
            if (isset($payload['status'])) {
                $action = 'status';
            } elseif (isset($payload['tracking_number'])) {
                $action = 'shipping';
            }
        }

        switch ($action) {
            case 'status':
                $status = isset($payload['status']) ? (string) $payload['status'] : '';
                if ($status === '') {
                    throw new \InvalidArgumentException($this->t('orders.error.invalid_status', [], 'A valid status is required.'));
                }
                if (!$this->getOrdersService()->updateStatus($orderId, $status)) {
                    $this->jsonError(
                        'not_found',
                        $this->t('orders.error.not_found', [], 'Order not found.'),
                        404
                    );
                    return;
                }
                $this->recordAuditEvent('orders.status.updated', [
                    'order_id' => $orderId,
                    'status' => $status,
                    'token_subject' => $authPayload['sub'] ?? null,
                ]);
                $this->dispatchWebhookEvent('order.status.updated', [
                    'order_id' => (string) $orderId,
                    'status' => $status,
                ]);
                $this->renderJson([], 204);
                return;
            case 'shipping':
                $trackingNumber = isset($payload['tracking_number']) ? trim((string) $payload['tracking_number']) : '';
                if ($trackingNumber === '') {
                    throw new \InvalidArgumentException($this->t('orders.error.invalid_shipping', [], 'A tracking number is required.'));
                }
                $carrierId = null;
                if (array_key_exists('carrier_id', $payload)) {
                    $rawCarrier = $payload['carrier_id'];
                    if ($rawCarrier === null || $rawCarrier === '') {
                        $carrierId = null;
                    } elseif (is_numeric($rawCarrier)) {
                        $carrierId = (int) $rawCarrier;
                        if ($carrierId <= 0) {
                            throw new \InvalidArgumentException($this->t('orders.error.invalid_carrier', [], 'A valid carrier_id is required when provided.'));
                        }
                    } else {
                        throw new \InvalidArgumentException($this->t('orders.error.invalid_carrier', [], 'A valid carrier_id is required when provided.'));
                    }
                }
                if (!$this->getOrdersService()->updateShipping($orderId, $trackingNumber, $carrierId)) {
                    $this->jsonError(
                        'not_found',
                        $this->t('orders.error.not_found', [], 'Order not found.'),
                        404
                    );
                    return;
                }
                $this->recordAuditEvent('orders.shipping.updated', [
                    'order_id' => $orderId,
                    'tracking_number' => $trackingNumber,
                    'carrier_id' => $carrierId,
                    'token_subject' => $authPayload['sub'] ?? null,
                ]);
                $webhookPayload = [
                    'order_id' => (string) $orderId,
                    'tracking_number' => $trackingNumber,
                ];
                if ($carrierId !== null) {
                    $webhookPayload['carrier_id'] = $carrierId;
                }
                $this->dispatchWebhookEvent('order.shipping.updated', $webhookPayload);
                $this->renderJson([], 204);
                return;
            default:
                throw new \InvalidArgumentException($this->t('orders.error.invalid_action', [], 'Unsupported order action.'));
        }
    }

    private function getOrdersService(): OrdersService
    {
        if ($this->ordersService === null) {
            $this->ordersService = new OrdersService();
        }

        return $this->ordersService;
    }
}
