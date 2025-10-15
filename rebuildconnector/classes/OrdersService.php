<?php

defined('_PS_VERSION_') || exit;

class OrdersService
{
    public function getOrders(array $filters = []): array
    {
        // TODO: fetch orders from PrestaShop core.
        return [];
    }

    public function getOrderById(int $orderId): array
    {
        // TODO: return order data structure.
        return [];
    }

    public function updateStatus(int $orderId, string $statusReference): bool
    {
        // TODO: apply new order status.
        return false;
    }

    public function updateShipping(int $orderId, string $trackingNumber): bool
    {
        // TODO: persist tracking number update.
        return false;
    }
}
