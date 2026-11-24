<?php
class ModelExtensionModuleNothizProductImport extends Model {
    private const DEFAULT_QUANTITY = 1000;
    private const TEXT_BYTES = 65535;

    /**
     * Build a read-only preview. Rows that pass workbook validation still need a ZIP image match.
     */
    public function preflight(array $rows, array $images, bool $imagesChecked = true): array {
        $items = [];
        $seen = [];
        $imageGroups = [];
        foreach ($images as $sku => $group) {
            $key = $this->fold((string)$sku);
            $imageGroups[$key] = array_key_exists($key, $imageGroups) ? null : $group;
        }

        foreach ($rows as $row) {
            $data = $row['data'];
            $sku = $this->cell($data, 'sku');
            $title = $this->cell($data, 'title');
            $item = ['row' => $row['row'], 'sku' => $sku, 'title' => $title];

            try {
                $storedSku = $this->requiredText($sku, 'sku', 64);
                $key = $this->fold($sku);
                if (isset($seen[$key])) {
                    $items[] = $item + $this->result('skipped', 'SKU is already listed in the workbook.');
                    continue;
                }
                $existing = $this->existingProduct($sku, $storedSku);
                if ($existing) {
                    $seen[$key] = true;
                    $items[] = $item + $this->skipExistingProduct($existing);
                    continue;
                }

                $group = $images[$sku] ?? $imageGroups[$key] ?? null;
                if ($imagesChecked && empty($group['entries'])) {
                    throw new InvalidArgumentException('No unambiguous image folder matches this SKU.');
                }
                $product = $this->prepareProduct($data, $title, $storedSku, $imagesChecked ? $group : ['categories' => [], 'entries' => []]);
                $seen[$key] = true;
                if ($imagesChecked) {
                    $items[] = $item + ['product' => $product] + $this->result('ready', 'Ready to import.');
                } else {
                    $items[] = $item + $this->result('awaiting_images', 'Waiting for image ZIP validation.');
                }
            } catch (InvalidArgumentException $e) {
                $items[] = $item + $this->result('failed', $e->getMessage());
            }
        }

        return $items;
    }

    /**
     * Serialize importer writes across administrators and commit one complete product at a time.
     */
    public function importItem(array $item, string $zipPath, string $jobId): array {
        $this->load->model('catalog/product');
        $this->load->model('catalog/category');

        // SKU is not unique in stock OpenCart. One lock also protects shared catalog definitions.
        $database = defined('DB_DATABASE') ? DB_DATABASE : '';
        $lock = 'nothiz-import-' . substr(hash('sha256', $database . ':' . DB_PREFIX), 0, 40);
        $lockResult = $this->db->query("SELECT GET_LOCK('" . $lock . "', 5) AS acquired");
        if ((int)($lockResult->row['acquired'] ?? 0) !== 1) {
            return $this->result('failed', 'Another import is writing to the catalog. Run the import again.');
        }

        $paths = [];
        $productId = 0;
        $productStarted = false;
        $transactionStarted = false;
        $marker = 'nothiz-import-' . substr(hash('sha256', $jobId . ':' . $item['row']), 0, 40);

        try {
            $prepared = $item['product'];
            $this->removeIncompleteProduct($marker, $prepared['sku']);
            $existing = $this->existingProduct($item['sku'], $prepared['sku']);
            if ($existing) {
                // A request can finish the row before the controller saves its cursor.
                $imagePrefix = NothizProductImportFiles::IMPORT_IMAGE_PATH . '/' . $jobId . '/' . $item['row'] . '/';
                if (strpos((string)$existing['image'], $imagePrefix) === 0) {
                    return $this->created((int)$existing['product_id']);
                }
                return $this->skipExistingProduct($existing);
            }

            $languageIds = $this->languageIds();
            $storeId = max(0, (int)$this->config->get('config_store_id'));
            NothizProductImportFiles::discardRowImages(DIR_IMAGE, $jobId, $item['row']);
            $paths = NothizProductImportFiles::publishImages($zipPath, $prepared['entries'], DIR_IMAGE, $jobId, $item['row']);
            if (!$paths) {
                throw new RuntimeException('No product images could be published.');
            }

            $this->db->query('START TRANSACTION');
            $transactionStarted = true;
            $product = $this->productData($prepared, $paths, $languageIds, $storeId, $marker);
            $productStarted = true;
            $productId = (int)$this->model_catalog_product->addProduct($product);
            if (!$productId) {
                throw new RuntimeException('OpenCart returned no product ID.');
            }

            // Keep unfinished products off the storefront, including on the stock MyISAM schema.
            $this->db->query("UPDATE `" . DB_PREFIX . "product` SET model = '" . $this->db->escape($prepared['sku'])
                . "', status = " . $prepared['status'] . " WHERE product_id = " . $productId);
            $this->db->query('COMMIT');
            return $this->created($productId);
        } catch (Throwable $e) {
            try {
                if ($transactionStarted) {
                    $this->db->query('ROLLBACK');
                }
                // MyISAM ignores transactions. The marker identifies only this row's incomplete write.
                if ($productId) {
                    $this->model_catalog_product->deleteProduct($productId);
                } elseif ($productStarted) {
                    $this->removeIncompleteProduct($marker, $item['product']['sku']);
                }
            } finally {
                NothizProductImportFiles::discardImages($paths, DIR_IMAGE);
            }
            throw $e;
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('" . $lock . "')");
        }
    }

    private function prepareProduct(array $data, string $title, string $sku, array $group): array {
        $name = $this->requiredText($title, 'title', 255);
        $metaTitle = $this->cell($data, 'meta_title');
        $description = $this->description($this->cell($data, 'description'));
        $quantity = $this->cell($data, 'quantity');
        $status = strtolower($this->cell($data, 'status'));
        if ($quantity !== '' && (!is_numeric($quantity) || (float)$quantity < 0 ||
            (float)$quantity > 2147483647 || floor((float)$quantity) !== (float)$quantity)) {
            throw new InvalidArgumentException('quantity must be a whole number between 0 and 2147483647.');
        }
        if (!in_array($status, ['', '0', '1', 'false', 'true', 'no', 'yes'], true)) {
            throw new InvalidArgumentException('status must be 0, 1, false, true, no, or yes.');
        }

        $categories = [];
        foreach ($group['categories'] as $category) {
            $categories[] = $this->requiredText($category, 'Category folder name', 255);
        }

        return [
            'sku' => $sku,
            'name' => $name,
            'meta_title' => $metaTitle === '' ? $name : $this->requiredText($metaTitle, 'meta_title', 255),
            'description' => $description,
            'price' => $this->price($this->cell($data, 'price'), 'price', false),
            'quantity' => $quantity === '' ? self::DEFAULT_QUANTITY : (int)(float)$quantity,
            'status' => in_array($status, ['0', 'false', 'no'], true) ? 0 : 1,
            'variants' => $this->variants($data),
            'categories' => $categories,
            'entries' => $group['entries']
        ];
    }

    private function variants(array $data): array {
        // Discover numbered groups from their keys so a blank or absent group cannot hide later groups.
        $groups = [0 => ''];
        foreach (array_keys($data) as $key) {
            if (preg_match('/^variant_(?:name|value|price)_([1-9][0-9]*)$/D', $key, $match)) {
                $groups[$match[1]] = '_' . $match[1];
            }
        }
        ksort($groups, SORT_NUMERIC);

        $variants = [];
        $seen = [];
        foreach ($groups as $suffix) {
            $name = $this->cell($data, 'variant_name' . $suffix);
            $value = $this->cell($data, 'variant_value' . $suffix);
            $price = $this->cell($data, 'variant_price' . $suffix);
            if ($name === '' && $value === '' && $price === '') {
                continue;
            }

            $storedName = $this->requiredText($name, 'variant_name' . $suffix, 64);
            $nameKey = $this->fold($name);
            if (isset($seen[$nameKey])) {
                throw new InvalidArgumentException('An option name is repeated in this row.');
            }
            $seen[$nameKey] = true;
            $values = preg_split('/[,\r\n|]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
            $prices = preg_split('/[,|\n]/u', str_replace(["\r\n", "\r"], "\n", $price));
            $values = array_values(array_filter(array_map('trim', $values), static function ($part) {
                return $part !== '';
            }));
            if (!$values) {
                throw new InvalidArgumentException('variant_value' . $suffix . ' requires at least one option value.');
            }
            $optionValues = [];
            $used = [];
            foreach ($values as $index => $valueName) {
                $valueKey = $this->fold($valueName);
                if (isset($used[$valueKey])) {
                    throw new InvalidArgumentException('An option value is repeated in variant_value' . $suffix . '.');
                }
                $used[$valueKey] = true;
                $adjustment = trim($prices[$index] ?? '');
                $optionValues[] = [
                    'name' => $this->requiredText($valueName, 'variant_value' . $suffix, 128),
                    'price' => $adjustment === '' ? 0.0 : $this->price($adjustment, 'variant_price' . $suffix, true)
                ];
            }

            $variants[] = [
                'name' => $storedName,
                'text' => $this->limitedText($this->encode($value), 'variant_value' . $suffix),
                'values' => $optionValues
            ];
        }

        return $variants;
    }

    private function cell(array $data, string $key): string {
        $value = trim((string)($data[$key] ?? ''));
        return strtolower($value) === 'nan' ? '' : $value;
    }

    private function fold(string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function encode(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function requiredText(string $value, string $field, int $limit): string {
        $stored = $this->encode($value);
        if ($stored === '' || preg_match_all('/./us', $stored) > $limit) {
            throw new InvalidArgumentException($field . ' must contain 1 to ' . $limit . ' characters after escaping.');
        }
        return $stored;
    }

    private function limitedText(string $value, string $field): string {
        if (strlen($value) > self::TEXT_BYTES) {
            throw new InvalidArgumentException($field . ' is too long for OpenCart.');
        }
        return $value;
    }

    private function description(string $value): string {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $html = '';
        foreach (preg_split('/\n\h*\n+/u', $value) as $paragraph) {
            if (trim($paragraph) !== '') {
                $html .= '<p>' . str_replace("\n", '<br>', $this->encode(trim($paragraph))) . '</p>';
            }
        }

        // The storefront decodes the stored description once, so protect the escaped source text.
        return $this->limitedText($this->encode($html), 'description');
    }

    private function price(string $value, string $field, bool $allowNegative): float {
        $amount = (float)$value;
        // OpenCart writes prices by casting them to float, and the database stores DECIMAL(15,4).
        if (!is_numeric($value) || !is_finite($amount) || (!$allowNegative && $amount < 0) ||
            abs($amount) > 99999999999.9999 || (float)(string)abs($amount) >= 100000000000) {
            throw new InvalidArgumentException($field . ' must be a price within the OpenCart decimal limit.');
        }
        return round($amount, 4);
    }

    private function existingProduct(string $sku, string $storedSku): array {
        $sql = "SELECT product_id, image FROM `" . DB_PREFIX . "product` WHERE sku = '" . $this->db->escape($storedSku) . "'";
        if ($sku !== $storedSku) {
            $sql .= " OR sku = '" . $this->db->escape($sku) . "'";
        }
        return $this->db->query($sql . ' LIMIT 1')->row;
    }

    private function skipExistingProduct(array $product): array {
        $result = $this->result('skipped', 'SKU already exists in OpenCart.');
        $result['existing_image'] = false;
        $image = (string)($product['image'] ?? '');
        $root = realpath(DIR_IMAGE);
        if ($root !== false && $image !== '' && strpos($image, "\0") === false) {
            // Resolve the stored path before checking that it stays inside the image directory.
            $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $path = realpath($root . $image);
            $result['existing_image'] = $path !== false && strpos($path, $root) === 0 && is_file($path);
        }
        return $result;
    }

    private function removeIncompleteProduct(string $marker, string $sku): void {
        $query = $this->db->query("SELECT product_id, image FROM `" . DB_PREFIX . "product` WHERE model = '" . $marker
            . "' AND sku = '" . $this->db->escape($sku) . "' AND status = 0 LIMIT 1");
        if ($query->num_rows) {
            $this->model_catalog_product->deleteProduct((int)$query->row['product_id']);
        }
    }

    private function languageIds(): array {
        $default = (int)$this->config->get('config_language_id');
        $query = $this->db->query("SELECT language_id FROM `" . DB_PREFIX . "language` WHERE status = 1 ORDER BY language_id");
        $ids = array_map('intval', array_column($query->rows, 'language_id'));
        if ($default > 0) {
            array_unshift($ids, $default);
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            throw new RuntimeException('OpenCart has no active content language.');
        }
        return $ids;
    }

    private function productData(array $item, array $paths, array $languageIds, int $storeId, string $marker): array {
        $description = [
            'name' => $item['name'], 'description' => $item['description'], 'tag' => '',
            'meta_title' => $item['meta_title'], 'meta_description' => '', 'meta_keyword' => ''
        ];
        $gallery = [];
        foreach (array_slice($paths, 1) as $index => $path) {
            $gallery[] = ['image' => $path, 'sort_order' => $index + 1];
        }
        $categories = $this->categoryPath($item['categories'], $languageIds, $storeId);
        [$options, $attributes] = $this->catalogVariants($item['variants'], $languageIds, $item['quantity']);

        return [
            'model' => $marker, 'sku' => $item['sku'],
            'upc' => '', 'ean' => '', 'jan' => '', 'isbn' => '', 'mpn' => '', 'location' => '',
            'quantity' => $item['quantity'], 'minimum' => 1, 'subtract' => 1,
            'stock_status_id' => (int)$this->config->get('config_stock_status_id'),
            'date_available' => date('Y-m-d'), 'manufacturer_id' => 0, 'shipping' => 1,
            'price' => $item['price'], 'points' => 0, 'weight' => 0,
            'weight_class_id' => (int)$this->config->get('config_weight_class_id'),
            'length' => 0, 'width' => 0, 'height' => 0,
            'length_class_id' => (int)$this->config->get('config_length_class_id'),
            'status' => 0, 'tax_class_id' => 0, 'sort_order' => 0,
            'image' => $paths[0], 'product_image' => $gallery,
            'product_description' => array_fill_keys($languageIds, $description),
            'product_store' => [$storeId], 'product_category' => $categories,
            'product_option' => $options, 'product_attribute' => $attributes
        ];
    }

    // Resolve category paths by parent and name, and use the native model for new descendants.
    private function categoryPath(array $names, array $languageIds, int $storeId): array {
        $ids = [];
        $parentId = 0;
        foreach ($names as $name) {
            $match = $this->findNamed('category', $name, $languageIds[0], ' AND item.parent_id = ' . $parentId);
            $description = [
                'name' => $name, 'description' => '', 'meta_title' => $name,
                'meta_description' => '', 'meta_keyword' => ''
            ];
            if (!$match) {
                $categoryId = (int)$this->model_catalog_category->addCategory([
                    'parent_id' => $parentId, 'top' => $parentId === 0 ? 1 : 0, 'column' => 1, 'sort_order' => 0, 'status' => 1,
                    'category_description' => array_fill_keys($languageIds, $description),
                    'category_store' => [$storeId]
                ]);
                if (!$categoryId) {
                    throw new RuntimeException('OpenCart returned no category ID.');
                }
            } else {
                $categoryId = (int)$match['category_id'];
                $this->fillNames('category', $categoryId, $name, $languageIds, ", description = '', meta_title = '"
                    . $this->db->escape($name) . "', meta_description = '', meta_keyword = ''");
                $this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "category_to_store` SET category_id = "
                    . $categoryId . ", store_id = " . $storeId);
                foreach (array_merge($ids, [$categoryId]) as $level => $pathId) {
                    $this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "category_path` SET category_id = "
                        . $categoryId . ", path_id = " . $pathId . ", level = " . $level);
                }
            }
            $ids[] = $categoryId;
            $parentId = $categoryId;
        }

        return $ids;
    }

    // Preserve existing catalog option IDs and append new values without rewriting other products' choices.
    private function catalogVariants(array $variants, array $languageIds, int $quantity): array {
        if (!$variants) {
            return [[], []];
        }

        $groupId = $this->namedId('attribute_group', 'Specifications', $languageIds, 'sort_order = 0');
        $options = [];
        $attributes = [];
        $supportedTypes = " AND item.type IN ('select', 'radio', 'checkbox', 'image')";
        foreach ($variants as $variant) {
            $option = $this->findNamed('option', $variant['name'], $languageIds[0], $supportedTypes);
            if ($option) {
                $optionId = (int)$option['option_id'];
                $type = $option['type'];
                $this->fillNames('option', $optionId, $variant['name'], $languageIds);
            } else {
                $optionId = $this->insertNamed('option', $variant['name'], $languageIds, "type = 'select', sort_order = 0");
                $type = 'select';
            }

            $values = [];
            foreach ($variant['values'] as $index => $value) {
                $optionField = 'option_id = ' . $optionId;
                $valueId = $this->namedId('option_value', $value['name'], $languageIds,
                    $optionField . ", image = '', sort_order = " . $index, ' AND item.' . $optionField, ', ' . $optionField);

                // MySQL collation can match different workbook spellings to the same option value.
                if (isset($values[$valueId])) {
                    throw new RuntimeException('Two workbook values match the same OpenCart option value.');
                }
                $values[$valueId] = [
                    'option_value_id' => $valueId, 'quantity' => $quantity, 'subtract' => 0,
                    'price' => abs($value['price']), 'price_prefix' => $value['price'] < 0 ? '-' : '+',
                    'points' => 0, 'points_prefix' => '+', 'weight' => 0, 'weight_prefix' => '+'
                ];
            }
            if (isset($options[$optionId])) {
                throw new RuntimeException('Two workbook variants match the same OpenCart option.');
            }
            $options[$optionId] = [
                'option_id' => $optionId, 'type' => $type, 'required' => 1,
                'product_option_value' => array_values($values)
            ];
            $groupField = 'attribute_group_id = ' . $groupId;
            $attributeId = $this->namedId('attribute', $variant['name'], $languageIds,
                $groupField . ', sort_order = 0', ' AND item.' . $groupField);
            $attributes[] = [
                'attribute_id' => $attributeId,
                'product_attribute_description' => array_fill_keys($languageIds, ['text' => $variant['text']])
            ];
        }

        return [array_values($options), $attributes];
    }

    private function findNamed(string $table, string $name, int $languageId, string $conditions): array {
        // Older imports stored literal names, while native OpenCart forms store entity-encoded names.
        $plain = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
        $names = "'" . $this->db->escape($name) . "'";
        if ($plain !== $name) {
            $names .= ", '" . $this->db->escape($plain) . "'";
        }
        $id = $table . '_id';
        $sql = "SELECT item.* FROM `" . DB_PREFIX . $table . "` item INNER JOIN `" . DB_PREFIX . $table
            . "_description` description ON description." . $id . " = item." . $id . " WHERE description.name IN (" . $names . ")"
            . $conditions . " ORDER BY (description.language_id = " . $languageId . ") DESC, item." . $id . " LIMIT 1";
        return $this->db->query($sql)->row;
    }

    private function namedId(string $table, string $name, array $languageIds, string $fields, string $conditions = '', string $descriptionFields = ''): int {
        $match = $this->findNamed($table, $name, $languageIds[0], $conditions);
        if (!$match) {
            return $this->insertNamed($table, $name, $languageIds, $fields, $descriptionFields);
        }
        $id = (int)$match[$table . '_id'];
        $this->fillNames($table, $id, $name, $languageIds, $descriptionFields);
        return $id;
    }

    private function insertNamed(string $table, string $name, array $languageIds, string $fields, string $descriptionFields = ''): int {
        $this->db->query("INSERT INTO `" . DB_PREFIX . $table . "` SET " . $fields);
        $id = (int)$this->db->getLastId();
        if (!$id) {
            throw new RuntimeException('OpenCart returned no ID for the imported ' . str_replace('_', ' ', $table) . '.');
        }
        $this->fillNames($table, $id, $name, $languageIds, $descriptionFields);
        return $id;
    }

    private function fillNames(string $table, int $id, string $name, array $languageIds, string $fields = ''): void {
        foreach ($languageIds as $languageId) {
            $this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . $table . "_description` SET " . $table . "_id = " . $id
                . ", language_id = " . $languageId . ", name = '" . $this->db->escape($name) . "'" . $fields);
        }
    }

    private function created(int $productId): array {
        return $this->result('created', 'Created OpenCart product #' . $productId . '.');
    }

    private function result(string $status, string $message): array {
        return ['status' => $status, 'message' => $message];
    }
}
