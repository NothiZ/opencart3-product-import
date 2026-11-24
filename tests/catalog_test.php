<?php
declare(strict_types=1);

define('DB_PREFIX', 'oc_');
define('DIR_IMAGE', __DIR__);

class Model {
    public $db;
    public $config;
    public $load;
    public $model_catalog_product;
    public $model_catalog_category;
}

class NothizProductImportFiles {
    public const IMPORT_IMAGE_PATH = 'catalog/nothiz_product_import';
    public static $cleaned = 0;

    public static function discardRowImages(string $imageRoot, string $jobId, int $row): void {
        self::$cleaned++;
    }

    public static $discarded = [];

    public static function publishImages($zipPath, $entries, $imageRoot, $jobId, $row): array {
        return ['catalog/import/first.jpg', 'catalog/import/second.jpg'];
    }

    public static function discardImages(array $paths, string $imageRoot): void {
        self::$discarded = $paths;
    }
}

function expect($actual, $expected, string $message): void {
    if ($actual !== $expected) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$modelPath = __DIR__ . '/../upload/admin/model/extension/module/nothiz_product_import.php';
expect(is_file($modelPath), true, 'The catalog model must be available to the OpenCart loader.');
require $modelPath;

$db = new class {
    public $existing = ['ALREADY' => 9];
    public $lastProduct = 0;
    public $existingImages = [];
    public $queries = [];

    public function escape($value) {
        return str_replace("'", "\\'", $value);
    }

    public function query($sql) {
        $this->queries[] = $sql;
        $rows = [];
        if (strpos($sql, 'GET_LOCK(') !== false) {
            $rows[] = ['acquired' => '1'];
        } elseif (preg_match("/WHERE sku = '([^']+)'/", $sql, $match)) {
            if (isset($this->existing[strtoupper($match[1])])) {
                $rows[] = ['product_id' => $this->existing[strtoupper($match[1])], 'image' => $this->existingImages[strtoupper($match[1])] ?? ''];
            }
        } elseif (strpos($sql, 'SELECT language_id') !== false) {
            $rows = [['language_id' => '1'], ['language_id' => '2']];
        } elseif (strpos($sql, 'WHERE model =') !== false && $this->lastProduct) {
            $rows[] = ['product_id' => $this->lastProduct];
        } elseif (strpos($sql, 'FROM `oc_option`') !== false) {
            $rows[] = ['option_id' => (strpos($sql, 'Colour') === false ? '8' : '10'), 'type' => 'radio'];
        } elseif (strpos($sql, 'FROM `oc_option_value`') !== false) {
            $rows[] = ['option_value_id' => (strpos($sql, 'Small') === false ? '9' : '12')];
        } elseif (strpos($sql, 'FROM `oc_attribute_group`') !== false) {
            $rows[] = ['attribute_group_id' => '7'];
        } elseif (strpos($sql, 'FROM `oc_attribute`') !== false) {
            $rows[] = ['attribute_id' => '6'];
        }
        return (object) ['row' => $rows[0] ?? [], 'rows' => $rows, 'num_rows' => count($rows)];
    }
};

$product = new class($db) {
    public $data;
    public $fail = false;
    public $deleted = [];
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function addProduct($data) {
        $this->data = $data;
        $this->db->lastProduct = 27;
        if ($this->fail) {
            throw new RuntimeException('Partial product write');
        }
        $this->db->lastProduct = 0;
        return 27;
    }

    public function deleteProduct($productId) {
        $this->deleted[] = $productId;
    }
};

$category = new class {
    public $data = [];

    public function addCategory($data) {
        $this->data[] = $data;
        return count($this->data) + 100;
    }
};

$model = new ModelExtensionModuleNothizProductImport();
$model->db = $db;
$model->model_catalog_product = $product;
$model->model_catalog_category = $category;
$model->config = new class {
    public function get($key) {
        return ['config_language_id' => 2, 'config_stock_status_id' => 5, 'config_length_class_id' => 1, 'config_weight_class_id' => 1][$key] ?? null;
    }
};
$model->load = new class {
    public function model($route) {}
};

$base = ['title' => 'Blue shirt', 'price' => '12.50', 'description' => "A < B\r\nSecond\r\n \r\nC & D"];
$variants = ['variant_name' => 'Size', 'variant_value' => 'Large|Small', 'variant_price' => '+2.25|-1.50', 'variant_name_3' => 'Colour', 'variant_value_3' => 'Black'];
$rows = [
    ['row' => 2, 'data' => ['sku' => 'NEW-SKU'] + $variants + $base],
    ['row' => 3, 'data' => ['sku' => 'new-sku'] + $base],
    ['row' => 4, 'data' => ['sku' => 'ALREADY'] + $base],
    ['row' => 5, 'data' => ['sku' => 'NO-IMAGE'] + $base],
    ['row' => 6, 'data' => ['sku' => 'WRONG-PRICE', 'price' => '100000000000'] + $base],
    ['row' => 7, 'data' => ['sku' => str_repeat('a', 65)] + $base],
    ['row' => 8, 'data' => ['sku' => 'WRONG-VARIANT', 'variant_name' => 'Size'] + $base],
    ['row' => 9, 'data' => ['sku' => 'WRONG-QTY', 'quantity' => '4.3'] + $base],
    ['row' => 10, 'data' => ['sku' => 'DISABLED', 'status' => 'false', 'quantity' => '0', 'meta_title' => 'Custom title'] + $base],
];
$image = ['categories' => ['Apparel', 'Shirts'], 'entries' => ['first.jpg', 'second.jpg']];
$images = array_fill_keys(['NEW-SKU', 'WRONG-PRICE', 'WRONG-VARIANT', 'WRONG-QTY', 'DISABLED', str_repeat('a', 65)], $image);
$items = $model->preflight($rows, $images);
expect(array_column($items, 'status'), ['ready', 'skipped', 'skipped', 'failed', 'failed', 'failed', 'failed', 'failed', 'ready'], 'Preflight validates, deduplicates and requires an image match.');
expect(array_column($items, 'row'), range(2, 10), 'Preflight must preserve workbook row numbers and order.');

$db->queries = [];
$workbook = $model->preflight($rows, [], false);
expect(array_column($workbook, 'status'), ['awaiting_images', 'skipped', 'skipped', 'awaiting_images', 'failed', 'failed', 'failed', 'failed', 'awaiting_images'], 'Workbook-only preflight validates rows before requiring images.');
expect($workbook[4]['message'], $items[4]['message'], 'Workbook-only preflight checks price limits.');
expect($workbook[6]['message'], $items[6]['message'], 'Workbook-only preflight checks option fields.');
expect(isset($workbook[0]['product']), false, 'Rows awaiting images cannot be used for import.');
expect(count($db->queries), count(preg_grep('/^SELECT /', $db->queries)), 'Workbook-only preflight is read-only.');
$retryRows = [
    ['row' => 2, 'data' => ['sku' => 'RETRY', 'price' => 'bad'] + $base],
    ['row' => 3, 'data' => ['sku' => 'retry'] + $base],
];
expect(array_column($model->preflight($retryRows, [], false), 'status'), ['failed', 'awaiting_images'], 'An invalid row does not block a later valid row with the same SKU.');

$existingRows = [$rows[2]];
$imageFile = tempnam(DIR_IMAGE, 'image_');
try {
    $db->existingImages['ALREADY'] = basename($imageFile);
    expect($model->preflight($existingRows, [], false)[0]['existing_image'] ?? null, true, 'Existing SKU preflight finds the stored primary image on disk.');
    expect($model->preflight($existingRows, $images)[0]['existing_image'] ?? null, true, 'Preflight with a ZIP reports an existing primary image as well.');
    foreach (['', basename($imageFile) . '.missing', '.', '../README.md', "image\0.jpg"] as $unavailableImage) {
        $db->existingImages['ALREADY'] = $unavailableImage;
        $existingItem = $model->preflight($existingRows, [], false)[0];
        expect($existingItem['status'], 'skipped', 'An existing product remains skipped when its image is unavailable.');
        expect($existingItem['existing_image'] ?? null, false, 'An existing primary image must be a file beneath the image root.');
    }
    $db->existing['NEW-SKU'] = 90;
    $db->existingImages['NEW-SKU'] = basename($imageFile);
    expect($model->importItem($items[0], 'images.zip', 'job')['existing_image'] ?? null, true, 'An existing SKU detected during import reports its primary image.');
} finally {
    unlink($imageFile);
    unset($db->existingImages['ALREADY'], $db->existingImages['NEW-SKU'], $db->existing['NEW-SKU']);
}

$result = $model->importItem($items[0], 'images.zip', 'job');
expect($result['status'], 'created', 'A prepared row imports through the native catalog model.');
expect($product->data['quantity'], 1000, 'Blank quantity uses the legacy default.');
expect($product->data['stock_status_id'], 5, 'Stock status comes from the shop configuration.');
expect($product->data['product_store'], [0], 'Products are assigned to the default storefront.');
expect($product->data['product_category'], [101, 102], 'All folders in the new category path are assigned.');
expect($category->data[0]['top'], 1, 'New root categories stay visible in the top menu, as in the legacy import.');
expect($category->data[1]['parent_id'], 101, 'Nested category is created under the parent.');
expect(html_entity_decode($product->data['product_description'][2]['description'], ENT_QUOTES, 'UTF-8'), '<p>A &lt; B<br>Second</p><p>C &amp; D</p>', 'Descriptions keep paragraphs and escape source markup.');
expect($product->data['product_description'][1]['meta_title'], 'Blue shirt', 'Blank meta title uses the product title.');
expect($product->data['product_option'][0]['type'], 'radio', 'Existing compatible options retain their type.');
expect(array_column($product->data['product_option'][0]['product_option_value'], 'price_prefix'), ['+', '-'], 'Option price signs map to OpenCart prefixes.');
expect(array_column($product->data['product_option'][0]['product_option_value'], 'price'), [2.25, 1.5], 'OpenCart receives absolute price adjustments.');
expect(count($product->data['product_option']), 2, 'Numbered groups work alongside legacy fields, including a gap.');
expect($product->data['product_attribute'][0]['product_attribute_description'][2]['text'], 'Large|Small', 'Variant text is also available as a localized product attribute.');
expect($product->data['image'], 'catalog/import/first.jpg', 'The first image is the main image.');
expect($product->data['product_image'][0]['image'], 'catalog/import/second.jpg', 'Later images are gallery images.');

$db->existing['NEW-SKU'] = 90;
expect($model->importItem($items[0], 'images.zip', 'job')['status'], 'skipped', 'Import rechecks SKU availability after preview.');
$db->existingImages['NEW-SKU'] = NothizProductImportFiles::IMPORT_IMAGE_PATH . '/job/2/1.jpg';
expect($model->importItem($items[0], 'images.zip', 'job')['status'], 'created', 'A completed row replays as created only for its original job.');
expect(NothizProductImportFiles::$cleaned, 1, 'The model clears stale images before publishing, after checking SKU availability.');
$product->fail = true;
try {
    $model->importItem($items[8], 'images.zip', 'job');
    throw new LogicException('Expected a row exception.');
} catch (RuntimeException $e) {
    expect($e->getMessage(), 'Partial product write', 'The original failure is propagated.');
}
expect($product->data['quantity'], 0, 'An explicit zero quantity is honored.');
expect($product->data['product_description'][1]['meta_title'], 'Custom title', 'An optional custom meta title is honored.');
expect($product->deleted, [27], 'A product partially inserted on MyISAM is removed.');
expect(NothizProductImportFiles::$discarded, ['catalog/import/first.jpg', 'catalog/import/second.jpg'], 'Published images are removed after a row failure.');
expect(in_array('ROLLBACK', $db->queries, true), true, 'Failed writes attempt a database rollback.');

echo "Catalog tests passed.\n";
