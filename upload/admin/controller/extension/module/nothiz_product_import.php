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
        self::REUSE_META . '.tmp', 'job.json', 'job.json.tmp', 'job.tmp', 'archive-scan.json', 'archive-scan.json.tmp', 'paused',
    ];
    private const UPLOAD_FILES = ['workbook' => 'workbook.xlsx', 'images' => 'images.zip'];

    public function index() {
        $text = $this->language->load(self::ROUTE);
        require_once DIR_SYSTEM . 'library/nothiz_product_import/files.php';
        $text['text_excel_limit'] = sprintf($text['text_excel_limit'], NothizProductImportFiles::WORKBOOK_BYTES / 1048576);
        $text['text_zip_limit'] = sprintf($text['text_zip_limit'], NothizProductImportFiles::IMAGE_ARCHIVE_BYTES / 1048576);
        $this->document->setTitle($text['heading_title']);
        $this->document->addStyle('view/stylesheet/nothiz_product_import.css?v=5');
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
            } catch (NothizProductImportIssue $exception) {
                $error = $this->issueText($exception->issue());
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
        $paused = $job !== null && !$summary['done'] && is_file($this->jobDirectory($jobId) . '/paused');
        $phase = $job === null || $error ? 1 : ($paused || $summary['started'] ? ($summary['done'] ? 4 : 3) : 2);
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

        $pending = $this->session->data[self::SESSION_UPLOAD] ?? [];
        $pendingScan = $canModify && ($pending['expires'] ?? 0) > time()
            && is_file($this->jobDirectory($pending['id']) . '/archive-scan.json') ? $pending['id'] : '';
        $data = [
            'text' => $text,
            'error' => $error,
            'action' => $this->link(self::ROUTE),
            'begin_upload_url' => $this->link(self::ROUTE . '/beginUpload'),
            'upload_chunk_url' => $this->link(self::ROUTE . '/uploadChunk'),
            'plan_upload_url' => $this->link(self::ROUTE . '/planUpload'),
            'batch_url' => $this->link(self::ROUTE . '/batch'),
            'paused' => $paused,
            'pause_url' => $this->link(self::ROUTE . '/pauseImport'),
            'scan_url' => $this->link(self::ROUTE . '/checkArchive'),
            'pending_scan' => $pendingScan,
            'outer_products' => !empty($job['outer_products']),
            'strip_products' => !empty($job['strip_products']),
            'warnings' => array_map(function ($issue) { return $this->issueText($issue); }, $job['warnings'] ?? []),
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
            'rows' => array_map(function ($row) {
                $row['message'] = $this->rowMessage($row);
                return $row;
            }, array_slice($rows, 0, 100)),
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

    public function pauseImport() {
        $this->language->load(self::ROUTE);
        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->authorized('modify')) {
            $this->sendJson(['error' => $this->language->get('error_permission')]);
            return;
        }
        try {
            $id = $this->requestJobId();
            $job = $this->readJob($id);
            // Signal outside the batch lock so the running request can stop after its current row.
            if (!$this->summary($job)['done'] && @file_put_contents($this->jobDirectory($id) . '/paused', '1') !== 1) {
                throw new RuntimeException($this->language->get('error_storage'));
            }
            $this->sendJson(['requested' => true]);
        } catch (RuntimeException $exception) {
            $this->sendJson(['error' => $exception->getMessage()]);
        }
    }

    private function requestJobId(): string {
        $id = $this->request->post['job'] ?? '';
        if (!is_string($id) || $id === '' || $id !== ($this->session->data[self::SESSION_JOB] ?? '')) {
            throw new RuntimeException($this->language->get('error_job'));
        }
        return $id;
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
            $lock = $this->lockUpload($directory, true);
            if ($lock === false) {
                $this->sendJson(['busy' => true]);
                return;
            }

            try {
                $job = $this->readJob($jobId);
                if (is_file($directory . '/archive-scan.json')) {
                    $this->sendJson(['error' => $this->language->get('error_scan_incomplete')]);
                    return;
                }
                $pausedPath = $directory . '/paused';
                if (($this->request->post['resume'] ?? '') === '1' && is_file($pausedPath) && !@unlink($pausedPath)) {
                    throw new RuntimeException($this->language->get('error_storage'));
                }
                $deferred = false;
                $paused = false;
                $from = $job['cursor'];
                $end = min($from + 20, count($job['items']));
                $deadline = microtime(true) + 5.0;
                $updates = [];
                for ($i = $from; $i < $end; ++$i) {
                    clearstatcache(true, $pausedPath);
                    if (is_file($pausedPath)) {
                        $paused = true;
                        break;
                    }
                    $item = &$job['items'][$i];
                    if ($item['status'] === 'ready') {
                        try {
                            $result = $this->model_extension_module_nothiz_product_import->importItem(
                                $item, $directory . '/images.zip', substr(hash('sha256', 'images:' . $jobId), 0, 32)
                            );
                            if ($result['status'] === 'deferred') {
                                $deferred = true;
                                unset($item);
                                break;
                            }
                            foreach (['status', 'issue', 'message', 'issues'] as $field) {
                                unset($item[$field]);
                                if (isset($result[$field])) {
                                    $item[$field] = $result[$field];
                                }
                            }
                            if (!empty($result['existing_image'])) {
                                $item['existing_image'] = true;
                            }
                        } catch (NothizProductImportIssue $exception) {
                            $this->logError($exception, $item['row']);
                            unset($item['message'], $item['issues']);
                            $item['status'] = 'failed';
                            $item['issue'] = $exception->issue();
                        } catch (Throwable $exception) {
                            $this->logError($exception, $item['row']);
                            unset($item['message'], $item['issues']);
                            $item['status'] = 'failed';
                            $item['issue'] = ['code' => 'unexpected_row', 'params' => []];
                        }
                        $job['started'] = true;
                        $updates[] = [
                            'row' => $item['row'], 'status' => $item['status'], 'message' => $this->rowMessage($item),
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
                clearstatcache(true, $pausedPath);
                $paused = ($paused || is_file($pausedPath)) && !$summary['done'];
                if ($summary['done']) {
                    @unlink($pausedPath);
                    @unlink($directory . '/workbook.xlsx');
                }
                $this->sendJson($summary + ['updated' => $updates, 'paused' => $paused, 'deferred' => $deferred]);
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
        fputcsv($stream, [$this->language->get('column_row'), 'SKU', $this->language->get('column_title'),
            $this->language->get('column_status'), $this->language->get('column_message')], ',', '"', $escape);
        foreach ($job['items'] as $item) {
            $values = [];
            $item['status'] = $this->language->get('status_' . $item['status']);
            $item['message'] = $this->rowMessage($item);
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
        if (!@mkdir($directory, 0700) || !$this->protectJobDirectory($directory)) {
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
        } catch (NothizProductImportIssue $exception) {
            $this->sendJson(['error' => $this->issueText($exception->issue())]);
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

        $metadata = '{}';
        $temporary = $marker . '.tmp';
        if (@file_put_contents($temporary, $metadata) !== strlen($metadata)
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

    public function checkArchive() {
        $this->language->load(self::ROUTE);
        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->authorized('modify')) {
            $this->sendJson(['error' => $this->language->get('error_permission')]);
            return;
        }

        try {
            $this->requireImportReaders();
            $recheck = isset($this->request->post['job']);
            $strip = $recheck && ($this->request->post['strip_products'] ?? '') === '1';
            if ($recheck) {
                $jobId = $this->requestJobId();
            } else {
                $upload = $this->stagedUpload($this->request->post['upload'] ?? '');
                $jobId = $upload['id'];
            }
            $directory = $this->jobDirectory($jobId);
            $lock = $this->lockUpload($directory, true);
            if ($lock === false) {
                $this->sendJson(['busy' => true]);
                return;
            }

            try {
                $archive = $directory . '/images.zip';
                if ($recheck) {
                    $job = $this->readJob($jobId);
                    if ($this->summary($job)['started'] || empty($job['outer_products'])
                        || !is_file($archive) || !is_file($directory . '/workbook.xlsx')) {
                        throw new RuntimeException($this->language->get('error_job'));
                    }
                } elseif (time() >= $upload['expires'] || empty($upload['needs_images'])
                    || is_file($directory . '/job.json')) {
                    throw new RuntimeException($this->language->get('error_upload_expired'));
                }

                $saved = $this->readScan($directory);
                if ($saved === null || ($saved['strip'] ?? null) !== $strip) {
                    if (!$recheck) {
                        $this->verifyArchiveUpload($upload, $directory);
                    }
                    $saved = ['strip' => $strip, 'state' => null];
                    $this->writeState($directory . '/archive-scan.json', $saved);
                }

                if (empty($saved['done'])) {
                    $saved = NothizProductImportFiles::scanImages($archive, $saved['state'], DIR_IMAGE, $strip) + ['strip' => $strip];
                    $this->writeState($directory . '/archive-scan.json', $saved);
                }
                if ($recheck && $saved['done']) {
                    $rows = NothizProductImportFiles::readWorkbook($directory . '/workbook.xlsx');
                    $this->applyArchiveIndex($job, $rows, $saved);
                    $this->writeJob($directory, $job);
                    @unlink($directory . '/archive-scan.json');
                }
                $this->sendJson(['done' => $saved['done'], 'processed' => $saved['processed'], 'total' => $saved['total']]);
            } finally {
                fclose($lock);
            }
        } catch (NothizProductImportIssue $exception) {
            $this->sendJson(['error' => $this->issueText($exception->issue())]);
        } catch (RuntimeException $exception) {
            $this->sendJson(['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $this->logError($exception);
            $this->sendJson(['error' => $this->language->get('error_preflight')]);
        }
    }

    private function verifyArchiveUpload(array $upload, string $directory): void {
        $archive = $directory . '/images.zip';
        $reused = is_file($directory . '/' . self::REUSE_META);
        if (!isset($upload['archive_sha']) || !is_file($archive) || (!$reused
            && !NothizProductImportStagedUpload::complete($archive, $upload['files']['images']['bytes']))) {
            throw new RuntimeException($this->language->get('error_upload_incomplete'));
        }
        if (!$reused) {
            $actual = @hash_file('sha256', $archive);
            if (!is_string($actual) || !hash_equals($upload['archive_sha'], $actual)) {
                throw new RuntimeException($this->language->get('error_archive_checksum'));
            }
        }
    }

    private function readScan(string $directory): ?array {
        $content = @file_get_contents($directory . '/archive-scan.json');
        $scan = $content === false ? null : json_decode($content, true);
        return is_array($scan) && isset($scan['strip']) && array_key_exists('state', $scan) ? $scan : null;
    }

    private function applyArchiveIndex(array &$job, array $rows, array $scan): void {
        $index = $scan['index'];
        $this->load->model(self::ROUTE);
        $job['items'] = $this->model_extension_module_nothiz_product_import->preflight(
            $rows, $index['groups'], true, $index['issues']
        );
        $job['cursor'] = $this->summary($job)['ready'] ? 0 : count($job['items']);
        $job['outer_products'] = $index['outer_products'];
        $job['strip_products'] = $scan['strip'];
        $job['warnings'] = $index['warnings'];
    }

    private function preview() {
        $this->requireImportReaders();
        $upload = $this->stagedUpload($this->request->post['staged_upload'] ?? '');
        $jobId = $upload['id'];
        $directory = $this->jobDirectory($jobId);
        $lock = $this->lockUpload($directory);
        $saved = false;
        try {
            if (time() >= $upload['expires'] || is_file($directory . '/job.json')) {
                throw new RuntimeException($this->language->get('error_upload_expired'));
            }
            if (!isset($upload['needs_images'])) {
                throw new RuntimeException($this->language->get('error_upload_incomplete'));
            }

            $rows = $this->uploadedWorkbook($upload, $directory);
            $job = ['cursor' => 0, 'workbook_name' => $upload['files']['workbook']['name'], 'images_name' => ''];
            $archive = $directory . '/images.zip';
            if ($upload['needs_images']) {
                $scan = $this->readScan($directory);
                if (empty($scan['done'])) {
                    throw new RuntimeException($this->language->get('error_scan_incomplete'));
                }
                $this->applyArchiveIndex($job, $rows, $scan);
                $this->archiveCache()->remember($upload['archive_sha'], $archive, $scan['index']['groups']);
                $job['image_source'] = is_file($directory . '/' . self::REUSE_META) ? 'cache' : 'upload';
                $job['images_name'] = $upload['files']['images']['name'];
            } else {
                $this->load->model(self::ROUTE);
                $job['items'] = $this->model_extension_module_nothiz_product_import->preflight($rows, [], false);
                if (in_array('awaiting_images', array_column($job['items'], 'status'), true)) {
                    throw new RuntimeException($this->language->get('error_catalog_changed'));
                }
                $job['cursor'] = count($job['items']);
                $job['image_source'] = 'catalog';
            }

            $this->writeJob($directory, $job);
            $saved = true;
            foreach (['workbook.xlsx.ranges', 'images.zip.ranges', self::REUSE_META, 'archive-scan.json'] as $entry) {
                @unlink($directory . '/' . $entry);
            }
            if (empty($job['outer_products'])) {
                @unlink($directory . '/workbook.xlsx');
                if (!$this->summary($job)['ready']) {
                    @unlink($archive);
                }
            }
            $this->session->data[self::SESSION_JOB] = $jobId;
        } finally {
            if ($saved) {
                unset($this->session->data[self::SESSION_UPLOAD]);
            }
            fclose($lock);
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

    private function lockUpload($directory, bool $nonblocking = false) {
        $path = $directory . '/lock';
        $lock = $this->isJobDirectory($directory) && !is_link($path) ? @fopen($path, 'c') : false;
        if ($lock === false) {
            throw new RuntimeException($this->language->get('error_storage'));
        }
        if (!flock($lock, $nonblocking ? LOCK_EX | LOCK_NB : LOCK_EX)) {
            fclose($lock);
            if ($nonblocking) {
                return false;
            }
            throw new RuntimeException($this->language->get('error_storage'));
        }
        if (!$this->protectJobDirectory($directory)) {
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
        $directory = $this->jobDirectory($jobId);
        $file = $directory . '/job.json';
        if (!$this->isJobDirectory($directory) || is_link($file) || !is_file($file)
            || filemtime($file) < time() - self::JOB_AGE || !$this->protectJobDirectory($directory)) {
            throw new RuntimeException($this->language->get('error_job'));
        }
        $job = json_decode(file_get_contents($file), true);
        if (!is_array($job) || !isset($job['items'], $job['cursor'])) {
            throw new RuntimeException($this->language->get('error_job'));
        }
        return $job;
    }

    private function writeJob($directory, $job) {
        $this->writeState($directory . '/job.json', $job);
    }

    private function writeState(string $path, array $data): void {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $temporaryPath = $path . '.tmp';
        if ($json === false || @file_put_contents($temporaryPath, $json) !== strlen($json)
            || !@rename($temporaryPath, $path)) {
            throw new RuntimeException($this->language->get('error_storage'));
        }
    }

    private function pruneExpiredJobs() {
        $pattern = rtrim(DIR_UPLOAD, '/\\') . '/nothiz_product_import_*';
        foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $directory) {
            $stateFile = $directory . '/job.json';
            if (!preg_match('/\Anothiz_product_import_[0-9a-f]{32}\z/', basename($directory))
                || !$this->isJobDirectory($directory) || is_link($stateFile)
                || filemtime(is_file($stateFile) ? $stateFile : $directory) >= time() - self::JOB_AGE) {
                continue;
            }

            $lock = !is_link($directory . '/lock') ? @fopen($directory . '/lock', 'c') : false;
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
                foreach ($this->temporaryFiles($directory) as $name) {
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

    private function issueText(array $issue, string $fallback = ''): string {
        $code = $issue['code'] ?? '';
        $key = 'issue_' . $code;
        $message = $this->language->get($key);
        if ($code === '' || !is_string($message) || $message === $key) {
            return $fallback !== '' ? $fallback : $this->language->get('error_row');
        }
        try {
            $params = $issue['params'] ?? [];
            if ($code === 'field_length' && ($params[0] ?? '') === 'Category folder name') {
                $params[0] = $this->language->get('text_category_folder');
            }
            $formatted = vsprintf($message, $params);
            return is_string($formatted) ? $formatted : $message;
        } catch (Throwable $exception) {
            return $fallback !== '' ? $fallback : $this->language->get('error_row');
        }
    }

    private function rowMessage(array $row): string {
        if (!empty($row['issues'])) {
            return implode("\n", array_map(function ($issue) {
                return $this->issueText($issue);
            }, $row['issues']));
        }
        $previous = (string)($row['message'] ?? '');
        return isset($row['issue']) ? $this->issueText($row['issue'], $previous) : $previous;
    }

    private function isJobDirectory(string $path): bool {
        $root = realpath(DIR_UPLOAD);
        $directory = realpath($path);
        return $root !== false && $directory !== false && !is_link($path)
            && is_dir($directory) && dirname($directory) === $root && basename($directory) === basename($path);
    }

    private function protectJobDirectory(string $path): bool {
        require_once DIR_SYSTEM . 'library/nothiz_product_import/private_storage.php';
        return $this->isJobDirectory($path) && NothizProductImportPrivateStorage::protect($path);
    }

    private function temporaryFiles(string $directory): array {
        $files = self::TEMP_FILES;
        foreach (@scandir($directory) ?: [] as $name) {
            if (preg_match('/\\Arow-[1-9][0-9]*\\.receipt(?:\\.tmp)?\\z/', $name)) {
                $files[] = $name;
            }
        }
        return array_merge($files, ['index.html', '.htaccess']);
    }

    private function deleteJob($directory) {
        foreach (array_merge($this->temporaryFiles($directory), ['lock']) as $name) {
            $path = $directory . '/' . $name;
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    private function summary($job) {
        $counts = ['ready' => 0, 'created' => 0, 'skipped' => 0, 'duplicate' => 0, 'failed' => 0];
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
        foreach (['ready', 'created', 'failed', 'duplicate', 'skipped'] as $state) {
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
