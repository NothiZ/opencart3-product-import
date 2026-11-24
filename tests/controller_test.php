<?php
// A standalone check for the shell of the module, with an OpenCart-style registry.
class Controller {
    private $registry;
    public function __construct($registry) { $this->registry = $registry; }
    public function __get($name) { return $this->registry[$name]; }
}

class TestResponse {
    public $output;
    public function addHeader($header) {}
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
    public function importItem($item, $zipPath, $job) {
        $this->calls[] = $item['sku'];
        return ['status' => 'created', 'message' => 'Product created'];
    }
};
$controller = new ControllerExtensionModuleNothizProductImport([
    'session' => $session,
    'request' => $request,
    'response' => $response,
    'load' => new class { public function model($route) {} },
    'user' => new class { public function hasPermission($type, $route) { return true; } },
    'language' => new class { public function load($route) {} public function get($key) { return $key; } },
    'log' => new class { public function write($message) { throw new RuntimeException($message); } },
    'model_extension_module_nothiz_product_import' => $model,
]);

try {
    $controller->batch();
    check(isset(json_decode($response->output, true)['error']), 'Bad token must be rejected');
    check(!$model->calls, 'Rejected request must not write');
    $request->get['user_token'] = 'admin-token';
    $request->post['job'] = str_repeat('b', 32);
    $controller->batch();
    check(isset(json_decode($response->output, true)['error']), 'A different job must be rejected');
    $request->post['job'] = $job;
    $controller->batch();
    $result = json_decode($response->output, true);
    check($result['done'] && $model->calls === ['NEW'], 'Only ready rows should import');
    $controller->batch();
    check($model->calls === ['NEW'], 'Replayed batch must not create another product');
    echo "Controller checks passed\n";
} finally {
    foreach (glob($dir . '/*') as $path) {
        unlink($path);
    }
    rmdir($dir);
    rmdir(DIR_UPLOAD);
}
