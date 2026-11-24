<?php
class Controller {
    private $registry;
    public function __construct($registry) { $this->registry = $registry; }
    public function __get($name) { return $this->registry[$name]; }
}

function check($value, $message) {
    if (!$value) {
        throw new RuntimeException($message);
    }
}

require dirname(__DIR__) . '/upload/admin/controller/extension/module/nothiz_product_import.php';

$module = 'nothiz_product_import';
$moduleRoute = 'extension/module/' . $module;
$managerRoute = 'extension/extension/module';
$menuId = 'menu-nothiz-product-import';
$session = (object) ['data' => ['user_token' => 'test-token']];
$request = (object) ['get' => ['user_token' => 'test-token', 'extension' => $module]];
$user = new class {
    public $permissions = [];
    public function hasPermission($type, $route) { return in_array($route, $this->permissions[$type] ?? [], true); }
};
$event = new class {
    public $events = [];
    public function addEvent($code, $trigger, $action) { $this->events[$code][] = [$trigger, $action]; }
    public function deleteEventByCode($code) { unset($this->events[$code]); }
};
$controller = new ControllerExtensionModuleNothizProductImport([
    'session' => $session,
    'request' => $request,
    'user' => $user,
    'load' => new class {
        public function model($route) { check($route === 'setting/event', 'Use the stock event model'); }
    },
    'model_setting_event' => $event,
    'language' => new class {
        private $key;
        public function load($route, $key = '') {
            check($route === 'extension/module/nothiz_product_import' && $key !== '', 'Use the module language container');
            $this->key = $key;
        }
        public function get($key) {
            check($key === $this->key, 'Read the module language container');
            return new class {
                public function get($key) {
                    check($key === 'text_menu', 'Read the localized menu label');
                    return '商品导入';
                }
            };
        }
    },
    'url' => new class {
        public function link($route, $query, $secure) {
            check($secure, 'Use the secure admin URL');
            return 'https://localhost/admin/index.php?route=' . $route . '&amp;' . $query;
        }
    },
]);

check(is_callable([$controller, 'install']) && is_callable([$controller, 'uninstall']), 'Provide OpenCart lifecycle callbacks');
$request->get['route'] = $managerRoute . '/install';
$user->permissions['modify'] = [$moduleRoute];
$controller->install();
check(!$event->events, 'Importer modify permission alone cannot install the sidebar event');

$user->permissions['modify'] = [$managerRoute];
$request->get['route'] = $moduleRoute . '/install';
$controller->install();
check(!$event->events, 'Direct module requests cannot install the sidebar event');

$request->get['route'] = $managerRoute . '/install';
$request->get['user_token'] = 'wrong';
$controller->install();
check(!$event->events, 'Installation requires the current admin token');

$request->get['user_token'] = 'test-token';
$controller->install();
$controller->install();
check(count($event->events[$module] ?? []) === 1, 'Repeated installation leaves one event');
[$trigger, $action] = $event->events[$module][0];
check($trigger === 'admin/view/common/column_left/before', 'Use the native sidebar view event');
check(strpos($action, $moduleRoute . '/') === 0, 'Event calls the module controller');
$callback = [$controller, basename($action)];
check(is_callable($callback) && $callback() === null, 'Direct event requests must be harmless');

$route = 'common/column_left';
$data = ['menus' => [['id' => 'menu-dashboard'], ['id' => 'menu-catalog'], ['id' => 'menu-sale']]];
$sidebar = $data;
$code = '';
$args = [&$route, &$data, &$code];
call_user_func_array($callback, $args);
check($data === $sidebar, 'Hide the menu without module access permission');
$user->permissions['access'] = [$moduleRoute];
$request->get['user_token'] = 'wrong';
call_user_func_array($callback, $args);
check($data === $sidebar, 'Hide the menu on a token mismatch');
$request->get['user_token'] = 'test-token';
check(call_user_func_array($callback, $args) === null, 'Keep rendering the stock sidebar view');
call_user_func_array($callback, $args);
check(array_column($data['menus'], 'id') === ['menu-dashboard', 'menu-catalog', $menuId, 'menu-sale'],
    'Insert one top-level menu after Catalog');
check($data['menus'][2]['name'] === '商品导入' && $data['menus'][2]['children'] === [], 'Render the localized link as a leaf');
check(html_entity_decode($data['menus'][2]['href']) === 'https://localhost/admin/index.php?route=' . $moduleRoute . '&user_token=test-token',
    'Link to the module with the current admin token');

$data['menus'] = [['id' => 'menu-dashboard'], ['id' => 'menu-sale']];
call_user_func_array($callback, $args);
check(array_column($data['menus'], 'id') === ['menu-dashboard', $menuId, 'menu-sale'], 'Use Dashboard when Catalog is absent');
$data['menus'] = [['id' => 'menu-sale']];
call_user_func_array($callback, $args);
check(array_column($data['menus'], 'id') === ['menu-sale', $menuId], 'Append when neither stock anchor is present');

$request->get['route'] = $moduleRoute . '/uninstall';
$controller->uninstall();
check(isset($event->events[$module]), 'Direct module requests cannot uninstall the sidebar event');
$request->get['route'] = $managerRoute . '/uninstall';
$event->events['other_extension'] = [['other-trigger', 'other-action']];
$controller->uninstall();
check(array_keys($event->events) === ['other_extension'], 'Uninstall deletes only this module’s event');

echo "Sidebar checks passed\n";
