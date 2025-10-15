<?php

defined('_PS_VERSION_') || exit;

class ProductsService
{
    public function getProducts(array $filters = []): array
    {
        // TODO: fetch product list from PrestaShop core.
        return [];
    }

    public function updateStock(int $productId, int $quantity): bool
    {
        // TODO: update product stock level.
        return false;
    }
}
