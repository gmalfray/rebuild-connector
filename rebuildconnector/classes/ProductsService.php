<?php

defined('_PS_VERSION_') || exit;

class ProductsService
{
    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getProducts(array $filters = []): array
    {
        $langId = $this->getLanguageId();
        $context = Context::getContext();
        $shopId = $context->shop instanceof Shop ? (int) $context->shop->id : (int) Configuration::get('PS_SHOP_DEFAULT');

        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : 20;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $query = new DbQuery();
        $query->select('p.id_product, pl.name, p.reference, p.active');
        $query->select('IFNULL(ps.price, p.price) AS base_price');
        $query->select('sa.quantity, sa.id_stock_available');
        $query->from('product', 'p');
        $query->innerJoin(
            'product_lang',
            'pl',
            'pl.id_product = p.id_product AND pl.id_lang = ' . (int) $langId . ' AND pl.id_shop = ' . (int) $shopId
        );
        $query->leftJoin(
            'product_shop',
            'ps',
            'ps.id_product = p.id_product AND ps.id_shop = ' . (int) $shopId
        );
        $query->leftJoin(
            'stock_available',
            'sa',
            'sa.id_product = p.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . (int) $shopId
        );

        if (isset($filters['active'])) {
            $query->where('p.active = ' . (int) (bool) $filters['active']);
        }

        if (!empty($filters['search'])) {
            $term = pSQL((string) $filters['search'], true);
            $like = '"%' . $term . '%"';
            $query->where('(pl.name LIKE ' . $like . ' OR p.reference LIKE ' . $like . ')');
        }

        if (!empty($filters['ids']) && is_array($filters['ids'])) {
            $ids = array_filter(array_map('intval', $filters['ids']));
            if (!empty($ids)) {
                $query->where('p.id_product IN (' . implode(',', $ids) . ')');
            }
        }

        $query->orderBy('pl.name ASC');
        $query->limit($limit, $offset);

        $rows = Db::getInstance()->executeS($query);
        if (!is_array($rows)) {
            return [];
        }

        $products = [];
        foreach ($rows as $row) {
            $products[] = $this->formatProductRow($row);
        }

        return $products;
    }

    public function updateStock(int $productId, int $quantity): bool
    {
        $product = new Product($productId);
        if (!Validate::isLoadedObject($product)) {
            return false;
        }

        StockAvailable::setQuantity($productId, 0, $quantity);

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatProductRow(array $row): array
    {
        $idProduct = isset($row['id_product']) ? (int) $row['id_product'] : 0;
        $priceTaxExcl = isset($row['base_price']) ? (float) $row['base_price'] : 0.0;
        $priceTaxIncl = $idProduct > 0 ? (float) Product::getPriceStatic($idProduct, true) : $priceTaxExcl;

        return [
            'id' => $idProduct,
            'name' => isset($row['name']) ? (string) $row['name'] : '',
            'reference' => isset($row['reference']) ? (string) $row['reference'] : '',
            'active' => isset($row['active']) ? (bool) $row['active'] : false,
            'price_tax_excl' => $priceTaxExcl,
            'price_tax_incl' => $priceTaxIncl,
            'quantity' => isset($row['quantity']) ? (int) $row['quantity'] : 0,
        ];
    }

    private function getLanguageId(): int
    {
        $context = Context::getContext();
        if ($context->language instanceof Language) {
            return (int) $context->language->id;
        }

        return (int) Configuration::get('PS_LANG_DEFAULT');
    }
}
