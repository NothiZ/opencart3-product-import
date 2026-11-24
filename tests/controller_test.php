<?php
// A standalone check for the shell of the module, with an OpenCart-style registry.
class Controller {
    private $registry;
    public function __construct($registry) { $this->registry = $registry; }
    public function __get($name) { return $this->registry[$name]; }
}

class TestResponse {
    public $output;
    public $headers = [];
    public function addHeader($header) { $this->headers[] = $header; }
    public function setOutput($output) { $this->output = $output; }
}

function check($value, $message) {
    if (!$value) {
        throw new RuntimeException($message);
    }
}

define('DIR_UPLOAD', sys_get_temp_dir() . '/nothiz-controller-' . bin2hex(random_bytes(6)) . '/');
mkdir(DIR_UPLOAD);
define('DIR_IMAGE', DIR_UPLOAD);
define('DIR_SYSTEM', dirname(__DIR__) . '/upload/system/');
require dirname(__DIR__) . '/upload/admin/controller/extension/module/nothiz_product_import.php';

$job = str_repeat('a', 32);
$dir = DIR_UPLOAD . 'nothiz_product_import_' . $job;
mkdir($dir);
file_put_contents($dir . '/job.json', json_encode([
    'items' => [
        ['row' => 2, 'sku' => 'OLD', 'title' => 'Existing', 'status' => 'skipped', 'message' => 'Existing SKU'],
        ['row' => 3, 'sku' => 'NEW', 'title' => 'New', 'status' => 'ready', 'message' => 'Ready'],
    ],
    'cursor' => 0,
]));
$session = (object) ['data' => ['user_token' => 'admin-token', 'nothiz_product_import_job' => $job]];
$request = (object) ['server' => ['REQUEST_METHOD' => 'POST'], 'get' => ['user_token' => 'wrong'], 'post' => ['job' => $job]];
$response = new TestResponse();
$model = new class {
    public $calls = [];
    public $defer = true;
    public $afterImport;
    public function importItem($item, $zipPath, $job) {
        $this->calls[] = $item['sku'];
        if ($this->defer) { return ['status' => 'deferred']; }
        if ($this->afterImport) { ($this->afterImport)(); }
        return ['status' => 'created', 'issue' => ['code' => 'created_product', 'params' => [12]]];
    }
};
$language = new class {
    public $locale = 'en-gb';
    private $messages = [];
    public function load($route) {
        $_ = [];
        require dirname(__DIR__) . '/upload/admin/language/' . $this->locale . '/' . $route . '.php';
        $this->messages = $_;
        return $_;
    }
    public function get($key) { return $this->messages[$key] ?? $key; }
};
$controller = new ControllerExtensionModuleNothizProductImport([
    'session' => $session,
    'request' => $request,
    'response' => $response,
    'load' => new class { public function model($route) {} },
    'user' => new class { public function hasPermission($type, $route) { return true; } },
    'language' => $language,
    'log' => new class { public function write($message) { throw new RuntimeException($message); } },
    'model_extension_module_nothiz_product_import' => $model,
]);

try {
    $controller->batch();
    check(isset(json_decode($response->output, true)['error']), 'Bad token must be rejected');
    check(!$model->calls, 'Rejected request must not write');
    $controller->checkArchive();
    check(isset(json_decode($response->output, true)['error']), 'ZIP checks require a valid token');
    $controller->pauseImport();
    check(!is_file($dir . '/paused'), 'Rejected pause must not set a marker');
    $request->get['user_token'] = 'admin-token';
    $request->post['job'] = str_repeat('b', 32);
    $controller->batch();
    check(isset(json_decode($response->output, true)['error']), 'A different job must be rejected');
    $request->post['job'] = $job;
    $controller->batch();
    $result = json_decode($response->output, true);
    check(!empty($result['deferred']) && $result['processed'] === 1 && $result['ready'] === 1, 'Lock wait must preserve the ready row and save the preceding cursor');
    $controller->pauseImport();
    $controller->batch();
    check(!empty(json_decode($response->output, true)['paused']) && $model->calls === ['NEW'], 'Pause must not advance the waiting row');
    $model->defer = false;
    $request->post['resume'] = '1';
    $controller->batch();
    $result = json_decode($response->output, true);
    unset($request->post['resume']);
    check($result['done'] && $model->calls === ['NEW', 'NEW'], 'Resume must import the waiting row once');
    $controller->batch();
    check($model->calls === ['NEW', 'NEW'], 'Replayed batch must not create another product');
    $controller->report();
    check(strpos($response->output, 'Created OpenCart product #12.') !== false, 'CSV should translate persisted diagnostics');
    $language->locale = 'zh-cn';
    $controller->report();
    check(strpos($response->output, '已创建 OpenCart 商品 #12。') !== false, 'CSV should reflect the current backend language');
    check(strpos($response->output, 'Created OpenCart product #12.') === false, 'Structured CSV messages should not be stuck in the earlier locale');

    file_put_contents($dir . '/job.json', json_encode([
        'items' => [
            ['row' => 2, 'sku' => 'FIRST', 'title' => 'First', 'status' => 'ready'],
            ['row' => 3, 'sku' => 'SECOND', 'title' => 'Second', 'status' => 'ready'],
        ],
        'cursor' => 0,
    ]));
    $model->calls = [];
    $model->afterImport = function () use ($controller) { $controller->pauseImport(); };
    $controller->batch();
    $result = json_decode($response->output, true);
    check($result['paused'] && $result['processed'] === 1 && $model->calls === ['FIRST'], 'A pause requested during a row should finish that row only');
    $request->post = [
        'workbook_name' => 'fixture.xlsx',
        'workbook_size' => '1',
        'images_name' => 'fixture.zip',
        'images_size' => '1',
        'ignore_outer_folder' => '1',
    ];
    $controller->beginUpload();
    $upload = json_decode($response->output, true)['upload'];
    $stagingDir = DIR_UPLOAD . 'nothiz_product_import_' . $upload;
    $session->data['nothiz_product_import_upload']['needs_images'] = true;
    $session->data['nothiz_product_import_upload']['archive_sha'] = str_repeat('a', 64);
    file_put_contents($stagingDir . '/reused-images.json', '{}');
    file_put_contents($stagingDir . '/archive-scan.json', '{"strip":true,"state":null,"done":true}');
    $zip = new ZipArchive();
    check($zip->open($stagingDir . '/images.zip', ZipArchive::CREATE) === true, 'Create ZIP fixture');
    $png = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($png);
    $zip->addFromString('Photos 2026/Home/NEW/a.png', ob_get_clean());
    $zip->close();

    $request->post = ['upload' => $upload];
    $controller->checkArchive();
    $result = json_decode($response->output, true);
    $scan = json_decode(file_get_contents($stagingDir . '/archive-scan.json'), true);
    check(!empty($result['done']) && ($scan['index']['outer_folder'] ?? null) === 'Photos 2026', 'Restart obsolete scan checkpoints and detect the upload folder');
    check(($scan['index']['groups']['NEW']['categories'] ?? null) === ['Home'], 'Use the upload preference even on a separate scan request');

    echo "Controller checks passed\n";
} finally {
    foreach (array_filter([$dir, $stagingDir ?? null]) as $directory) {
        foreach (array_diff(scandir($directory), ['.', '..']) as $name) {
            unlink($directory . '/' . $name);
        }
        rmdir($directory);
    }
    rmdir(DIR_UPLOAD);
}
