<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/BaseApiController.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/OrdersService.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/ShippingLabelService.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/FcmService.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/FcmDeviceService.php';

class RebuildconnectorOrdersModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    private ?OrdersService $ordersService = null;
    private ?ShippingLabelService $shippingLabelService = null;

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
        // Endpoint statuses : GET /orders/statuses (action injectée par la route)
        $action = Tools::strtolower((string) Tools::getValue('action', ''));
        if ($action === 'statuses') {
            $statuses = $this->getOrdersService()->getOrderStatuses();
            $this->renderJson([
                'statuses' => $statuses,
            ]);
            return;
        }

        $idRaw = Tools::getValue('id_order', Tools::getValue('id', false));
        $hasIdSegment = ($idRaw !== false && $idRaw !== '' && $idRaw !== null);
        $orderId = (int) $idRaw;
        // /orders/{id} avec un id non valide (ex. /orders/0) → 404, au lieu de retomber sur la liste.
        if ($hasIdSegment && $orderId <= 0) {
            $this->jsonError(
                'not_found',
                $this->t('orders.error.not_found', [], 'Order not found.'),
                404
            );
            return;
        }
        if ($orderId > 0) {
            $action = Tools::strtolower((string) Tools::getValue('action', ''));
            if ($action === 'invoice') {
                $pdf = $this->getOrdersService()->getInvoicePdf($orderId);
                if ($pdf === null) {
                    $this->jsonError(
                        'not_found',
                        $this->t('orders.error.invoice_not_found', [], 'No invoice available for this order.'),
                        404
                    );
                    return;
                }
                $this->renderPdf($pdf, 'facture-' . $orderId . '.pdf');
                return;
            }

            if ($action === 'shipping-label') {
                $result = $this->getShippingLabelService()->getShippingLabel($orderId);
                if ($result === null) {
                    $this->jsonError(
                        'not_found',
                        $this->t('orders.error.shipping_label_not_found', [], 'No shipping label available for this order.'),
                        404
                    );
                    return;
                }
                $this->renderPdf($result['pdf'], $result['filename']);
                return;
            }

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
                'order' => $order,
            ]);

            return;
        }

        $filters = [
            'limit' => $this->parseLimit(Tools::getValue('limit')),
            'offset' => $this->parseOffset(Tools::getValue('offset')),
            'customer_id' => Tools::getValue('customer_id'),
            'status' => Tools::getValue('status'),
            'date_from' => Tools::getValue('date_from'),
            'date_to' => Tools::getValue('date_to'),
            'search' => Tools::getValue('search'),
        ];

        $orders = $this->getOrdersService()->getOrders($filters);

        $this->renderJson([
            'orders' => $orders,
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
                // Statut inexistant → 400 (invalid_payload) plutôt qu'un 500 via OrderHistory.
                if (!$this->getOrdersService()->statusExists($status)) {
                    throw new \InvalidArgumentException($this->t('orders.error.unknown_status', [], 'Unknown order status.'));
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
                $this->notifyShippingUpdate($orderId, $trackingNumber, $carrierId);
                $this->renderJson([], 204);
                return;
            default:
                throw new \InvalidArgumentException($this->t('orders.error.invalid_action', [], 'Unsupported order action.'));
        }
    }

    private function notifyShippingUpdate(int $orderId, string $trackingNumber, ?int $carrierId): void
    {
        $settings = $this->getSettingsService();
        if (!$settings->isShippingNotificationEnabled()) {
            return;
        }

        // Ciblage par catégorie : seuls les appareils abonnés à "order.shipping.updated"
        // reçoivent cette notification. Les appareils avec topics vide (non configurés)
        // reçoivent aussi (rétrocompatibilité).
        $tokens = (new FcmDeviceService())->getTokensForCategory('order.shipping.updated');
        $fallbackTokens = $settings->getFcmDeviceTokens();

        if ($tokens === [] && $fallbackTokens === []) {
            return;
        }

        $notification = [
            'title' => $this->t('notifications.order_shipping_title'),
            'body' => $this->t('notifications.order_shipping_body', [$trackingNumber], sprintf('Tracking %s is now available.', $trackingNumber)),
        ];

        $data = [
            'event' => 'order.shipping.updated',
            'order_id' => (string) $orderId,
            'tracking_number' => $trackingNumber,
        ];

        if ($carrierId !== null) {
            $data['carrier_id'] = (string) $carrierId;
        }

        $success = (new FcmService($settings))->sendNotification($tokens, $notification, $data, [], $fallbackTokens);

        if (!$success && $this->isDevMode()) {
            error_log('[RebuildConnector] FCM shipping notification failed.');
        }
    }

    /**
     * @param mixed $value
     */
    private function parseLimit($value): int
    {
        if ($value === null || $value === '' || $value === false) {
            return OrdersService::DEFAULT_LIMIT;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException($this->t('orders.error.invalid_limit', [], 'Limit must be a positive integer.'));
        }

        $limit = (int) $value;
        if ($limit <= 0) {
            throw new \InvalidArgumentException($this->t('orders.error.invalid_limit', [], 'Limit must be a positive integer.'));
        }

        return min($limit, OrdersService::MAX_LIMIT);
    }

    /**
     * @param mixed $value
     */
    private function parseOffset($value): int
    {
        if ($value === null || $value === '' || $value === false) {
            return 0;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException($this->t('orders.error.invalid_offset', [], 'Offset must be a non-negative integer.'));
        }

        $offset = (int) $value;
        if ($offset < 0) {
            throw new \InvalidArgumentException($this->t('orders.error.invalid_offset', [], 'Offset must be a non-negative integer.'));
        }

        return $offset;
    }

    private function getOrdersService(): OrdersService
    {
        if ($this->ordersService === null) {
            $this->ordersService = new OrdersService();
        }

        return $this->ordersService;
    }

    private function getShippingLabelService(): ShippingLabelService
    {
        if ($this->shippingLabelService === null) {
            $this->shippingLabelService = new ShippingLabelService();
        }

        return $this->shippingLabelService;
    }
}
