<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/BaseApiController.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/OrdersService.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/ShippingLabelService.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/ShippingLabelGenerator.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/FcmService.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/PushHubService.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/FcmDeviceService.php';

class RebuildconnectorOrdersModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    private ?OrdersService $ordersService = null;
    private ?ShippingLabelService $shippingLabelService = null;
    private ?ShippingLabelGenerator $shippingLabelGenerator = null;

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
                case 'POST':
                    $authPayload = $this->requireAuth(['orders.write']);
                    $this->handlePost($authPayload);
                    break;
                default:
                    header('Allow: GET, PATCH, POST');
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

    /**
     * @param array<string, mixed> $authPayload
     */
    private function handlePost(array $authPayload = []): void
    {
        $orderId = (int) Tools::getValue('id_order', (int) Tools::getValue('id', 0));
        if ($orderId <= 0) {
            throw new \InvalidArgumentException($this->t('orders.error.not_found', [], 'Order not found.'));
        }

        $action = Tools::strtolower((string) Tools::getValue('action', ''));

        if ($action === 'shipping-label') {
            $this->handleGenerateShippingLabel($orderId, $authPayload);
            return;
        }

        throw new \InvalidArgumentException($this->t('orders.error.invalid_action', [], 'Unsupported order action.'));
    }

    /**
     * POST /orders/{id}/shipping-label — Déclenche la génération d'une étiquette de transport.
     *
     * Idempotence : si une étiquette existe déjà pour cette commande, retourne 200 sans régénérer.
     * Si le transporteur est détecté mais la génération n'est pas encore configurée, retourne 501.
     *
     * @param array<string, mixed> $authPayload
     */
    private function handleGenerateShippingLabel(int $orderId, array $authPayload = []): void
    {
        $generator = $this->getShippingLabelGenerator();

        try {
            $result = $generator->generate($orderId);
        } catch (\InvalidArgumentException $e) {
            $this->jsonError(
                'not_found',
                $this->t('orders.error.not_found', [], 'Order not found.'),
                404
            );
            return;
        }

        switch ($result->getStatus()) {
            case ShippingLabelGenerationResult::STATUS_ALREADY_EXISTS:
                // Étiquette déjà disponible : répondre 200 sans générer
                $this->renderJson([
                    'generated'    => false,
                    'label_ready'  => true,
                    'carrier_type' => $result->getCarrierType() !== '' ? $result->getCarrierType() : null,
                ], 200);
                return;

            case ShippingLabelGenerationResult::STATUS_GENERATED:
                $this->recordAuditEvent('orders.shipping_label.generated', [
                    'order_id'      => $orderId,
                    'carrier_type'  => $result->getCarrierType(),
                    'token_subject' => $authPayload['sub'] ?? null,
                ]);
                $payload = [
                    'generated'    => true,
                    'label_ready'  => true,
                    'carrier_type' => $result->getCarrierType(),
                ];
                if ($result->getTrackingNumber() !== null) {
                    $payload['tracking_number'] = $result->getTrackingNumber();
                }
                $this->renderJson($payload, 201);
                return;

            case ShippingLabelGenerationResult::STATUS_NOT_CONFIGURED:
                // Transporteur détecté mais génération non encore implémentée
                $this->jsonError(
                    'generation_not_configured',
                    'Label generation is not yet configured for carrier: ' . $result->getCarrierType(),
                    501
                );
                return;

            case ShippingLabelGenerationResult::STATUS_UNSUPPORTED_CARRIER:
                $this->jsonError(
                    'carrier_not_supported',
                    $this->t('orders.error.carrier_not_supported', [], 'Carrier not supported for label generation.'),
                    422
                );
                return;

            case ShippingLabelGenerationResult::STATUS_WEBSERVICE_ERROR:
                $this->jsonError(
                    'carrier_webservice_error',
                    $result->getErrorDetail() ?? 'Carrier webservice error.',
                    502
                );
                return;

            default:
                $this->jsonError('server_error', 'Unexpected generation result.', 500);
        }
    }

    private function notifyShippingUpdate(int $orderId, string $trackingNumber, ?int $carrierId): void
    {
        $settings = $this->getSettingsService();
        if (!$settings->isShippingNotificationEnabled()) {
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

        // Mode hub centralisé : relai au hub (fallback FCM direct si le hub est injoignable).
        $hub = new PushHubService($settings);
        if ($hub->isEnabled() && $hub->notify('order.shipping.updated', $notification, $data)) {
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

    private function getShippingLabelGenerator(): ShippingLabelGenerator
    {
        if ($this->shippingLabelGenerator === null) {
            $this->shippingLabelGenerator = new ShippingLabelGenerator();
        }

        return $this->shippingLabelGenerator;
    }
}
