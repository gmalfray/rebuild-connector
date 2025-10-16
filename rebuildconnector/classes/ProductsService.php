<?php

defined('_PS_VERSION_') || exit;

class ProductsService
{
    /** @var Link|null */
    private $link = null;

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getProducts(array $filters = []): array
    {
        $langId = $this->getLanguageId();
        $shopId = $this->getShopId();

        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : 20;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $query = new DbQuery();
        $query->select('p.id_product, pl.name, pl.link_rewrite, p.reference, p.active');
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

        $rows = (array) Db::getInstance()->executeS($query);

        $products = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $products[] = $this->formatProductRow($row, $langId, $shopId);
        }

        return $products;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProductById(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $langId = $this->getLanguageId();
        $shopId = $this->getShopId();

        $products = $this->getProducts([
            'ids' => [$productId],
            'limit' => 1,
            'offset' => 0,
        ]);

        if ($products === []) {
            return [];
        }

        $product = $products[0];
        $product['images'] = $this->getProductImages($productId, $langId, $shopId);

        if ($product['cover_image'] === null) {
            foreach ($product['images'] as $image) {
                if (!empty($image['is_cover'])) {
                    $product['cover_image'] = $image;
                    break;
                }
            }
        }

        return $product;
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
     * @param array<string, mixed> $payload
     */
    public function updateProduct(int $productId, array $payload): bool
    {
        $product = new Product($productId, true);
        if (!Validate::isLoadedObject($product)) {
            return false;
        }

        $updated = false;

        if (array_key_exists('active', $payload)) {
            $normalizedActive = $this->normalizeBoolean($payload['active']);
            if ($normalizedActive === null) {
                return false;
            }

            $product->active = $normalizedActive;
            $updated = true;
        }

        if (array_key_exists('price_tax_excl', $payload)) {
            $rawPrice = $payload['price_tax_excl'];
            if (!is_numeric($rawPrice)) {
                return false;
            }

            $product->price = (float) $rawPrice;
            $updated = true;
        }

        if (!$updated) {
            return false;
        }

        return (bool) $product->update();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatProductRow(array $row, int $langId, int $shopId): array
    {
        $idProduct = isset($row['id_product']) ? (int) $row['id_product'] : 0;
        $priceTaxExcl = isset($row['base_price']) ? (float) $row['base_price'] : 0.0;
        $priceTaxIncl = $idProduct > 0 ? (float) Product::getPriceStatic($idProduct, true) : $priceTaxExcl;
        $linkRewrite = isset($row['link_rewrite']) ? (string) $row['link_rewrite'] : '';

        $product = [
            'id' => $idProduct,
            'name' => isset($row['name']) ? (string) $row['name'] : '',
            'reference' => isset($row['reference']) ? (string) $row['reference'] : '',
            'active' => isset($row['active']) ? (bool) $row['active'] : false,
            'price_tax_excl' => $priceTaxExcl,
            'price_tax_incl' => $priceTaxIncl,
            'quantity' => isset($row['quantity']) ? (int) $row['quantity'] : 0,
            'cover_image' => null,
        ];

        $coverImage = $this->getCoverImageData($idProduct, $linkRewrite, $langId, $shopId);
        if ($coverImage !== null) {
            $product['cover_image'] = $coverImage;
        }

        return $product;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getCoverImageData(int $productId, string $linkRewrite, int $langId, int $shopId): ?array
    {
        if ($productId <= 0 || !class_exists('Image')) {
            return null;
        }

        $cover = Image::getCover($productId);
        if (!is_array($cover) || !isset($cover['id_image'])) {
            return null;
        }

        $imageId = (int) $cover['id_image'];
        if ($imageId <= 0) {
            return null;
        }

        if ($linkRewrite === '') {
            $linkRewrite = $this->resolveProductLinkRewrite($productId, $langId, $shopId);
        }

        $legend = isset($cover['legend']) && is_string($cover['legend']) ? $cover['legend'] : null;
        $position = isset($cover['position']) ? (int) $cover['position'] : null;

        return $this->formatImageData($productId, $imageId, $linkRewrite, true, $legend, $position);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getProductImages(int $productId, int $langId, int $shopId): array
    {
        if ($productId <= 0 || !class_exists('Image')) {
            return [];
        }

        $images = Image::getImages($langId, $productId);
        if (!is_array($images)) {
            return [];
        }

        $linkRewrite = $this->resolveProductLinkRewrite($productId, $langId, $shopId);
        $formatted = [];

        foreach ($images as $image) {
            if (!is_array($image) || !isset($image['id_image'])) {
                continue;
            }

            $imageId = (int) $image['id_image'];
            if ($imageId <= 0) {
                continue;
            }

            $isCover = false;
            if (isset($image['cover'])) {
                $normalizedCover = $this->normalizeBoolean($image['cover']);
                if ($normalizedCover !== null) {
                    $isCover = $normalizedCover;
                }
            }

            $legend = isset($image['legend']) && is_string($image['legend']) ? $image['legend'] : null;
            $position = isset($image['position']) ? (int) $image['position'] : null;

            $formatted[] = $this->formatImageData($productId, $imageId, $linkRewrite, $isCover, $legend, $position);
        }

        return $formatted;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatImageData(int $productId, int $imageId, string $linkRewrite, bool $isCover, ?string $legend, ?int $position): array
    {
        $urls = [
            'thumbnail' => $this->resolveImageUrl($linkRewrite, $productId, $imageId, 'home_default'),
            'large' => $this->resolveImageUrl($linkRewrite, $productId, $imageId, 'large_default'),
        ];

        $urls = array_filter($urls);
        $primaryUrl = $urls['large'] ?? (reset($urls) ?: null);

        return [
            'id' => $imageId,
            'is_cover' => $isCover,
            'legend' => $legend,
            'position' => $position,
            'url' => $primaryUrl !== false ? $primaryUrl : null,
            'urls' => $urls,
        ];
    }

    private function resolveImageUrl(string $linkRewrite, int $productId, int $imageId, string $type): ?string
    {
        if ($linkRewrite === '') {
            return null;
        }

        $link = $this->getLink();
        if ($link === null) {
            return null;
        }

        $reference = $productId . '-' . $imageId;

        try {
            return $link->getImageLink($linkRewrite, $reference, $type);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * @return Link|null
     */
    private function getLink()
    {
        if ($this->link instanceof Link) {
            return $this->link;
        }

        if (!class_exists('Link')) {
            return null;
        }

        $context = Context::getContext();
        if (isset($context->link) && $context->link instanceof Link) {
            $this->link = $context->link;
        } else {
            $this->link = new Link();
        }

        return $this->link;
    }

    private function resolveProductLinkRewrite(int $productId, int $langId, int $shopId): string
    {
        if (!class_exists('Product')) {
            return '';
        }

        try {
            $product = new Product($productId, false, $langId, $shopId);
        } catch (\Throwable $exception) {
            return '';
        }

        if (isset($product->link_rewrite)) {
            $linkRewrite = $product->link_rewrite;
            if (is_string($linkRewrite) && $linkRewrite !== '') {
                return $linkRewrite;
            }

            if (is_array($linkRewrite)) {
                $value = $linkRewrite[$langId] ?? reset($linkRewrite);
                if (is_string($value)) {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * @param mixed $value
     */
    private function normalizeBoolean($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            if ($value === 1) {
                return true;
            }
            if ($value === 0) {
                return false;
            }

            return null;
        }

        if (is_float($value)) {
            if ((int) $value === 1) {
                return true;
            }
            if ((int) $value === 0) {
                return false;
            }

            return null;
        }

        if (is_string($value)) {
            $normalized = Tools::strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return null;
    }

    private function getLanguageId(): int
    {
        $context = Context::getContext();
        if ($context->language instanceof Language) {
            return (int) $context->language->id;
        }

        return (int) Configuration::get('PS_LANG_DEFAULT');
    }

    private function getShopId(): int
    {
        $context = Context::getContext();
        if ($context->shop instanceof Shop) {
            return (int) $context->shop->id;
        }

        return (int) Configuration::get('PS_SHOP_DEFAULT');
    }
}
