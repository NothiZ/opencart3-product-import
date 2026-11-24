<?php
class ControllerExtensionModuleNothizProductImport extends Controller {
    private const CODE = 'nothiz_product_import';
    private const ROUTE = 'extension/module/' . self::CODE;
    private const MODULE_MANAGER = 'extension/extension/module';
    private const MENU_ID = 'menu-nothiz-product-import';
    private const SESSION_JOB = 'nothiz_product_import_job';
    private const SESSION_UPLOAD = 'nothiz_product_import_upload';
    private const JOB_AGE = 86400;
    private const REUSE_META = 'reused-images.json';
    private const TEMP_FILES = [
        'workbook.xlsx', 'workbook.xlsx.ranges', 'workbook.xlsx.ranges.tmp', 'images.zip',
        'images.zip.ranges', 'images.zip.ranges.tmp', 'images.zip.cache-tmp', self::REUSE_META,
        self::REUSE_META . '.tmp', 'job.json', 'job.tmp',
    ];
    private const UPLOAD_FILES = ['workbook' => 'workbook.xlsx', 'images' => 'images.zip'];

    public function index() {
        $text = $this->language->load(self::ROUTE);
        require_once DIR_SYSTEM . 'library/nothiz_product_import/files.php';
        $text['text_excel_limit'] = sprintf($text['text_excel_limit'], NothizProductImportFiles::WORKBOOK_BYTES / 1048576);
        $text['text_zip_limit'] = sprintf($text['text_zip_limit'], NothizProductImportFiles::IMAGE_ARCHIVE_BYTES / 1048576);
        $this->document->setTitle($text['heading_title']);
        $this->document->addStyle('view/stylesheet/nothiz_product_import.css?v=4');
        $error = '';
        $canModify = $this->authorized('modify');

        if (($this->request->server['REQUEST_METHOD'] ?? '') === 'POST') {
            try {
                if (!$canModify) {
                    throw new RuntimeException($text['error_permission']);
                }
                $this->preview();
                $this->response->redirect($this->link(self::ROUTE));
                return;
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                $this->logError($exception);
                $error = $text['error_preflight'];
            }
        }

        $jobId = $this->session->data[self::SESSION_JOB] ?? '';
        $job = null;
        if ($this->authorized('access') && $jobId !== '') {
            try {
                $job = $this->readJob($jobId);
            } catch (RuntimeException $exception) {
                $error = $error ?: $text['error_job'];
            }
        }

        $summary = $job === null ? [] : $this->summary($job);
        $phase = $job === null || $error ? 1 : (!$summary['started'] ? 2 : ($summary['done'] ? 4 : 3));
        $filters = $job === null ? [] : $this->resultFilters($summary, $text);
        $activeFilter = $this->request->get['status'] ?? 'all';
        if (!is_string($activeFilter) || !isset($filters[$activeFilter])) {
            $activeFilter = 'all';
        }
        $rows = $job['items'] ?? [];
        if ($activeFilter !== 'all') {
            $rows = array_values(array_filter($rows, static function ($row) use ($activeFilter) {
                return $row['status'] === $activeFilter;
            }));
        }

        $data = [
            'text' => $text,
            'error' => $error,
            'action' => $this->link(self::ROUTE),
            'begin_upload_url' => $this->link(self::ROUTE . '/beginUpload'),
            'upload_chunk_url' => $this->link(self::ROUTE . '/uploadChunk'),
            'plan_upload_url' => $this->link(self::ROUTE . '/planUpload'),
            'batch_url' => $this->link(self::ROUTE . '/batch'),
            'report_url' => $this->link(self::ROUTE . '/report'),
            'products_url' => $this->user->hasPermission('access', 'catalog/product') ? $this->link('catalog/product') : '',
            'home_url' => $this->link('common/dashboard'),
            'can_modify' => $canModify,
            'job_id' => $jobId,
            'job' => $job !== null,
            'workbook_name' => $job['workbook_name'] ?? '',
            'images_name' => $job['images_name'] ?? '',
            'image_source' => $job['image_source'] ?? 'upload',
            'phase' => $phase,
            'summary' => $summary,
            'filters' => $filters,
            'active_filter' => $activeFilter,
            'rows' => array_slice($rows, 0, 100),
            'more_rows' => count($rows) > 100,
            'header' => $this->load->controller('common/header'),
            'column_left' => $this->load->controller('common/column_left'),
            'footer' => $this->load->controller('common/footer'),
        ];

        $this->response->setOutput($this->load->view(self::ROUTE, $data));
    }

    public function install() {
        $this->changeSidebarEvent('install');
    }

    public function uninstall() {
        $this->changeSidebarEvent('uninstall');
    }

    public function addSidebarMenu(&$route = '', &$data = null) {
        if ($route !== 'common/column_left' || !is_array($data['menus'] ?? null) || !$this->authorized('access')) {
            return;
        }

        $menuIds = array_column($data['menus'], 'id');
        if (in_array(self::MENU_ID, $menuIds, true)) {
            return;
        }

        // The current group may not have access to Catalog.
        $after = array_search('menu-catalog', $menuIds, true);
        if ($after === false) {
            $after = array_search('menu-dashboard', $menuIds, true);
        }

        $this->language->load(self::ROUTE, self::CODE);
        $menu = [
            'id' => self::MENU_ID,
            'icon' => 'fa-upload',
            'name' => htmlspecialchars($this->language->get(self::CODE)->get('text_menu'), ENT_QUOTES, 'UTF-8'),
            'href' => htmlspecialchars($this->link(self::ROUTE), ENT_QUOTES, 'UTF-8'),
            'children' => [],
        ];
        array_splice($data['menus'], $after === false ? count($data['menus']) : $after + 1, 0, [$menu]);
    }

    private function changeSidebarEvent($operation) {
        // OpenCart calls these callbacks from its module manager, which owns the group permissions.
        if (($this->request->get['route'] ?? '') !== self::MODULE_MANAGER . '/' . $operation
            || ($this->request->get['extension'] ?? '') !== self::CODE
            || !$this->hasRequestToken() || !$this->user->hasPermission('modify', self::MODULE_MANAGER)) {
            return;
        }

        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode(self::CODE);
        if ($operation === 'install') {
            $this->model_setting_event->addEvent(self::CODE, 'admin/view/common/column_left/before', self::ROUTE . '/addSidebarMenu');
        }
    }

    public function batch() {
        $this->language->load(self::ROUTE);
        $jobId = $this->request->post['job'] ?? '';
        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->authorized('modify')) {
            $this->sendJson(['error' => $this->language->get('error_permission')]);
            return;
        }

        if (!is_string($jobId) || $jobId !== ($this->session->data[self::SESSION_JOB] ?? '')) {
            $this->sendJson(['error' => $this->language->get('error_job')]);
            return;
        }

        try {
            require_once DIR_SYSTEM . 'library/nothiz_product_import/files.php';
            $this->load->model(self::ROUTE);
            $directory = $this->jobDirectory($jobId);
            $lock = is_dir($directory) ? fopen($directory . '/lock', 'c') : false;
            if ($lock === false) {
                throw new RuntimeException($this->language->get('error_job'));
            }

            try {
                // OpenCart sessions do not guarantee that two browser requests execute serially.
                if (!flock($lock, LOCK_EX | LOCK_NB)) {
                    $this->sendJson(['busy' => true]);
                    return;
                }

                $job = $this->readJob($jobId);
                $from = $job['cursor'];
                $end = min($from + 20, count($job['items']));
                $deadline = microtime(true) + 5.0;
                $updates = [];
                for ($i = $from; $i < $end; ++$i) {
                    $item = &$job['items'][$i];
                    if ($item['status'] === 'ready') {
                        $job['started'] = true;
                        try {
                            $result = $this->model_extension_module_nothiz_product_import->importItem(
                                $item, $directory . '/images.zip', substr(hash('sha256', 'images:' . $jobId), 0, 32)
                            );
                            $item['status'] = $result['status'];
                            $item['message'] = $result['message'];
                            if (!empty($result['existing_image'])) {
                                $item['existing_image'] = true;
                            }
                        } catch (Throwable $exception) {
                            $this->logError($exception, $item['row']);
                            $item['status'] = 'failed';
                            $item['message'] = $this->language->get('error_row');
                        }
                        $updates[] = [
                            'row' => $item['row'], 'status' => $item['status'], 'message' => $item['message'],
                        ];
                    }
                    unset($item);
                    $job['cursor'] = $i + 1;
                    if (microtime(true) >= $deadline) {
                        break;
                    }
                }
                // Save the cursor only after the product model finishes a row.
                $this->writeJob($directory, $job);
                $summary = $this->summary($job);
                if ($summary['done'] && is_file($directory . '/images.zip')) {
                    unlink($directory . '/images.zip');
                }
                $this->sendJson($summary + ['updated' => $updates]);
            } finally {
                fclose($lock);
            }
        } catch (Throwable $exception) {
            $this->logError($exception);
            $this->sendJson(['error' => $this->language->get('error_batch')]);
        }
    }

    public function report() {
        $this->language->load(self::ROUTE);
        if (!$this->authorized('access')) {
            $this->sendJson(['error' => $this->language->get('error_permission')]);
            return;
        }

        try {
            $job = $this->readJob($this->session->data[self::SESSION_JOB] ?? '');
        } catch (RuntimeException $exception) {
            $this->sendJson(['error' => $this->language->get('error_job')]);
            return;
        }

        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        $escape = PHP_VERSION_ID >= 70400 ? '' : '\\';
        fputcsv($stream, ['Row', 'SKU', 'Title', 'Status', 'Message'], ',', '"', $escape);
        foreach ($job['items'] as $item) {
            $values = [];
            foreach (['row', 'sku', 'title', 'status', 'message'] as $key) {
                $value = (string)$item[$key];
                // Spreadsheet applications interpret some imported text as formulas when opening CSV.
                $values[] = preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $value) ? "'" . $value : $value;
            }
            fputcsv($stream, $values, ',', '"', $escape);
        }

        rewind($stream);
        $this->response->addHeader('Content-Type: text/csv; charset=UTF-8');
        $this->response->addHeader('Content-Disposition: attachment; filename="product-import.csv"');
        $this->response->setOutput(stream_get_contents($stream));
        fclose($stream);
    }

    public function beginUpload() {
        $this->language->load(self::ROUTE);
        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->authorized('modify')) {
            $this->sendJson(['error' => $this->language->get('error_permission')]);
            return;
        }

        require_once DIR_SYSTEM . 'library/nothiz_product_import/files.php';
        require_once DIR_SYSTEM . 'library/nothiz_product_import/staged_upload.php';
        $upload = [
            'id' => bin2hex(random_bytes(16)),
            'expires' => time() + self::JOB_AGE,
            'files' => [],
        ];
        $limits = [
            'workbook' => NothizProductImportFiles::WORKBOOK_BYTES,
            'images' => NothizProductImportFiles::IMAGE_ARCHIVE_BYTES,
        ];
        foreach (self::UPLOAD_FILES as $kind => $file) {
            $rawName = $this->request->post[$kind . '_name'] ?? '';
            $bytes = filter_var($this->request->post[$kind . '_size'] ?? null, FILTER_VALIDATE_INT);
            if ($kind === 'images' && $rawName === '' && $bytes === false) {
                continue;
            }
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            if (!is_string($rawName) || strlen($rawName) > 1024 || $bytes === false || $bytes < 1 || $bytes > $limits[$kind]
                || strtolower(pathinfo($rawName, PATHINFO_EXTENSION)) !== $extension) {
                $error = sprintf($this->language->get('error_file_size'), strtoupper($extension), $limits[$kind] / 1048576);
                if ($kind === 'images') {
                    $upload['image_error'] = $error;
                    continue;
                }
                $this->sendJson(['error' => $error]);
                return;
            }
            $upload['files'][$kind] = [
                'name' => basename(str_replace('\\', '/', html_entity_decode($rawName, ENT_QUOTES, 'UTF-8'))),
                'bytes' => $bytes,
            ];
        }

        $this->pruneExpiredJobs();
        $directory = $this->jobDirectory($upload['id']);
        if (!@mkdir($directory, 0700)) {
            $this->sendJson(['error' => $this->language->get('error_storage')]);
            return;
        }
        $this->session->data[self::SESSION_UPLOAD] = $upload;
        $this->sendJson(['upload' => $upload['id'], 'chunk_bytes' => NothizProductImportStagedUpload::CHUNK_BYTES]);
    }

    public function planUpload() {
        $this->language->load(self::ROUTE);
        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->authorized('modify')) {
            $this->sendJson(['error' => $this->language->get('error_permission')]);
            return;
        }

        try {
            $this->requireImportReaders();
            $upload = $this->stagedUpload($this->request->post['upload'] ?? '');
            $directory = $this->jobDirectory($upload['id']);
            $lock = $this->lockUpload($directory);
            try {
                if (time() >= $upload['expires'] || is_file($directory . '/job.json')) {
                    throw new RuntimeException($this->language->get('error_upload_expired'));
                }

                if (isset($this->request->post['archive_sha'])) {
                    $reply = $this->planArchive($upload, $directory, $this->request->post['archive_sha']);
                } else {
                    $rows = $this->uploadedWorkbook($upload, $directory);
                    $this->load->model(self::ROUTE);
                    $items = $this->model_extension_module_nothiz_product_import->preflight($rows, [], false);
                    $upload['needs_images'] = in_array('awaiting_images', array_column($items, 'status'), true);
                    if ($upload['needs_images'] && isset($upload['image_error'])) {
                        throw new RuntimeException($upload['image_error']);
                    }
                    $reply = ['needs_images' => $upload['needs_images']];
                }
                $this->session->data[self::SESSION_UPLOAD] = $upload;
            } finally {
                fclose($lock);
            }
            $this->sendJson($reply);
        } catch (RuntimeException $exception) {
            $this->sendJson(['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $this->logError($exception);
            $this->sendJson(['error' => $this->language->get('error_preflight')]);
        }
    }

    private function planArchive(array &$upload, string $directory, $sha): array {
        if (empty($upload['needs_images']) || !isset($upload['files']['images'])
            || !is_string($sha) || !preg_match('/\\A[a-f0-9]{64}\\z/', $sha)
            || (isset($upload['archive_sha']) && $upload['archive_sha'] !== $sha)) {
            throw new RuntimeException($this->language->get('error_upload_transfer'));
        }
        $upload['archive_sha'] = $sha;
        $marker = $directory . '/' . self::REUSE_META;
        $archive = $directory . '/images.zip';
        if (is_file($marker) && is_file($archive)) {
            return ['reused' => true];
        }
        // A process can exit after attaching a cache hit but before publishing its marker.
        if (is_file($archive) && !is_file($archive . '.ranges') && !@unlink($archive)) {
            throw new RuntimeException($this->language->get('error_storage'));
        }

        // Old versions retain an unconfirmed archive only in the previous job directory.
        $previous = $this->session->data[self::SESSION_JOB] ?? '';
        $legacyPath = $previous !== '' && $previous !== $upload['id']
            ? $this->jobDirectory($previous) . '/images.zip' : null;
        $cached = $this->archiveCache()->reuse($sha, $upload['files']['images']['bytes'], $archive, $legacyPath);
        if ($cached === null) {
            return ['reused' => false];
        }

        $metadata = json_encode($cached, JSON_UNESCAPED_UNICODE);
        $temporary = $marker . '.tmp';
        if ($metadata === false || @file_put_contents($temporary, $metadata) !== strlen($metadata)
            || !@rename($temporary, $marker)) {
            @unlink($temporary);
            @unlink($marker);
            @unlink($archive);
            return ['reused' => false];
        }
        return ['reused' => true];
    }

    public function uploadChunk() {
        $this->language->load(self::ROUTE);
        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->authorized('modify')) {
            $this->sendJson(['error' => $this->language->get('error_permission')]);
            return;
        }

        require_once DIR_SYSTEM . 'library/nothiz_product_import/staged_upload.php';
        try {
            $upload = $this->stagedUpload($this->request->get['upload'] ?? '');
            $kind = $this->request->get['file'] ?? '';
            $offset = filter_var($this->request->get['offset'] ?? null, FILTER_VALIDATE_INT);
            if (!is_string($kind) || !isset($upload['files'][$kind]) || $offset === false || $offset < 0) {
                throw new InvalidArgumentException();
            }
            $chunk = file_get_contents('php://input', false, null, 0, NothizProductImportStagedUpload::CHUNK_BYTES + 1);
            if ($chunk === false) {
                throw new RuntimeException($this->language->get('error_upload_transfer'));
            }

            $directory = $this->jobDirectory($upload['id']);
            $lock = $this->lockUpload($directory);
            try {
                if (time() >= $upload['expires'] || is_file($directory . '/job.json')) {
                    throw new RuntimeException($this->language->get('error_upload_expired'));
                }
                if ($kind === 'images' && (!isset($upload['archive_sha']) || is_file($directory . '/' . self::REUSE_META))) {
                    throw new RuntimeException($this->language->get('error_upload_expired'));
                }
                $next = NothizProductImportStagedUpload::append(
                    $directory . '/' . self::UPLOAD_FILES[$kind], $upload['files'][$kind]['bytes'], $offset, $chunk
                );
            } finally {
                fclose($lock);
            }
            $this->sendJson(['offset' => $next]);
        } catch (InvalidArgumentException $exception) {
            $this->sendJson(['error' => $this->language->get('error_upload_transfer')]);
        } catch (RuntimeException $exception) {
            $this->sendJson(['error' => $exception->getMessage()]);
        }
    }

    private function preview() {
        $this->requireImportReaders();
        $upload = $this->stagedUpload($this->request->post['staged_upload'] ?? '');
        $jobId = $upload['id'];
        $directory = $this->jobDirectory($jobId);
        $lock = $this->lockUpload($directory);
        $consume = false;
        $saved = false;
        try {
            if (time() >= $upload['expires'] || is_file($directory . '/job.json')) {
                throw new RuntimeException($this->language->get('error_upload_expired'));
            }
            if (!isset($upload['needs_images'])) {
                throw new RuntimeException($this->language->get('error_upload_incomplete'));
            }

            $rows = $this->uploadedWorkbook($upload, $directory);
            $this->load->model(self::ROUTE);
            $archive = $directory . '/images.zip';
            $source = 'catalog';
            if ($upload['needs_images']) {
                $marker = $directory . '/' . self::REUSE_META;
                $reused = is_file($marker);
                if (!isset($upload['archive_sha']) || !is_file($archive) || (!$reused
                    && !NothizProductImportStagedUpload::complete($archive, $upload['files']['images']['bytes']))) {
                    throw new RuntimeException($this->language->get('error_upload_incomplete'));
                }

                $consume = true;
                $sha = $upload['archive_sha'];
                if (!$reused) {
                    $actual = @hash_file('sha256', $archive);
                    if (!is_string($actual) || !hash_equals($sha, $actual)) {
                        throw new RuntimeException($this->language->get('error_archive_checksum'));
                    }
                }
                $metadata = $reused ? json_decode(file_get_contents($marker), true) : [];
                $index = $metadata['index'] ?? null;
                if (!is_array($index)) {
                    $index = NothizProductImportFiles::inspectImages($archive);
                    $this->archiveCache()->remember($sha, $archive, $index);
                }
                $items = $this->model_extension_module_nothiz_product_import->preflight($rows, $index);
                $source = $reused ? 'cache' : 'upload';
            } else {
                $consume = true;
                $items = $this->model_extension_module_nothiz_product_import->preflight($rows, [], false);
                if (in_array('awaiting_images', array_column($items, 'status'), true)) {
                    throw new RuntimeException($this->language->get('error_catalog_changed'));
                }
            }

            $job = [
                'items' => $items,
                'cursor' => 0,
                'workbook_name' => $upload['files']['workbook']['name'],
                'images_name' => $source === 'catalog' ? '' : $upload['files']['images']['name'],
                'image_source' => $source,
            ];
            $hasReady = $this->summary($job)['ready'] > 0;
            if (!$hasReady) {
                $job['cursor'] = count($items);
            }
            $this->writeJob($directory, $job);
            $saved = true;
            foreach (['workbook.xlsx', 'workbook.xlsx.ranges', 'images.zip.ranges', self::REUSE_META] as $entry) {
                if (is_file($directory . '/' . $entry)) {
                    @unlink($directory . '/' . $entry);
                }
            }
            if (!$hasReady && is_file($archive)) {
                @unlink($archive);
            }
            $this->session->data[self::SESSION_JOB] = $jobId;
        } finally {
            if ($consume) {
                unset($this->session->data[self::SESSION_UPLOAD]);
            }
            fclose($lock);
            if ($consume && !$saved) {
                $this->deleteJob($directory);
            }
        }
    }

    private function requireImportReaders() {
        if (!class_exists('ZipArchive') || !class_exists('DOMDocument') || !function_exists('mb_strlen') || !function_exists('imagecreatefromstring')) {
            throw new RuntimeException($this->language->get('error_php'));
        }
        require_once DIR_SYSTEM . 'library/nothiz_product_import/files.php';
        require_once DIR_SYSTEM . 'library/nothiz_product_import/staged_upload.php';
    }

    private function uploadedWorkbook(array $upload, string $directory): array {
        $path = $directory . '/workbook.xlsx';
        if (!NothizProductImportStagedUpload::complete($path, $upload['files']['workbook']['bytes'])) {
            throw new RuntimeException($this->language->get('error_upload_incomplete'));
        }
        $rows = NothizProductImportFiles::readWorkbook($path);
        if (!$rows) {
            throw new RuntimeException($this->language->get('error_no_rows'));
        }
        return $rows;
    }

    private function archiveCache(): NothizProductImportArchiveCache {
        require_once DIR_SYSTEM . 'library/nothiz_product_import/archive_cache.php';
        return new NothizProductImportArchiveCache(DIR_UPLOAD);
    }

    private function stagedUpload($id) {
        $upload = $this->session->data[self::SESSION_UPLOAD] ?? null;
        if (!is_string($id) || $upload === null || $id !== $upload['id'] || time() >= $upload['expires']) {
            throw new RuntimeException($this->language->get('error_upload_expired'));
        }
        return $upload;
    }

    private function lockUpload($directory) {
        $lock = is_dir($directory) ? @fopen($directory . '/lock', 'c') : false;
        if ($lock === false) {
            throw new RuntimeException($this->language->get('error_storage'));
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException($this->language->get('error_storage'));
        }
        return $lock;
    }

    private function jobDirectory($jobId) {
        if (!is_string($jobId) || !preg_match('/\A[0-9a-f]{32}\z/', $jobId)) {
            throw new RuntimeException($this->language->get('error_job'));
        }
        return rtrim(DIR_UPLOAD, '/\\') . '/nothiz_product_import_' . $jobId;
    }

    private function readJob($jobId) {
        $file = $this->jobDirectory($jobId) . '/job.json';
        if (!is_file($file) || filemtime($file) < time() - self::JOB_AGE) {
            throw new RuntimeException($this->language->get('error_job'));
        }
        $job = json_decode(file_get_contents($file), true);
        if (!is_array($job) || !isset($job['items'], $job['cursor'])) {
            throw new RuntimeException($this->language->get('error_job'));
        }
        return $job;
    }

    private function writeJob($directory, $job) {
        $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $temporaryPath = $directory . '/job.tmp';
        if ($json === false || file_put_contents($temporaryPath, $json) !== strlen($json)
            || !rename($temporaryPath, $directory . '/job.json')) {
            throw new RuntimeException($this->language->get('error_storage'));
        }
    }

    private function pruneExpiredJobs() {
        $pattern = rtrim(DIR_UPLOAD, '/\\') . '/nothiz_product_import_*';
        foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $directory) {
            $stateFile = $directory . '/job.json';
            if (!preg_match('/\Anothiz_product_import_[0-9a-f]{32}\z/', basename($directory))
                || is_link($directory)
                || filemtime(is_file($stateFile) ? $stateFile : $directory) >= time() - self::JOB_AGE) {
                continue;
            }

            $lock = @fopen($directory . '/lock', 'c');
            if ($lock === false) {
                continue;
            }
            try {
                // A stale staging directory may still be finishing an upload or publishing its preview.
                if (!flock($lock, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                clearstatcache();
                if (is_file($stateFile) && filemtime($stateFile) >= time() - self::JOB_AGE) {
                    continue;
                }
                foreach (self::TEMP_FILES as $name) {
                    if (is_file($directory . '/' . $name)) {
                        @unlink($directory . '/' . $name);
                    }
                }
            } finally {
                fclose($lock);
            }
            @unlink($directory . '/lock');
            @rmdir($directory);
        }
    }

    private function deleteJob($directory) {
        foreach (array_merge(self::TEMP_FILES, ['lock']) as $name) {
            $path = $directory . '/' . $name;
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    private function summary($job) {
        $counts = ['ready' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($job['items'] as $item) {
            ++$counts[$item['status']];
        }
        $counts['total'] = count($job['items']);
        $counts['processed'] = $job['cursor'];
        $counts['done'] = $job['cursor'] >= $counts['total'];
        $counts['started'] = $job['started'] ?? ($counts['created'] > 0 || ($job['cursor'] > 0 && $counts['ready'] > 0));
        return $counts;
    }

    private function resultFilters(array $summary, array $text): array {
        $filters = ['all' => [
            'id' => 'all', 'label' => $text['text_filter_all'], 'count' => $summary['total'], 'url' => $this->link(self::ROUTE),
        ]];
        foreach (['ready', 'created', 'failed', 'skipped'] as $state) {
            if (($state === 'created' && !$summary['started']) || ($state === 'ready' && $summary['started'] && $summary['done'])) {
                continue;
            }
            $filters[$state] = [
                'id' => $state, 'label' => $text['status_' . $state], 'count' => $summary[$state],
                'url' => $this->link(self::ROUTE, '&status=' . $state),
            ];
        }
        return $filters;
    }

    private function authorized($type) {
        return $this->hasRequestToken()
            && $this->user->hasPermission('access', self::ROUTE)
            && ($type === 'access' || $this->user->hasPermission('modify', self::ROUTE));
    }

    private function hasRequestToken() {
        $expected = $this->session->data['user_token'] ?? '';
        $provided = $this->request->get['user_token'] ?? '';
        return $expected !== '' && is_string($provided) && hash_equals($expected, $provided);
    }

    private function link($route, $extra = '') {
        return html_entity_decode($this->url->link(
            $route, 'user_token=' . rawurlencode($this->session->data['user_token'] ?? '') . $extra, true
        ), ENT_QUOTES, 'UTF-8');
    }

    private function sendJson($data) {
        $this->response->addHeader('Content-Type: application/json; charset=UTF-8');
        $this->response->setOutput(json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function logError(Throwable $exception, $row = null) {
        $location = $row === null ? '' : ' row ' . (int)$row;
        $this->log->write('Nothiz Product Import' . $location . ': ' . $exception->getMessage());
    }
}
