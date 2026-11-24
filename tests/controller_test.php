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
    echo "Controller checks passed\n";
} finally {
    foreach (array_diff(scandir($dir), ['.', '..']) as $name) {
        $path = $dir . '/' . $name;
        unlink($path);
    }
    rmdir($dir);
    rmdir(DIR_UPLOAD);
}
