<?php

defined('_PS_VERSION_') || exit;

class ProductsService
{
    /**
     * Seuil de stock faible par défaut (utilisé si le produit n'a pas de low_stock_threshold défini).
     */
    public const DEFAULT_LOW_STOCK_THRESHOLD = 5;

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

        $defaultThreshold = self::DEFAULT_LOW_STOCK_THRESHOLD;

        $query = new DbQuery();
        $query->select('p.id_product, pl.name, pl.link_rewrite, p.reference, p.ean13, p.active, p.date_upd');
        $query->select('IFNULL(ps.price, p.price) AS base_price');
        $query->select('sa.quantity, sa.id_stock_available');
        $query->select(
            'CASE WHEN ps.low_stock_threshold IS NOT NULL AND ps.low_stock_threshold > 0'
            . ' THEN ps.low_stock_threshold'
            . ' ELSE ' . (int) $defaultThreshold
            . ' END AS low_stock_threshold'
        );
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

        // N'applique le filtre active que si la clé est présente ET la valeur n'est ni false ni null.
        // isset() retourne true même pour false, ce qui causerait un filtre p.active=0 non désiré.
        if (array_key_exists('active', $filters) && $filters['active'] !== false && $filters['active'] !== null) {
            $query->where('p.active = ' . (int) (bool) $filters['active']);
        }

        if (!empty($filters['search'])) {
            $term = pSQL((string) $filters['search'], true);
            $like = '"%' . $term . '%"';
            $query->where('(pl.name LIKE ' . $like . ' OR p.reference LIKE ' . $like . ')');
        }

        if (!empty($filters['barcode'])) {
            // Correspondance EXACTE (scan EAN13/référence) : distinct du filtre "search" (LIKE partiel).
            // Combinaison-aware (v1.10.5) : matche aussi product_attribute.ean13/.reference (ex. pelotes
            // de laine dont le code-barres est posé sur la déclinaison "Coloris", pas sur le produit).
            $code = pSQL((string) $filters['barcode']);
            $this->joinProductAttributeForBarcode($query, $code, $shopId);
            $query->select(
                'pa.id_product_attribute AS matched_id_product_attribute,'
                . ' pa.ean13 AS matched_pa_ean13, pa.reference AS matched_pa_reference'
            );
            $query->where(
                '(p.ean13 = "' . $code . '" OR p.reference = "' . $code . '"'
                . ' OR pas.id_product_attribute IS NOT NULL)'
            );
        }

        if (!empty($filters['ids']) && is_array($filters['ids'])) {
            $ids = array_filter(array_map('intval', $filters['ids']));
            if (!empty($ids)) {
                $query->where('p.id_product IN (' . implode(',', $ids) . ')');
            }
        }

        if (!empty($filters['stock'])) {
            $stockFilter = (string) $filters['stock'];
            if ($stockFilter === 'in_stock') {
                $query->where('IFNULL(sa.quantity, 0) > 0');
            } elseif ($stockFilter === 'out_of_stock') {
                $query->where('IFNULL(sa.quantity, 0) <= 0');
            } elseif ($stockFilter === 'low_stock') {
                // 0 < quantity <= seuil (threshold effectif)
                $query->where('IFNULL(sa.quantity, 0) > 0');
                $query->where(
                    'IFNULL(sa.quantity, 0) <= CASE'
                    . ' WHEN ps.low_stock_threshold IS NOT NULL AND ps.low_stock_threshold > 0'
                    . ' THEN ps.low_stock_threshold'
                    . ' ELSE ' . (int) $defaultThreshold
                    . ' END'
                );
            }
        }

        $query->orderBy('pl.name ASC');
        $query->limit($limit, $offset);

        $rows = (array) Db::getInstance()->executeS($query);

        // Le champ `combinations` (liste complète des déclinaisons) n'est exposé que sur les résultats
        // d'une recherche "barcode" (scan) : c'est le seul cas où l'app en a besoin (faire choisir la
        // bonne déclinaison), et ça évite d'alourdir la liste paginée générale.
        $includeCombinations = !empty($filters['barcode']);
        $barcodeCode = $includeCombinations ? trim((string) $filters['barcode']) : null;

        $products = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $products[] = $this->formatProductRow($row, $langId, $shopId, $includeCombinations, $barcodeCode);
        }

        return $products;
    }

    /**
     * Retourne le nombre total de produits correspondant aux filtres fournis,
     * sans tenir compte de la pagination (limit/offset ignorés).
     *
     * @param array<string, mixed> $filters
     */
    public function countProducts(array $filters = []): int
    {
        $langId = $this->getLanguageId();
        $shopId = $this->getShopId();

        $query = new DbQuery();
        $query->select('COUNT(DISTINCT p.id_product)');
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

        if (array_key_exists('active', $filters) && $filters['active'] !== false && $filters['active'] !== null) {
            $query->where('p.active = ' . (int) (bool) $filters['active']);
        }

        if (!empty($filters['search'])) {
            $term = pSQL((string) $filters['search'], true);
            $like = '"%' . $term . '%"';
            $query->where('(pl.name LIKE ' . $like . ' OR p.reference LIKE ' . $like . ')');
        }

        if (!empty($filters['barcode'])) {
            // Cf. getProducts() : même jointure combinaison-aware pour un total cohérent avec la liste.
            $code = pSQL((string) $filters['barcode']);
            $this->joinProductAttributeForBarcode($query, $code, $shopId);
            $query->where(
                '(p.ean13 = "' . $code . '" OR p.reference = "' . $code . '"'
                . ' OR pas.id_product_attribute IS NOT NULL)'
            );
        }

        if (!empty($filters['ids']) && is_array($filters['ids'])) {
            $ids = array_filter(array_map('intval', $filters['ids']));
            if (!empty($ids)) {
                $query->where('p.id_product IN (' . implode(',', $ids) . ')');
            }
        }

        if (!empty($filters['stock'])) {
            $defaultThreshold = self::DEFAULT_LOW_STOCK_THRESHOLD;
            $stockFilter = (string) $filters['stock'];
            if ($stockFilter === 'in_stock') {
                $query->where('IFNULL(sa.quantity, 0) > 0');
            } elseif ($stockFilter === 'out_of_stock') {
                $query->where('IFNULL(sa.quantity, 0) <= 0');
            } elseif ($stockFilter === 'low_stock') {
                $query->where('IFNULL(sa.quantity, 0) > 0');
                $query->where(
                    'IFNULL(sa.quantity, 0) <= CASE'
                    . ' WHEN ps.low_stock_threshold IS NOT NULL AND ps.low_stock_threshold > 0'
                    . ' THEN ps.low_stock_threshold'
                    . ' ELSE ' . (int) $defaultThreshold
                    . ' END'
                );
            }
        }

        return (int) Db::getInstance()->getValue($query);
    }

    /**
     * Ajoute les LEFT JOIN nécessaires pour matcher le filtre "barcode" sur une COMBINAISON
     * (product_attribute.ean13/.reference) en plus du produit. La jointure `pa` ne cible QUE les
     * lignes dont le code correspond déjà (ean13/reference étant en pratique uniques), donc elle ne
     * duplique pas les lignes produit ; `pas` restreint le match à la boutique courante.
     */
    private function joinProductAttributeForBarcode(DbQuery $query, string $code, int $shopId): void
    {
        $query->leftJoin(
            'product_attribute',
            'pa',
            'pa.id_product = p.id_product AND (pa.ean13 = "' . $code . '" OR pa.reference = "' . $code . '")'
        );
        $query->leftJoin(
            'product_attribute_shop',
            'pas',
            'pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = ' . (int) $shopId
        );
    }

    /**
     * Libellé de combinaison façon core PrestaShop, ex. "Coloris - Bleu" (ou "Coloris - Bleu, Taille - M"
     * si plusieurs groupes d'attributs). Construit à partir de product_attribute_combination → attribute
     * → attribute_lang / attribute_group_lang, dans la langue courante.
     */
    private function getCombinationLabel(int $idProductAttribute, int $langId): string
    {
        if ($idProductAttribute <= 0) {
            return '';
        }

        $query = new DbQuery();
        $query->select('agl.name AS group_name, al.name AS attribute_name');
        $query->from('product_attribute_combination', 'pac');
        $query->innerJoin('attribute', 'a', 'a.id_attribute = pac.id_attribute');
        $query->innerJoin(
            'attribute_lang',
            'al',
            'al.id_attribute = a.id_attribute AND al.id_lang = ' . (int) $langId
        );
        $query->innerJoin(
            'attribute_group_lang',
            'agl',
            'agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = ' . (int) $langId
        );
        $query->where('pac.id_product_attribute = ' . (int) $idProductAttribute);
        $query->orderBy('a.position ASC');

        /** @var array<int, array<string, mixed>> $rows */
        $rows = (array) Db::getInstance()->executeS($query);

        $parts = [];
        foreach ($rows as $row) {
            $groupName = isset($row['group_name']) ? (string) $row['group_name'] : '';
            $attributeName = isset($row['attribute_name']) ? (string) $row['attribute_name'] : '';
            if ($groupName === '' || $attributeName === '') {
                continue;
            }
            $parts[] = $groupName . ' - ' . $attributeName;
        }

        return implode(', ', $parts);
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
        $images = $this->getProductImages($productId, $langId, $shopId);
        $product['images'] = array_values(array_filter(array_map(static function (array $image): array {
            return [
                'id' => $image['id'],
                'url' => $image['url'],
            ];
        }, $images), static function (array $image): bool {
            return isset($image['url']) && is_string($image['url']) && $image['url'] !== '';
        }));

        // Descriptions (potentiellement volumineuses en HTML) exposées uniquement sur le DÉTAIL,
        // pas dans la liste, pour ne pas gonfler la réponse paginée. Nécessaires au préremplissage
        // de l'écran d'édition de fiche produit côté app.
        if (class_exists('Product')) {
            $productObject = new Product($productId, false, $langId);
            if (Validate::isLoadedObject($productObject)) {
                $product['description'] = (string) $productObject->description;
                $product['description_short'] = (string) $productObject->description_short;
            }
        }

        return $product;
    }

    /**
     * @param int $combinationId id_product_attribute ciblé (0 ou absent = niveau produit, comportement
     *                           historique). Doit appartenir au produit sinon la mise à jour est rejetée.
     */
    public function updateStock(int $productId, int $quantity, int $combinationId = 0): bool
    {
        $product = new Product($productId);
        if (!Validate::isLoadedObject($product)) {
            return false;
        }

        if ($combinationId > 0 && !$this->combinationBelongsToProduct($combinationId, $productId)) {
            return false;
        }

        StockAvailable::setQuantity($productId, $combinationId > 0 ? $combinationId : 0, $quantity);

        return true;
    }

    /**
     * Vérifie qu'une combinaison (id_product_attribute) appartient bien au produit visé, pour éviter
     * qu'un combination_id "étranger" (issu d'un autre produit) n'écrase le stock d'une déclinaison qui
     * n'a rien à voir avec l'appel PATCH en cours.
     */
    private function combinationBelongsToProduct(int $combinationId, int $productId): bool
    {
        $query = new DbQuery();
        $query->select('pa.id_product_attribute');
        $query->from('product_attribute', 'pa');
        $query->where('pa.id_product_attribute = ' . (int) $combinationId);
        $query->where('pa.id_product = ' . (int) $productId);

        return (int) Db::getInstance()->getValue($query) > 0;
    }

    /**
     * Écrit l'EAN13 sur une déclinaison (product_attribute) plutôt que sur le produit. Appelant
     * responsable d'avoir déjà vérifié combinationBelongsToProduct() en amont.
     */
    private function updateCombinationEan13(int $combinationId, string $ean13): bool
    {
        if ($combinationId <= 0 || !class_exists('Combination')) {
            return false;
        }

        $combination = new Combination($combinationId);
        if (!Validate::isLoadedObject($combination)) {
            return false;
        }

        $combination->ean13 = pSQL($ean13);

        return (bool) $combination->update();
    }

    /**
     * Ajoute une image à un produit à partir d'une entrée $_FILES déjà validée (type/taille) par le
     * controller, puis renvoie la fiche produit à jour (même format que getProductById).
     * Suit le flux standard du core PrestaShop (cf. AdminProductsController::ajaxProcessAddProductImage /
     * classes/Image.php) : Image::add() (position + cover auto), copie/redimensionnement du fichier
     * source via ImageManager::resize() vers le chemin généré par Image::getPathForCreation(), puis une
     * déclinaison par ImageType actif pour les produits.
     *
     * @param array<string, mixed> $file entrée $_FILES['image']
     * @return array<string, mixed> fiche produit à jour, ou [] si le produit n'existe pas / échec technique
     */
    public function addProductImage(int $productId, array $file): array
    {
        if ($productId <= 0 || !class_exists('Image') || !class_exists('ImageManager') || !class_exists('ImageType')) {
            return [];
        }

        $product = new Product($productId);
        if (!Validate::isLoadedObject($product)) {
            return [];
        }

        $tmpName = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($tmpName === '' || !is_file($tmpName)) {
            return [];
        }

        $image = new Image();
        $image->id_product = $productId;
        $image->position = (int) Image::getHighestPosition($productId) + 1;
        // Première image du produit (pas encore de cover) : elle devient automatiquement la couverture.
        $image->cover = Image::getCover($productId) === false;

        if (!$image->add()) {
            return [];
        }

        $newPath = $image->getPathForCreation();
        if (!is_string($newPath) || $newPath === '') {
            $image->delete();

            return [];
        }

        $extension = '.' . $image->image_format;

        if (!ImageManager::resize($tmpName, $newPath . $extension)) {
            $image->delete();

            return [];
        }

        /** @var array<int, array<string, mixed>> $imageTypes */
        $imageTypes = ImageType::getImagesTypes('products');
        foreach ($imageTypes as $imageType) {
            $typeName = isset($imageType['name']) ? (string) $imageType['name'] : '';
            if ($typeName === '') {
                continue;
            }

            $width = isset($imageType['width']) ? (int) $imageType['width'] : 0;
            $height = isset($imageType['height']) ? (int) $imageType['height'] : 0;

            ImageManager::resize($tmpName, $newPath . '-' . stripslashes($typeName) . $extension, $width, $height);
        }

        return $this->getProductById($productId);
    }

    /**
     * Supprime une image de produit (fichiers + déclinaisons nettoyés par Image::delete()) après avoir
     * vérifié qu'elle appartient bien au produit demandé. Si l'image supprimée était la couverture,
     * promeut la première image restante en couverture (même logique que
     * AdminProductsController::ajaxProcessDeleteProductImage du core).
     */
    public function deleteProductImage(int $productId, int $imageId): bool
    {
        if ($productId <= 0 || $imageId <= 0 || !class_exists('Image')) {
            return false;
        }

        $image = new Image($imageId);
        if (!Validate::isLoadedObject($image) || (int) $image->id_product !== $productId) {
            return false;
        }

        $wasCover = (bool) $image->cover;

        if (!$image->delete()) {
            return false;
        }

        if ($wasCover) {
            $this->promoteFirstRemainingImageToCover($productId);
        }

        return true;
    }

    /**
     * Réassigne la couverture à la première image restante d'un produit qui vient d'en perdre une
     * (niveau boutique puis niveau global), à l'identique du core.
     */
    private function promoteFirstRemainingImageToCover(int $productId): void
    {
        if (!class_exists('Image')) {
            return;
        }

        if (Image::getCover($productId) === false) {
            Db::getInstance()->execute(
                'UPDATE `' . _DB_PREFIX_ . 'image_shop` image_shop SET image_shop.`cover` = 1'
                . ' WHERE image_shop.`id_product` = ' . (int) $productId
                . ' AND image_shop.`id_shop` = ' . (int) $this->getShopId()
                . ' LIMIT 1'
            );
        }

        if (Image::getGlobalCover($productId) === false) {
            Db::getInstance()->execute(
                'UPDATE `' . _DB_PREFIX_ . 'image` i SET i.`cover` = 1'
                . ' WHERE i.`id_product` = ' . (int) $productId
                . ' LIMIT 1'
            );
        }
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

        // combination_id (v1.10.7) : cible la déclinaison sur laquelle écrire l'ean13, plutôt que le
        // produit. Validé ici (appartenance au produit) même si aucun autre champ n'est fourni, pour que
        // le controller renvoie systématiquement 400 sur un id étranger plutôt que d'ignorer l'erreur.
        $combinationId = 0;
        if (array_key_exists('combination_id', $payload) && $payload['combination_id'] !== null) {
            if (!is_numeric($payload['combination_id'])) {
                return false;
            }

            $combinationId = (int) $payload['combination_id'];
            if ($combinationId <= 0 || !$this->combinationBelongsToProduct($combinationId, $productId)) {
                return false;
            }
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

        if (array_key_exists('ean13', $payload)) {
            $rawEan13 = $payload['ean13'];
            if (!is_string($rawEan13)) {
                return false;
            }

            $ean13 = trim($rawEan13);
            // Chaîne vide autorisée pour effacer un EAN13 existant. Sinon : 1 à 13 chiffres
            // (format admin PrestaShop standard pour ce champ).
            if ($ean13 !== '' && !preg_match('/^[0-9]{1,13}$/', $ean13)) {
                return false;
            }

            // combination_id fourni (déjà validé plus haut) : l'EAN13 va sur la déclinaison, pas sur le
            // produit (cas pensebonheur : pelotes, code-barres posé sur la combinaison "Coloris").
            if ($combinationId > 0) {
                if (!$this->updateCombinationEan13($combinationId, $ean13)) {
                    return false;
                }
            } else {
                $product->ean13 = pSQL($ean13);
            }
            $updated = true;
        }

        if (array_key_exists('name', $payload)) {
            $rawName = $payload['name'];
            if (!is_string($rawName)) {
                return false;
            }

            $name = trim($rawName);
            if ($name === '' || !Validate::isCatalogName($name)) {
                return false;
            }

            $product->name = $this->applyToAllLanguages($name);
            $updated = true;
        }

        if (array_key_exists('description', $payload)) {
            $rawDescription = $payload['description'];
            if (!is_string($rawDescription) || !Validate::isCleanHtml($rawDescription)) {
                return false;
            }

            $product->description = $this->applyToAllLanguages($rawDescription);
            $updated = true;
        }

        if (array_key_exists('description_short', $payload)) {
            $rawDescriptionShort = $payload['description_short'];
            if (!is_string($rawDescriptionShort) || !Validate::isCleanHtml($rawDescriptionShort)) {
                return false;
            }

            $product->description_short = $this->applyToAllLanguages($rawDescriptionShort);
            $updated = true;
        }

        if (array_key_exists('reference', $payload)) {
            $rawReference = $payload['reference'];
            if (!is_string($rawReference)) {
                return false;
            }

            $reference = trim($rawReference);
            // Chaîne vide autorisée pour effacer la référence existante (même logique que ean13).
            if (Tools::strlen($reference) > 64 || !Validate::isReference($reference)) {
                return false;
            }

            $product->reference = pSQL($reference);
            $updated = true;
        }

        if (!$updated) {
            return false;
        }

        return (bool) $product->update();
    }

    /**
     * Applique la même valeur à toutes les langues installées de la boutique.
     * Utilisé pour les champs multilang PrestaShop (name, description, description_short)
     * : l'app envoie une seule valeur, on la duplique sur chaque langue active.
     *
     * @return array<int, string>
     */
    private function applyToAllLanguages(string $value): array
    {
        $values = [];

        if (class_exists('Language')) {
            /** @var array<int, array<string, mixed>> $languages */
            $languages = Language::getLanguages(false);
            foreach ($languages as $language) {
                $idLang = isset($language['id_lang']) ? (int) $language['id_lang'] : 0;
                if ($idLang > 0) {
                    $values[$idLang] = $value;
                }
            }
        }

        if ($values === []) {
            $values[$this->getLanguageId()] = $value;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatProductRow(
        array $row,
        int $langId,
        int $shopId,
        bool $includeCombinations = false,
        ?string $barcodeCode = null
    ): array {
        $idProduct = isset($row['id_product']) ? (int) $row['id_product'] : 0;
        $priceTaxExcl = isset($row['base_price']) ? (float) $row['base_price'] : 0.0;
        $priceTaxIncl = $idProduct > 0 ? (float) Product::getPriceStatic($idProduct, true) : $priceTaxExcl;
        $linkRewrite = isset($row['link_rewrite']) ? (string) $row['link_rewrite'] : '';

        $images = $this->getProductImages($idProduct, $langId, $shopId, $linkRewrite);
        $imagePayload = array_map(static function (array $image): array {
            return [
                'id' => $image['id'],
                'url' => $image['url'],
            ];
        }, $images);
        $imagePayload = array_values(array_filter($imagePayload, static function (array $image): bool {
            return isset($image['url']) && is_string($image['url']) && $image['url'] !== '';
        }));

        $updatedAt = isset($row['date_upd']) ? (string) $row['date_upd'] : null;
        $quantity = isset($row['quantity']) ? (int) $row['quantity'] : 0;
        $lowStockThreshold = isset($row['low_stock_threshold']) && (int) $row['low_stock_threshold'] > 0
            ? (int) $row['low_stock_threshold']
            : self::DEFAULT_LOW_STOCK_THRESHOLD;
        $isLow = $quantity > 0 && $quantity <= $lowStockThreshold;

        // Chargée une seule fois ici (si applicable) puis réutilisée par buildMatchedCombination() pour
        // ne pas dupliquer la requête product_attribute.
        $combinations = $includeCombinations ? $this->getProductCombinations($idProduct, $langId, $shopId) : [];

        $product = [
            'id' => $idProduct,
            'name' => isset($row['name']) ? (string) $row['name'] : '',
            'reference' => isset($row['reference']) ? (string) $row['reference'] : '',
            'ean13' => isset($row['ean13']) ? (string) $row['ean13'] : '',
            'price' => $priceTaxIncl,
            'price_tax_excl' => $priceTaxExcl,
            'active' => isset($row['active']) ? (bool) $row['active'] : false,
            'stock' => [
                'quantity' => $quantity,
                'low_stock_threshold' => $lowStockThreshold,
                'is_low' => $isLow,
                'warehouse_id' => null,
                'updated_at' => $updatedAt,
            ],
            'matched_combination' => $this->buildMatchedCombination($row, $langId, $barcodeCode, $combinations),
            'images' => $imagePayload,
            'updated_at' => $updatedAt,
        ];

        if ($includeCombinations) {
            $product['combinations'] = $combinations;
        }

        return $product;
    }

    /**
     * Construit l'objet `matched_combination` exposé sur GET /products?barcode=... quand le code scanné
     * cible SANS AMBIGUÏTÉ une déclinaison précise (product_attribute) :
     * - le code matche directement l'EAN13/référence d'UNE combinaison (cf.
     *   ProductsService::joinProductAttributeForBarcode) ;
     * - OU (v1.10.7) le code matche l'EAN13/référence du PRODUIT lui-même ET ce produit n'a
     *   qu'UNE SEULE déclinaison (cas pensebonheur : l'auto-association a posé l'EAN13 sur le produit,
     *   pas de choix à faire côté app).
     * Null si la ligne ne provient pas d'une recherche "barcode", si le produit n'a pas de déclinaison,
     * ou si le match sur le produit reste ambigu (≥ 2 déclinaisons) : l'app doit alors laisser choisir
     * via le champ `combinations`.
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $combinations déclinaisons du produit, déjà chargées par
     *                                                        formatProductRow() (évite une requête en double)
     * @return array<string, mixed>|null
     */
    private function buildMatchedCombination(array $row, int $langId, ?string $barcodeCode, array $combinations): ?array
    {
        $matchedCombinationId = isset($row['matched_id_product_attribute'])
            ? (int) $row['matched_id_product_attribute']
            : 0;

        if ($matchedCombinationId > 0) {
            $idProduct = isset($row['id_product']) ? (int) $row['id_product'] : 0;
            $combinationQuantity = class_exists('StockAvailable')
                ? (int) StockAvailable::getQuantityAvailableByProduct($idProduct, $matchedCombinationId)
                : 0;

            return [
                'id' => $matchedCombinationId,
                'name' => $this->getCombinationLabel($matchedCombinationId, $langId),
                'ean13' => isset($row['matched_pa_ean13']) ? (string) $row['matched_pa_ean13'] : '',
                'reference' => isset($row['matched_pa_reference']) ? (string) $row['matched_pa_reference'] : '',
                'quantity' => $combinationQuantity,
            ];
        }

        if ($barcodeCode === null || $barcodeCode === '') {
            return null;
        }

        $productEan13 = isset($row['ean13']) ? (string) $row['ean13'] : '';
        $productReference = isset($row['reference']) ? (string) $row['reference'] : '';
        $matchesProduct = ($productEan13 !== '' && $productEan13 === $barcodeCode)
            || ($productReference !== '' && $productReference === $barcodeCode);

        if (!$matchesProduct || count($combinations) !== 1) {
            return null;
        }

        return $combinations[0];
    }

    /**
     * Liste toutes les déclinaisons (product_attribute) d'un produit, avec leur stock propre. Utilisée
     * pour le champ `combinations` (GET /products?barcode=...) afin de laisser l'app faire choisir la
     * bonne déclinaison quand le match est ambigu (EAN13 posé sur le produit, plusieurs déclinaisons).
     *
     * @return array<int, array<string, mixed>>
     */
    private function getProductCombinations(int $productId, int $langId, int $shopId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $query = new DbQuery();
        $query->select('pa.id_product_attribute, pa.ean13, pa.reference');
        $query->from('product_attribute', 'pa');
        $query->innerJoin(
            'product_attribute_shop',
            'pas',
            'pas.id_product_attribute = pa.id_product_attribute AND pas.id_shop = ' . (int) $shopId
        );
        $query->where('pa.id_product = ' . (int) $productId);
        $query->orderBy('pa.id_product_attribute ASC');

        /** @var array<int, array<string, mixed>> $rows */
        $rows = (array) Db::getInstance()->executeS($query);

        $combinations = [];
        foreach ($rows as $row) {
            $combinationId = isset($row['id_product_attribute']) ? (int) $row['id_product_attribute'] : 0;
            if ($combinationId <= 0) {
                continue;
            }

            $combinations[] = [
                'id' => $combinationId,
                'name' => $this->getCombinationLabel($combinationId, $langId),
                'ean13' => isset($row['ean13']) ? (string) $row['ean13'] : '',
                'reference' => isset($row['reference']) ? (string) $row['reference'] : '',
                'quantity' => class_exists('StockAvailable')
                    ? (int) StockAvailable::getQuantityAvailableByProduct($productId, $combinationId)
                    : 0,
            ];
        }

        return $combinations;
    }

    /**
     * URL de l'image de couverture d'un produit (même logique que la liste produits),
     * ou null si pas d'image. Utilisé pour les miniatures des lignes d'articles du détail commande.
     */
    public function getCoverImageUrl(int $productId): ?string
    {
        if ($productId <= 0 || !class_exists('Product')) {
            return null;
        }

        /** @var array<string, mixed>|false $cover */
        $cover = Product::getCover($productId);
        $imageId = is_array($cover) && isset($cover['id_image']) ? (int) $cover['id_image'] : 0;
        if ($imageId <= 0) {
            return null;
        }

        $langId = $this->getLanguageId();
        $shopId = $this->getShopId();
        $linkRewrite = $this->resolveProductLinkRewrite($productId, $langId, $shopId);

        return $this->resolveImageUrl($linkRewrite, $productId, $imageId, 'home_default');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getProductImages(int $productId, int $langId, int $shopId, ?string $linkRewrite = null): array
    {
        if ($productId <= 0 || !class_exists('Image')) {
            return [];
        }

        /** @var array<int, array<string, mixed>>|false $images */
        $images = Image::getImages($langId, $productId);
        if ($images === false) {
            return [];
        }

        if ($linkRewrite === null || $linkRewrite === '') {
            $linkRewrite = $this->resolveProductLinkRewrite($productId, $langId, $shopId);
        }
        $formatted = [];

        foreach ($images as $image) {
            if (!isset($image['id_image'])) {
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

        $urls = array_filter($urls, static function (?string $value): bool {
            return is_string($value) && $value !== '';
        });

        $primaryUrl = $urls['large'] ?? null;
        if ($primaryUrl === null) {
            $firstKey = array_key_first($urls);
            if ($firstKey !== null) {
                $primaryUrl = $urls[$firstKey];
            }
        }

        return [
            'id' => $imageId,
            'is_cover' => $isCover,
            'legend' => $legend,
            'position' => $position,
            'url' => $primaryUrl,
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
        /** @var mixed $contextLink */
        $contextLink = $context->link;
        $this->link = $contextLink instanceof Link ? $contextLink : new Link();

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

        /** @var string|array<int, string>|null $linkRewrite */
        $linkRewrite = $product->link_rewrite;
        if (is_string($linkRewrite) && $linkRewrite !== '') {
            return $linkRewrite;
        }

        if (is_array($linkRewrite)) {
            $value = $linkRewrite[$langId] ?? reset($linkRewrite);
            if (is_string($value) && $value !== '') {
                return $value;
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
