<?php
declare(strict_types=1);

define('DB_PREFIX', 'oc_');
$workDir = sys_get_temp_dir() . '/nothiz_catalog_test_' . bin2hex(random_bytes(8));
mkdir($workDir, 0700);
$zipPath = $workDir . '/images.zip';
register_shutdown_function(static function () use ($workDir) {
    foreach (glob($workDir . '/*') as $path) {
        unlink($path);
    }
    rmdir($workDir);
});

define('DIR_IMAGE', $workDir);
define('DIR_SYSTEM', __DIR__ . '/../upload/system/');

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
    public static $published = 0;
    public static $reused = false;

    public static function discardRowImages(string $imageRoot, string $jobId, int $row): void {
        self::$cleaned++;
    }

    public static $discarded = [];

    public static function publishImages($zipPath, $entries, $imageRoot, $jobId, $row, ?array &$publication = null): array {
        self::$published++;
        if ($publication !== null) {
            $publication = [['path' => 'catalog/import/second.jpg']];
            if (!self::$reused) {
                array_unshift($publication, ['path' => 'catalog/import/first.jpg']);
            }
        }
        return ['catalog/import/first.jpg', 'catalog/import/second.jpg'];
    }

    public static function discardImages(array $paths, string $imageRoot): void {
        self::$discarded = $paths;
    }

    public static function discardPublishedImages(array $publication, string $imageRoot): void {
        self::$discarded = array_column($publication, 'path');
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
    public $products = [9 => ['product_id' => 9, 'sku' => 'ALREADY', 'model' => '', 'status' => 1, 'image' => '']];
    public $lock = '1';
    public $failCommit = false;
    public $failFinalize = false;
    public $queries = [];

    public function addFixture(int $id, string $sku, string $model, string $image = '', int $status = 1): void {
        $this->products[$id] = ['product_id' => $id, 'sku' => $sku, 'model' => $model, 'status' => $status, 'image' => $image];
    }

    public function escape($value) {
        return str_replace("'", "\\'", $value);
    }

    public function query($sql) {
        $this->queries[] = $sql;
        $rows = [];
        if (strpos($sql, 'GET_LOCK(') !== false) {
            $rows[] = ['acquired' => $this->lock];
        } elseif (preg_match("/WHERE \\(sku IN \\((.*?)\\) OR model IN /", $sql, $match)) {
            preg_match_all("/'((?:\\\\.|[^'])*)'/", $match[1], $values);
            $keys = array_map('strtoupper', array_map('stripslashes', $values[1]));
            foreach ($this->products as $record) {
                if (!in_array(strtoupper($record['sku']), $keys, true) && !in_array(strtoupper($record['model']), $keys, true)) {
                    continue;
                }
                if (preg_match("/BINARY model <> '([^']+)'/", $sql, $marker)
                    && $record['model'] === $marker[1] && $record['status'] === 0 && in_array(strtoupper($record['sku']), $keys, true)) {
                    continue;
                }
                $rows[] = $record;
            }
        } elseif (preg_match("/WHERE sku = '([^']+)'/", $sql, $match)) {
            $rows = array_values(array_filter($this->products, static function ($record) use ($match) {
                return strcasecmp($record['sku'], $match[1]) === 0;
            }));
        } elseif (preg_match("/WHERE (?:BINARY )?model = '([^']+)' AND (?:BINARY )?sku = '([^']+)' AND status = 0/", $sql, $match)) {
            $rows = array_values(array_filter($this->products, static function ($record) use ($match) {
                return $record['model'] === $match[1] && $record['sku'] === $match[2] && $record['status'] === 0;
            }));
        } elseif (preg_match("/SET model = '([^']+)', status = (\\d+) WHERE product_id = (\\d+)/", $sql, $match)) {
            if ($this->failFinalize) {
                throw new RuntimeException('Product finalization interrupted');
            }
            $this->products[(int)$match[3]]['model'] = $match[1];
            $this->products[(int)$match[3]]['status'] = (int)$match[2];
        } elseif (preg_match('/WHERE product_id = (\d+)$/', $sql, $match)) {
            $rows[] = $this->products[(int)$match[1]];
        } elseif ($sql === 'COMMIT' && $this->failCommit) {
            throw new RuntimeException('Connection interrupted after commit');
        } elseif (strpos($sql, 'SELECT language_id') !== false) {
            $rows = [['language_id' => '1'], ['language_id' => '2']];
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
    private $nextId = 27;
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function addProduct($data) {
        $this->data = $data;
        $id = $this->nextId++;
        $this->db->addFixture($id, $data['sku'], $data['model'], $data['image'], $data['status']);
        if ($this->fail) {
            throw new RuntimeException('Partial product write');
        }
        return $id;
    }

    public function deleteProduct($productId) {
        $this->deleted[] = $productId;
        unset($this->db->products[$productId]);
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
expect(array_column($items, 'status'), ['ready', 'duplicate', 'skipped', 'failed', 'failed', 'failed', 'failed', 'failed', 'ready'], 'Preflight validates, deduplicates and requires an image match.');
expect(array_column($items, 'row'), range(2, 10), 'Preflight must preserve workbook row numbers and order.');
$reusedPreview = $model->preflight([$rows[0]], ['NEW-SKU' => $image + ['reused' => 2]])[0];
expect($reusedPreview['issue'], ['code' => 'ready_images_reused', 'params' => [2]], 'Preview reports the number of matching existing images.');

$db->queries = [];
$workbook = $model->preflight($rows, [], false);
expect(array_column($workbook, 'status'), ['awaiting_images', 'duplicate', 'skipped', 'awaiting_images', 'failed', 'failed', 'failed', 'failed', 'awaiting_images'], 'Workbook-only preflight validates rows before requiring images.');
expect($workbook[4]['issue'], ['code' => 'invalid_price', 'params' => ['price']], 'Workbook-only preflight checks price limits.');
expect($workbook[6]['issue'], ['code' => 'option_value_required', 'params' => ['variant_value']], 'Workbook-only preflight checks option fields.');
expect(isset($workbook[0]['product']), false, 'Rows awaiting images cannot be used for import.');
expect(count($db->queries), count(preg_grep('/^SELECT /', $db->queries)), 'Workbook-only preflight is read-only.');
$retryRows = [
    ['row' => 2, 'data' => ['sku' => 'RETRY', 'price' => 'bad'] + $base],
    ['row' => 3, 'data' => ['sku' => 'retry'] + $base],
];
$duplicateRows = $model->preflight($retryRows, [], false);
expect(array_column($duplicateRows, 'status'), ['failed', 'duplicate'], 'The first Excel row reserves the SKU even when it fails validation.');
expect($duplicateRows[1]['issue'], ['code' => 'duplicate_workbook', 'params' => [2]], 'The duplicate identifies its first Excel row.');
$structuredIssue = ['code' => 'image_conflict', 'params' => ['Shirts/NEW-SKU/first.jpg']];
$imageIssue = $model->preflight([$rows[0]], $images, true, ['new-sku' => [$structuredIssue]])[0];
expect($imageIssue['status'], 'failed', 'A case-insensitive archive issue blocks the corresponding product.');
expect($imageIssue['issue'], $structuredIssue, 'Preflight preserves the original archive issue.');

$db->addFixture(70, 'DIFFERENT', 'MODEL-ONLY');
$db->addFixture(71, 'CONFLICT', 'OTHER');
$db->addFixture(72, 'OTHER', 'CONFLICT');
$db->addFixture(73, 'MATCH-ONCE', 'MATCH-ONCE');
$db->addFixture(74, 'DIFFERENT', 'A&amp;B');
$db->addFixture(75, 'DUPLICATE-SKU', 'OTHER-A');
$db->addFixture(76, 'DUPLICATE-SKU', 'OTHER-B');
$db->addFixture(77, 'C&D', 'DIFFERENT');
$identifierRows = [];
foreach (['MODEL-ONLY', 'CONFLICT', 'MATCH-ONCE', 'A&B', 'DUPLICATE-SKU', 'C&D'] as $sku) {
    $identifierRows[] = ['row' => count($identifierRows) + 2, 'data' => ['sku' => $sku] + $base];
}
$identifierChecks = $model->preflight($identifierRows, [], false);
expect(array_column($identifierChecks, 'status'), ['skipped', 'failed', 'skipped', 'skipped', 'failed', 'skipped'], 'Both raw and encoded identifiers are checked against SKU and model fields.');
expect($identifierChecks[1]['issue'], ['code' => 'identifier_conflict', 'params' => []], 'Two product IDs produce an identifier conflict.');

$existingRows = [$rows[2]];
$imageFile = tempnam(DIR_IMAGE, 'image_');
try {
    $db->products[9]['image'] = basename($imageFile);
    expect($model->preflight($existingRows, [], false)[0]['existing_image'] ?? null, true, 'Existing SKU preflight finds the stored primary image on disk.');
    expect($model->preflight($existingRows, $images)[0]['existing_image'] ?? null, true, 'Preflight with a ZIP reports an existing primary image as well.');
    foreach (['', basename($imageFile) . '.missing', '.', '../README.md', "image\0.jpg"] as $unavailableImage) {
        $db->products[9]['image'] = $unavailableImage;
        $existingItem = $model->preflight($existingRows, [], false)[0];
        expect($existingItem['status'], 'skipped', 'An existing product remains skipped when its image is unavailable.');
        expect($existingItem['existing_image'] ?? null, false, 'An existing primary image must be a file beneath the image root.');
    }
    $db->addFixture(90, 'OTHER-SKU', 'NEW-SKU', basename($imageFile));
    expect($model->importItem($items[0], $zipPath, 'job')['existing_image'] ?? null, true, 'An existing model detected during import reports its primary image.');
    $db->addFixture(91, 'NEW-SKU', 'OTHER-MODEL');
    $partialMarker = 'nothiz-import-' . substr(hash('sha256', 'job:2'), 0, 40);
    $db->addFixture(92, 'NEW-SKU', $partialMarker, '', 0);
    $db->queries = [];
    expect($model->importItem($items[0], $zipPath, 'job')['issue']['code'], 'identifier_conflict', 'Import checks for conflicts again after preflight.');
    expect(NothizProductImportFiles::$published, 0, 'Catalog conflicts are detected before image publication.');
    expect($product->deleted, [], 'The conflict does not mutate any catalog products.');
    expect(count($db->queries), count(preg_grep('/^SELECT /', $db->queries)), 'Catalog conflicts do not issue any write commands.');
} finally {
    unlink($imageFile);
    unset($db->products[90], $db->products[91]);
}

$db->lock = '0';
$db->queries = [];
expect($model->importItem($items[0], $zipPath, 'job'), ['status' => 'deferred'], 'Catalog lock contention defers the row.');
expect(count($db->queries), 1, 'Contention performs only the lock request.');
$db->lock = null;
try {
    $model->importItem($items[0], $zipPath, 'job');
    throw new LogicException('Expected a database lock error.');
} catch (RuntimeException $e) {
    expect($e->getMessage(), 'OpenCart could not acquire the catalog import lock.', 'A database lock error is surfaced.');
    expect($e instanceof NothizProductImportIssue, true, 'A database lock failure can be localized.');
    expect($e->issue(), ['code' => 'catalog_lock_error', 'params' => []], 'Database lock errors identify the failed operation.');
}
$db->lock = '1';

$result = $model->importItem($items[0], $zipPath, 'job');
expect($result['status'], 'created', 'A prepared row imports through the native catalog model.');
expect($product->deleted, [92], 'An unambiguous retry removes only the marker for this unfinished product.');
expect($result['issue'], ['code' => 'created_product', 'params' => [27]], 'The created result stores a localizable product ID.');
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

expect($model->importItem($items[0], $zipPath, 'job'), $result, 'A reloaded job cursor reports the same created result.');
expect($model->importItem($items[0], $zipPath, 'another-job')['status'], 'skipped', 'An unrelated job does not claim a product through a shared archive path.');
expect(NothizProductImportFiles::$published, 1, 'A completed row replays without republishing files.');
expect(NothizProductImportFiles::$cleaned, 0, 'The numeric legacy image cleanup does not run for new jobs.');
$legacyItem = $items[0];
unset($legacyItem['product']['image_layout'], $db->products[27]);
$db->addFixture(90, 'NEW-SKU', 'NEW-SKU', NothizProductImportFiles::IMPORT_IMAGE_PATH . '/job/2/1.jpg');
expect($model->importItem($legacyItem, $zipPath, 'job')['status'], 'created', 'Legacy jobs can replay according to their private numeric image path.');
unset($db->products[90]);
$product->fail = true;
NothizProductImportFiles::$reused = true;
try {
    $model->importItem($items[8], $zipPath, 'job');
    throw new LogicException('Expected a row exception.');
} catch (RuntimeException $e) {
    expect($e->getMessage(), 'Partial product write', 'The original failure is propagated.');
}
expect($product->data['quantity'], 0, 'An explicit zero quantity is honored.');
expect($product->data['product_description'][1]['meta_title'], 'Custom title', 'An optional custom meta title is honored.');
expect($product->deleted, [92, 28], 'A product partially inserted on MyISAM is removed.');
expect(NothizProductImportFiles::$discarded, ['catalog/import/second.jpg'], 'A row failure removes only files that it published.');
expect(in_array('ROLLBACK', $db->queries, true), true, 'Failed writes attempt a database rollback.');

$product->fail = false;
$db->failFinalize = true;
try {
    $model->importItem($items[8], $zipPath, 'job');
    throw new LogicException('Expected a finalization error.');
} catch (RuntimeException $e) {
    expect($e->getMessage(), 'Product finalization interrupted', 'A stalled write keeps the original failure.');
}
expect($product->deleted, [92, 28, 29], 'A receipt cannot turn an unpublished product into a success.');
$db->failFinalize = false;
$db->failCommit = true;
$result = $model->importItem($items[8], $zipPath, 'job');
expect($result['status'], 'created', 'A finalized and confirmed product survives interruption after database commit.');
expect($result['issue']['params'], [30], 'Recovery replaces the stale receipt with the new product ID.');
expect($model->importItem($items[8], $zipPath, 'job'), $result, 'An interrupted commit result also replays without writes.');
expect($product->deleted, [92, 28, 29], 'Completed products are never removed by interrupted-write cleanup.');

echo "Catalog tests passed.\n";
