<?php

require_once __DIR__ . '/issue.php';

class NothizProductImportFiles {
    private const SHEET_NS = [
        'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
        'http://purl.oclc.org/ooxml/spreadsheetml/main',
    ];
    private const OFFICE_REL_NS = [
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'http://purl.oclc.org/ooxml/officeDocument/relationships',
    ];
    private const PACKAGE_REL_NS = [
        'http://schemas.openxmlformats.org/package/2006/relationships',
        'http://purl.oclc.org/ooxml/package/relationships',
    ];
    private const IMAGE_TYPES = [
        'jpg' => IMAGETYPE_JPEG,
        'jpeg' => IMAGETYPE_JPEG,
        'png' => IMAGETYPE_PNG,
        'webp' => IMAGETYPE_WEBP,
    ];
    private const XML_BYTES = 8 * 1024 * 1024;
    private const IMAGE_BYTES = 15 * 1024 * 1024;
    private const IMAGE_HEADER_BYTES = 256 * 1024;
    public const WORKBOOK_BYTES = 24 * 1024 * 1024;
    public const IMAGE_ARCHIVE_BYTES = 320 * 1024 * 1024;
    private const EXPANDED_IMAGE_ARCHIVE_BYTES = 768 * 1024 * 1024;
    public const IMPORT_IMAGE_PATH = 'catalog/nothiz_product_import';

    // Workbook reading

    /** @return array<int, array{row:int, data:array<string, string>}> */
    public static function readWorkbook(string $path): array {
        if (!class_exists('DOMDocument')) {
            throw new NothizProductImportIssue('workbook_dom_required', [], 'The PHP DOM extension is required.');
        }
        $zip = self::openArchive($path, self::WORKBOOK_BYTES);
        try {
            $workbookPath = 'xl/workbook.xml';
            if ($zip->locateName('_rels/.rels') !== false) {
                $packageRelationships = self::relationships(self::xml($zip, '_rels/.rels', 'Relationships', self::PACKAGE_REL_NS), '');
                foreach ($packageRelationships as $relationship) {
                    if ($relationship['type'] === 'officeDocument') {
                        $workbookPath = $relationship['path'];
                        break;
                    }
                }
            }

            $workbook = self::xml($zip, $workbookPath, 'workbook', self::SHEET_NS);
            $sheets = self::firstChild($workbook, 'sheets');
            $sheet = $sheets ? self::firstChild($sheets, 'sheet') : null;
            if (!$sheet) {
                throw new NothizProductImportIssue('workbook_worksheet_missing', [], 'The workbook does not contain a worksheet.');
            }

            $sheetId = '';
            foreach (self::OFFICE_REL_NS as $namespace) {
                $sheetId = $sheet->getAttributeNS($namespace, 'id');
                if ($sheetId !== '') {
                    break;
                }
            }
            $directory = dirname($workbookPath);
            $relationshipPath = ($directory === '.' ? '' : $directory . '/') . '_rels/' . basename($workbookPath) . '.rels';
            $relationships = self::relationships(self::xml($zip, $relationshipPath, 'Relationships', self::PACKAGE_REL_NS), $workbookPath);
            if (!isset($relationships[$sheetId]) || $relationships[$sheetId]['type'] !== 'worksheet') {
                throw new NothizProductImportIssue('workbook_worksheet_target', [], 'The first workbook sheet must point to a worksheet inside the XLSX file.');
            }

            unset($workbook, $sheets, $sheet);
            $strings = [];
            foreach ($relationships as $relationship) {
                if ($relationship['type'] === 'sharedStrings') {
                    $shared = self::xml($zip, $relationship['path'], 'sst', self::SHEET_NS);
                    foreach (self::children($shared, 'si') as $item) {
                        $strings[] = self::textRuns($item);
                    }
                    unset($shared, $item);
                    break;
                }
            }

            $worksheet = self::xml($zip, $relationships[$sheetId]['path'], 'worksheet', self::SHEET_NS);
            $sheetData = self::firstChild($worksheet, 'sheetData');
            if (!$sheetData) {
                throw new NothizProductImportIssue('workbook_rows_missing', [], 'The first worksheet is missing its row data.');
            }
            return self::workbookRows($sheetData, $strings);
        } finally {
            $zip->close();
        }
    }

    private static function workbookRows(DOMElement $sheetData, array $strings): array {
        $headers = [];
        $defaults = [];
        $rows = [];
        $previousRow = 0;
        $cellBytes = 0;
        foreach (self::children($sheetData, 'row') as $element) {
            $r = $element->getAttribute('r');
            $row = $r === '' ? $previousRow + 1 : (int)$r;
            if (($r !== '' && !preg_match('/^[1-9][0-9]{0,6}$/D', $r)) || $row <= $previousRow || $row > 1048576) {
                throw new NothizProductImportIssue('workbook_row_reference', [], 'The first worksheet contains an invalid row reference.');
            }
            $previousRow = $row;
            if ($row !== 1 && !$headers) {
                break;
            }

            $column = 0;
            $data = $defaults;
            $hasValues = false;
            foreach (self::children($element, 'c') as $cell) {
                $column = self::cellColumn($cell, $row, $column);
                if ($row !== 1 && !isset($headers[$column])) {
                    continue;
                }

                $value = self::cellText($cell, $strings);
                $cellBytes += strlen($value);
                if (strlen($value) > 128 * 1024 || $cellBytes > 16 * 1024 * 1024) {
                    throw new NothizProductImportIssue('workbook_cell_size', [], 'The worksheet exceeds the maximum cell text size.');
                }

                if ($row === 1) {
                    $header = strtolower(trim($value));
                    if ($header !== '') {
                        if (in_array($header, $headers, true) || count($headers) >= 256) {
                            throw new NothizProductImportIssue('workbook_headers_invalid', [], 'Worksheet headers must be unique and there may be at most 256.');
                        }
                        $headers[$column] = $header;
                    }
                } else {
                    $data[$headers[$column]] = $value;
                    $hasValues = $hasValues || trim($value) !== '';
                }
            }

            if ($row === 1) {
                self::requireHeaders($headers);
                $defaults = array_fill_keys($headers, '');
            } elseif ($hasValues) {
                if (count($rows) >= 10000) {
                    throw new NothizProductImportIssue('workbook_row_count', [], 'A workbook may contain at most 10,000 product rows.');
                }
                // Each returned row has entries for all headers, including sparse worksheet cells.
                if (count($rows) >= intdiv(250000, count($headers))) {
                    throw new NothizProductImportIssue('workbook_cell_count', [], 'The workbook may contain at most 250,000 expanded cells.');
                }
                $rows[] = ['row' => $row, 'data' => $data];
            }
        }
        if (!$headers) {
            self::requireHeaders($headers);
        }
        return $rows;
    }

    private static function requireHeaders(array $headers): void {
        $missing = array_diff(['sku', 'title', 'price', 'description'], $headers);
        if ($missing) {
            throw new NothizProductImportIssue('workbook_headers_missing', ['headers' => implode(', ', $missing)], 'The first worksheet is missing required headers.');
        }
    }

    private static function cellColumn(DOMElement $cell, int $row, int $previous): int {
        $reference = $cell->getAttribute('r');
        $column = $previous + 1;
        if ($reference !== '') {
            if (!preg_match('/^([A-Z]{1,3})([1-9][0-9]{0,6})$/D', $reference, $matches) || (int)$matches[2] !== $row) {
                throw new NothizProductImportIssue('workbook_cell_reference', ['row' => $row], 'Invalid cell reference in the worksheet.');
            }
            $column = 0;
            foreach (str_split($matches[1]) as $letter) {
                $column = $column * 26 + ord($letter) - 64;
            }
        }
        if ($column <= $previous || $column > 16384) {
            throw new NothizProductImportIssue('workbook_column_order', ['row' => $row], 'Invalid column order in the worksheet.');
        }
        return $column;
    }

    private static function cellText(DOMElement $cell, array $strings): string {
        $type = $cell->getAttribute('t');
        if ($type === 'inlineStr') {
            $inline = self::firstChild($cell, 'is');
            return $inline ? self::textRuns($inline) : '';
        }
        // Excel stores the last calculated value in v, including for formula cells.
        $value = self::firstChild($cell, 'v');
        if (!$value) {
            return '';
        }
        if ($type === 's') {
            $index = $value->textContent;
            if (!preg_match('/^[0-9]{1,7}$/D', $index) || !isset($strings[(int)$index])) {
                throw new NothizProductImportIssue('workbook_shared_string', [], 'The workbook contains an invalid shared string reference.');
            }
            return $strings[(int)$index];
        }
        return $value->textContent;
    }

    private static function textRuns(DOMElement $element): string {
        $plain = self::firstChild($element, 't');
        if ($plain) {
            return $plain->textContent;
        }
        $text = '';
        foreach (self::children($element, 'r') as $run) {
            $part = self::firstChild($run, 't');
            if ($part) {
                $text .= $part->textContent;
            }
        }
        return $text;
    }

    private static function children(DOMElement $parent, string $name): iterable {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name && $node->namespaceURI === $parent->namespaceURI) {
                yield $node;
            }
        }
    }

    private static function firstChild(DOMElement $parent, string $name): ?DOMElement {
        foreach (self::children($parent, $name) as $child) {
            return $child;
        }
        return null;
    }

    private static function relationships(DOMElement $xml, string $source): array {
        $relationships = [];
        foreach (self::children($xml, 'Relationship') as $element) {
            if ($element->getAttribute('TargetMode') === 'External') {
                continue;
            }
            $type = $element->getAttribute('Type');
            $name = substr($type, strrpos($type, '/') + 1);
            if (!in_array(dirname($type), self::OFFICE_REL_NS, true) || !in_array($name, ['officeDocument', 'worksheet', 'sharedStrings'], true)) {
                continue;
            }
            $id = $element->getAttribute('Id');
            if ($id === '' || isset($relationships[$id])) {
                throw new NothizProductImportIssue('workbook_relationship_id', [], 'The workbook contains an invalid OOXML relationship ID.');
            }
            $relationships[$id] = ['type' => $name, 'path' => self::resolvePart($source, $element->getAttribute('Target'))];
        }
        return $relationships;
    }

    private static function resolvePart(string $source, string $target): string {
        if ($target === '' || strpbrk($target, '?#') !== false) {
            throw new NothizProductImportIssue('workbook_relationship_target', [], 'The workbook contains an invalid OOXML relationship target.');
        }
        $target = rawurldecode($target);
        $directory = $source === '' ? '.' : dirname($source);
        $parts = $target[0] === '/' || $directory === '.' ? [] : explode('/', $directory);
        foreach (explode('/', $target[0] === '/' ? substr($target, 1) : $target) as $part) {
            if ($part === '.' || ($part === '..' && $parts)) {
                if ($part === '..') {
                    array_pop($parts);
                }
                continue;
            }
            self::assertPathPart($part, $target);
            $parts[] = $part;
        }
        if (!$parts) {
            throw new NothizProductImportIssue('workbook_relationship_target', [], 'The workbook contains an empty OOXML relationship target.');
        }
        return implode('/', $parts);
    }

    private static function xml(ZipArchive $zip, string $path, string $root, array $namespaces): DOMElement {
        $index = $zip->locateName($path);
        if ($index === false) {
            throw new NothizProductImportIssue('workbook_part_missing', ['part' => $root], 'The XLSX file is missing a required part.');
        }
        $xml = self::member($zip, $index, self::XML_BYTES);
        $doc = new DOMDocument();
        // Avoid loading external resources or expanding entities in uploaded XML.
        if (!$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)
            || $doc->doctype !== null || !$doc->documentElement || $doc->documentElement->localName !== $root
            || !in_array($doc->documentElement->namespaceURI, $namespaces, true)) {
            throw new NothizProductImportIssue('workbook_part_invalid', ['part' => $root], 'The XLSX file contains an invalid XML part.');
        }
        return $doc->documentElement;
    }

    // Image archive inspection

    public static function scanImages(string $path, ?array $state, string $imageRoot, bool $stripProducts, int $limit = 80): array {
        $zip = self::openArchive($path, self::IMAGE_ARCHIVE_BYTES, false);
        try {
            $count = $zip->numFiles;
            $total = 2 * $count;
            $state = $state ?? [
                'processed' => 0,
                'bytes' => 0,
                'products' => false,
                'other' => false,
                'catalog_products' => false,
                'other_catalog' => false,
                'deep' => false,
                'names' => [],
                'folders' => [],
                'groups' => [],
                'issues' => [],
                'warnings' => [],
            ];
            $root = self::imageRoot($imageRoot);
            $end = min($total, $state['processed'] + max(1, min($limit, 200)));
            $deadline = microtime(true) + 0.5;
            // Check archive-wide limits and wrapper evidence before assigning any entries to rows.
            do {
                $position = $state['processed']++;
                $index = $position % $count;
                $stat = self::stat($zip, $index);
                $path = $stat['name'];
                $directory = substr($path, -1) === '/';
                $parts = self::imagePathParts($path, $directory);
                if ($position < $count) {
                    $state['bytes'] += $stat['size'];
                    if ($state['bytes'] > self::EXPANDED_IMAGE_ARCHIVE_BYTES) {
                        throw new NothizProductImportIssue('archive_expanded', ['path' => $path], 'The image ZIP exceeds the maximum expanded size.');
                    }
                    if (!$directory && !self::imageJunk($parts) && self::isImageName($path) && count($parts) >= 3 && count($parts) <= 6) {
                        $products = $parts[0] === 'products' && count($parts) >= 4 && count($parts) <= 5;
                        $catalogProducts = array_slice($parts, 0, 2) === ['catalog', 'products'] && count($parts) >= 5;
                        $state[$products ? 'products' : 'other'] = true;
                        $state[$catalogProducts ? 'catalog_products' : 'other_catalog'] = true;
                        $state['deep'] = $state['deep'] || count($parts) > 4;
                    }
                } elseif (!$directory) {
                    self::scanImage($zip, $index, $stat, $parts, $root, $stripProducts, $state);
                }
            } while ($state['processed'] < $end && microtime(true) < $deadline);

            $done = $state['processed'] === $total;
            return [
                'done' => $done,
                'processed' => $state['processed'],
                'total' => $total,
                'state' => $done ? null : $state,
                'index' => !$done ? null : [
                    'groups' => $state['groups'],
                    'issues' => $state['issues'],
                    'warnings' => $state['warnings'],
                    'outer_products' => $state['products'] && !$state['other'],
                ],
            ];
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, array{categories:string[], entries:string[]}> */
    public static function inspectImages(string $path): array {
        $state = null;
        do {
            $result = self::scanImages($path, $state, dirname($path), false);
            $state = $result['state'];
        } while (!$result['done']);
        return $result['index']['groups'];
    }

    private static function scanImage(ZipArchive $zip, int $index, array $stat, array $parts, string $root, bool $stripProducts, array &$state): void {
        $path = $stat['name'];
        if (self::imageJunk($parts) || !self::isImageName($path)) {
            $state['warnings'][] = ['code' => 'image_ignored', 'params' => ['path' => $path]];
            return;
        }
        if ($state['catalog_products'] && !$state['other_catalog'] && $state['deep'] && array_slice($parts, 0, 2) === ['catalog', 'products']) {
            $parts = array_slice($parts, 2);
        } elseif ($stripProducts && $state['products'] && !$state['other'] && $parts[0] === 'products') {
            $parts = array_slice($parts, 1);
        }
        array_pop($parts);
        $sku = array_pop($parts);
        $key = $sku === null ? '' : self::foldPath($sku);
        $pathKey = hash('sha256', self::foldPath($path));
        try {
            if (array_key_exists($pathKey, $state['names'])) {
                $error = new NothizProductImportIssue('image_duplicate', ['path' => $path], 'The image ZIP repeats an image path.');
                $otherSku = $state['names'][$pathKey];
                if ($otherSku !== null && $otherSku !== $sku) {
                    $state['issues'][$otherSku][] = $error->issue();
                    unset($state['groups'][$otherSku]);
                }
                throw $error;
            }
            $state['names'][$pathKey] = $sku;
            if (count($parts) !== 1 && count($parts) !== 2) {
                throw new NothizProductImportIssue('image_layout', ['path' => $path], 'Use category/SKU/image or parent/child/SKU/image folders in the image ZIP.');
            }
            if (isset($state['folders'][$key]) && $state['folders'][$key] !== [$sku, $parts]) {
                $otherSku = $state['folders'][$key][0];
                $error = new NothizProductImportIssue('image_folders', ['path' => $path, 'sku' => $sku], 'The image ZIP contains the same SKU in multiple folders.');
                if ($otherSku !== $sku) {
                    $state['issues'][$otherSku][] = $error->issue();
                    unset($state['groups'][$otherSku]);
                }
                throw $error;
            }
            $state['folders'][$key] = [$sku, $parts];
            if (isset($state['issues'][$sku])) {
                return;
            }
            if (count($state['groups'][$sku]['entries'] ?? []) >= 100) {
                throw new NothizProductImportIssue('image_count', ['path' => $path, 'sku' => $sku], 'The image ZIP may contain at most 100 images per SKU.');
            }
            if ($stat['size'] < 1 || $stat['size'] > self::IMAGE_BYTES) {
                throw new NothizProductImportIssue('archive_entry_size', ['path' => $path], 'An archive entry is empty or exceeds the maximum expanded size.');
            }
            $extension = self::imageExtension($path);
            $header = @$zip->getFromIndex($index, min(self::IMAGE_HEADER_BYTES, $stat['size']));
            self::validateImageHeader($path, $extension, $header === false ? '' : $header, $stat['size'] > self::IMAGE_HEADER_BYTES);
            $destination = self::imageFile($root, $path);
            if ($destination !== null && file_exists($destination)) {
                if (@filesize($destination) !== $stat['size']) {
                    throw self::imageConflict($path);
                }
                try {
                    $archiveHash = hash('sha256', self::member($zip, $index, self::IMAGE_BYTES, $stat));
                } catch (NothizProductImportIssue $error) {
                    throw new NothizProductImportIssue('image_type', ['path' => $path], 'The image cannot be read completely.');
                }
                if (!self::matchingFile($destination, $stat['size'], $archiveHash)) {
                    throw self::imageConflict($path);
                }
                $state['groups'][$sku]['reused'] = ($state['groups'][$sku]['reused'] ?? 0) + 1;
            }
            $state['groups'][$sku]['categories'] = $parts;
            $state['groups'][$sku]['entries'][] = $path;
            usort($state['groups'][$sku]['entries'], static function (string $left, string $right): int {
                return strnatcasecmp($left, $right) ?: strcmp($left, $right);
            });
        } catch (NothizProductImportIssue $error) {
            if ($sku === null) {
                $state['warnings'][] = $error->issue();
            } else {
                $state['issues'][$sku][] = $error->issue();
                unset($state['groups'][$sku]);
            }
        }
    }

    private static function foldPath(string $path): string {
        return function_exists('mb_strtolower') ? mb_strtolower($path, 'UTF-8') : strtolower($path);
    }

    private static function imageJunk(array $parts): bool {
        $name = end($parts);
        return $parts[0] === '__MACOSX' || in_array($name, ['.DS_Store', 'Thumbs.db', 'desktop.ini'], true) || strpos($name, '._') === 0;
    }

    private static function isImageName(string $path): bool {
        return isset(self::IMAGE_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))]);
    }

    private static function imageExtension(string $path): string {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!isset(self::IMAGE_TYPES[$extension])) {
            throw new NothizProductImportIssue('image_type', ['path' => $path], 'The image ZIP supports JPG, JPEG, PNG and WebP images.');
        }
        if ($extension === 'webp' && defined('VERSION') && version_compare(VERSION, '3.0.3.9', '<')) {
            throw new NothizProductImportIssue('image_webp', ['path' => $path], 'Convert WebP images to JPG or PNG for OpenCart versions before 3.0.3.9.');
        }
        return $extension;
    }

    private static function validateImageHeader(string $path, string $extension, string $bytes, bool $partial = false): void {
        $image = @getimagesizefromstring($bytes);
        // A JPEG can have more metadata than the preview reads before its frame header.
        if (!$image && $partial && self::IMAGE_TYPES[$extension] === IMAGETYPE_JPEG && substr($bytes, 0, 3) === "\xff\xd8\xff") {
            return;
        }
        if (!$image || $image[2] !== self::IMAGE_TYPES[$extension] || $image[0] < 1 || $image[1] < 1
            || $image[0] > intdiv(16000000, $image[1])) {
            throw new NothizProductImportIssue('image_type', ['path' => $path], 'Invalid image type or dimensions.');
        }
    }

    private static function image(ZipArchive $zip, int $index, array $stat): array {
        $path = $stat['name'];
        $extension = self::imageExtension($path);
        $bytes = self::member($zip, $index, self::IMAGE_BYTES, $stat);
        self::validateImageHeader($path, $extension, $bytes);
        // A valid header can still precede truncated image data. Decode before it reaches OpenCart's resizer.
        if (!function_exists('imagecreatefromstring') || @imagecreatefromstring($bytes) === false) {
            throw new NothizProductImportIssue('image_decode', ['path' => $path], 'The image could not be decoded by PHP GD.');
        }
        return [$extension, $bytes];
    }

    // Publishing and rollback

    /** @return string[] Paths relative to the OpenCart image directory. */
    public static function publishImages(string $zipPath, array $entries, string $imageRoot, string $jobId, int $row, ?array &$publication = null): array {
        if (!$entries || count($entries) > 100) {
            throw new RuntimeException('Invalid list of images to publish.');
        }
        if ($publication !== null) {
            return self::publishOriginalImages($zipPath, $entries, self::imageRoot($imageRoot), $publication);
        }
        $relative = self::imageDestination($jobId, $row);
        $imageRoot = self::imageRoot($imageRoot);
        $zip = self::openArchive($zipPath, self::IMAGE_ARCHIVE_BYTES);
        $paths = [];
        $rowDirectory = '';
        try {
            $directory = $imageRoot;
            foreach (explode('/', dirname($relative)) as $part) {
                $directory .= '/' . $part;
                if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0755) && !is_dir($directory))) {
                    throw new RuntimeException('Unable to create the import image directory.');
                }
            }
            if (!@mkdir($directory . '/' . $row, 0755)) {
                throw new RuntimeException('The import image folder already exists or could not be created.');
            }
            $rowDirectory = $directory . '/' . $row;

            // Use generated filenames and exclusive writes instead of extracting ZIP paths into the web root.
            foreach (array_values($entries) as $number => $entry) {
                $index = $zip->locateName($entry);
                if ($index === false) {
                    throw new RuntimeException('An image selected for import is missing from the ZIP.');
                }
                list($extension, $bytes) = self::image($zip, $index, self::stat($zip, $index));
                $fileName = ($number + 1) . '.' . $extension;
                $file = @fopen($rowDirectory . '/' . $fileName, 'xb');
                if (!$file) {
                    throw new RuntimeException('Unable to create the import image file.');
                }
                $paths[] = $relative . '/' . $fileName;
                $length = @fwrite($file, $bytes);
                $closed = @fclose($file);
                if ($length !== strlen($bytes) || !$closed) {
                    throw new RuntimeException('Unable to write the complete import image file.');
                }
            }
            return $paths;
        } catch (Throwable $error) {
            self::discardImages($paths, $imageRoot);
            if ($rowDirectory !== '') {
                @rmdir($rowDirectory);
            }
            throw $error;
        } finally {
            $zip->close();
        }
    }

    private static function publishOriginalImages(string $zipPath, array $entries, string $root, array &$publication): array {
        $publication = [];
        $zip = self::openArchive($zipPath, self::IMAGE_ARCHIVE_BYTES, false);
        $paths = [];
        $created = [];
        try {
            foreach ($entries as $entry) {
                $file = self::imageFile($root, $entry);
                $index = $zip->locateName($entry);
                if ($index === false) {
                    throw new NothizProductImportIssue('image_missing', ['path' => $entry], 'An image selected for import is missing from the ZIP.');
                }
                [, $bytes] = self::image($zip, $index, self::stat($zip, $index));
                $size = strlen($bytes);
                $sha256 = hash('sha256', $bytes);
                $relative = self::IMPORT_IMAGE_PATH . '/' . $entry;
                if ($file !== null && file_exists($file)) {
                    if (!self::matchingFile($file, $size, $sha256)) {
                        throw self::imageConflict($entry);
                    }
                } else {
                    $file = self::imageFile($root, $entry, true);
                    $record = self::writeImage($root, $file, $entry, $bytes, $sha256);
                    if ($record !== null) {
                        $created[] = ['path' => $relative] + $record;
                    }
                }
                $paths[] = $relative;
            }
            $publication = $created;
            return $paths;
        } catch (Throwable $error) {
            self::discardPublishedImages($created, $root);
            throw $error;
        } finally {
            $zip->close();
        }
    }

    private static function writeImage(string $root, string $file, string $entry, string $bytes, string $sha256): ?array {
        $directory = dirname($file);
        $temporary = @tempnam($directory, 'npi-');
        if ($temporary === false) {
            throw self::imagePublishError($entry);
        }
        try {
            $size = strlen($bytes);
            if (realpath(dirname($temporary)) !== realpath($directory) || @file_put_contents($temporary, $bytes) !== $size
                || !@chmod($temporary, 0644) || !($stat = @stat($temporary))) {
                throw self::imagePublishError($entry);
            }
            // Linking exposes the complete file atomically and fails if the destination is already occupied.
            if (self::imageFile($root, $entry) === $file && function_exists('link') && @link($temporary, $file)) {
                return ['size' => $size, 'sha256' => $sha256, 'dev' => (int)$stat['dev'], 'ino' => (int)$stat['ino']];
            }
            $existing = self::imageFile($root, $entry);
            if ($existing !== null && file_exists($existing)) {
                if (self::matchingFile($existing, $size, $sha256)) {
                    return null;
                }
                throw self::imageConflict($entry);
            }
            throw self::imagePublishError($entry);
        } finally {
            @unlink($temporary);
        }
    }

    private static function imageRoot(string $path): string {
        $root = realpath($path);
        if ($root === false || !is_dir($root)) {
            throw new NothizProductImportIssue('image_root', [], 'The OpenCart image directory is unavailable.');
        }
        return $root;
    }

    private static function imagePathParts(string $path, bool $directory = false): array {
        if (strlen($path) > 1000) {
            throw new NothizProductImportIssue('archive_path_length', ['path' => self::displayArchivePath($path)], 'An archive path exceeds the maximum length.');
        }
        $parts = explode('/', $directory ? substr($path, 0, -1) : $path);
        foreach ($parts as $part) {
            self::assertPathPart($part, $path);
            if (preg_match('/[<>"|?*]|^ |[. ]$|^(?:CON(?:IN\$|OUT\$)?|PRN|AUX|NUL|(?:COM|LPT)[1-9¹²³]) *(?:\.|$)/iuD', $part)) {
                throw new NothizProductImportIssue('archive_path', ['path' => self::displayArchivePath($path)], 'The archive contains an unsafe path.');
            }
        }
        return $parts;
    }

    private static function imageFile(string $root, string $entry, bool $create = false): ?string {
        $parts = self::imagePathParts($entry);
        $name = array_pop($parts);
        if (preg_match('/\.(?:php[0-9]*|phps|phtml?|phar|cgi|pl|py|shtml|asp[x]?|jsp[x]?)\./i', $name)) {
            // Apache can apply a handler based on a secondary filename extension.
            throw new NothizProductImportIssue('image_extension_unsafe', ['path' => $entry], 'The image filename contains an executable secondary extension.');
        }
        $relative = self::IMPORT_IMAGE_PATH . '/' . $entry;
        if (preg_match('/^.{256}/us', $relative)) {
            throw new NothizProductImportIssue('image_path_length', ['path' => $entry], 'The image path exceeds the OpenCart image column length.');
        }
        foreach (array_merge(explode('/', self::IMPORT_IMAGE_PATH), $parts) as $part) {
            $root .= '/' . $part;
            clearstatcache(true, $root);
            if (is_link($root) || (file_exists($root) && !is_dir($root))) {
                throw self::imageConflict($entry);
            }
            if (!is_dir($root)) {
                if (!$create) {
                    return null;
                }
                if (!@mkdir($root, 0755) && !is_dir($root)) {
                    throw self::imagePublishError($entry);
                }
                if (is_link($root)) {
                    throw self::imageConflict($entry);
                }
            }
        }
        $file = $root . '/' . $name;
        clearstatcache(true, $file);
        if (is_link($file) || (file_exists($file) && !is_file($file))) {
            throw self::imageConflict($entry);
        }
        return $file;
    }

    private static function matchingFile(string $file, int $size, string $sha256): bool {
        clearstatcache(true, $file);
        return !is_link($file) && is_file($file) && @filesize($file) === $size && @hash_file('sha256', $file) === $sha256;
    }

    private static function imageConflict(string $entry): NothizProductImportIssue {
        return new NothizProductImportIssue('image_conflict', ['path' => $entry], 'An existing image path contains different bytes or an unexpected entry.');
    }

    private static function imagePublishError(string $entry): NothizProductImportIssue {
        return new NothizProductImportIssue('image_publish', ['path' => $entry], 'Unable to publish the complete import image file.');
    }

    public static function discardPublishedImages(array $publication, string $imageRoot): void {
        $root = realpath($imageRoot);
        if ($root === false) {
            return;
        }
        $prefix = self::IMPORT_IMAGE_PATH . '/';
        foreach ($publication as $record) {
            if (strpos($record['path'], $prefix) !== 0) {
                continue;
            }
            try {
                $file = self::imageFile($root, substr($record['path'], strlen($prefix)));
                $stat = $file === null ? false : @lstat($file);
                if ($stat && (int)$stat['dev'] === $record['dev'] && (int)$stat['ino'] === $record['ino']
                    && self::matchingFile($file, $record['size'], $record['sha256'])) {
                    @unlink($file);
                }
            } catch (NothizProductImportIssue $error) {
                continue;
            }
        }
    }

    /**
     * Remove files left by an interrupted attempt after the caller has checked the SKU under its database lock.
     */
    public static function discardRowImages(string $imageRoot, string $jobId, int $row): void {
        $relative = self::imageDestination($jobId, $row);
        $directory = realpath($imageRoot);
        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException('The OpenCart image directory is unavailable.');
        }
        foreach (explode('/', $relative) as $part) {
            $directory .= '/' . $part;
            if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
                throw new RuntimeException('The prior import image path contains an unexpected entry.');
            }
            if (!is_dir($directory)) {
                return;
            }
        }

        // Validate the whole folder before deleting anything so unrelated files are preserved.
        $files = [];
        foreach (new FilesystemIterator($directory) as $file) {
            if ($file->isLink() || !$file->isFile() || !preg_match('/^([1-9][0-9]?|100)\.(?:jpe?g|png|webp)$/D', $file->getFilename(), $match)
                || isset($files[$match[1]])) {
                throw new RuntimeException('The prior import image folder contains an unexpected entry.');
            }
            $files[$match[1]] = $file->getPathname();
        }
        foreach ($files as $path) {
            if (!@unlink($path)) {
                throw new RuntimeException('Unable to remove an image left by an interrupted import.');
            }
        }
        if (!@rmdir($directory)) {
            throw new RuntimeException('Unable to remove an image folder left by an interrupted import.');
        }
    }

    private static function imageDestination(string $jobId, int $row): string {
        if (!preg_match('/^[a-f0-9]{32}$/D', $jobId) || $row < 1 || $row > 1048576) {
            throw new RuntimeException('Invalid import image destination.');
        }
        return self::IMPORT_IMAGE_PATH . '/' . $jobId . '/' . $row;
    }

    public static function discardImages(array $paths, string $imageRoot): void {
        $root = realpath($imageRoot);
        if ($root === false) {
            return;
        }
        $directories = [];
        foreach ($paths as $path) {
            if (!preg_match('#^catalog/nothiz_product_import/[a-f0-9]{32}/[1-9][0-9]*/[1-9][0-9]*\.(?:jpe?g|png|webp)$#D', $path)) {
                continue;
            }
            $directory = $root;
            foreach (explode('/', dirname($path)) as $part) {
                $directory .= '/' . $part;
                if (is_link($directory)) {
                    continue 2;
                }
            }
            @unlink($root . '/' . $path);
            $directories[$directory] = true;
        }
        foreach ($directories as $directory => $unused) {
            @rmdir($directory);
        }
    }

    // Shared archive validation

    private static function openArchive(string $path, int $maxBytes, bool $checkConsistency = true): ZipArchive {
        if (!class_exists('ZipArchive')) {
            throw new NothizProductImportIssue('archive_zip_required', [], 'The PHP ZIP extension is required.');
        }
        $size = @filesize($path);
        if ($size === false || $size < 1 || $size > $maxBytes) {
            throw new NothizProductImportIssue('archive_size', [], 'The archive is unreadable, empty, or exceeds the maximum upload size.');
        }
        $zip = new ZipArchive();
        // Resumable scans validate entries in batches. CHECKCONS reads all local headers on every open.
        if (@$zip->open($path, $checkConsistency ? ZipArchive::CHECKCONS : 0) !== true) {
            throw new NothizProductImportIssue('archive_open', [], 'Unable to open the ZIP or XLSX file.');
        }
        if ($zip->numFiles < 1 || $zip->numFiles > 10000) {
            $zip->close();
            throw new NothizProductImportIssue('archive_entries', [], 'The archive must contain between 1 and 10,000 entries.');
        }
        return $zip;
    }

    private static function stat(ZipArchive $zip, int $index): array {
        $stat = $zip->statIndex($index);
        $system = $mode = 0;
        if (!$stat || $stat['size'] < 0 || !empty($stat['encryption_method'])
            || ($zip->getExternalAttributesIndex($index, $system, $mode) && $system === 3
                && !in_array(($mode >> 16) & 0170000, [0, 0040000, 0100000], true))) {
            throw new NothizProductImportIssue('archive_entry', ['path' => self::displayArchivePath($stat['name'] ?? '')], 'The archive contains an encrypted, linked, or invalid entry.');
        }
        return $stat;
    }

    private static function member(ZipArchive $zip, int $index, int $maxBytes, ?array $stat = null): string {
        $stat = $stat ?? self::stat($zip, $index);
        if ($stat['size'] < 1 || $stat['size'] > $maxBytes) {
            throw new NothizProductImportIssue('archive_entry_size', ['path' => $stat['name']], 'An archive entry is empty or exceeds the maximum expanded size.');
        }
        $bytes = @$zip->getFromIndex($index, $stat['size'] + 1);
        if ($bytes === false || strlen($bytes) !== $stat['size']) {
            throw new NothizProductImportIssue('archive_entry_read', ['path' => $stat['name']], 'An archive entry could not be read completely.');
        }
        return $bytes;
    }

    private static function assertPathPart(string $part, string $path): void {
        if ($part === '' || $part === '.' || $part === '..' || strlen($part) > 255
            || !preg_match('//u', $part) || preg_match('/[\x00-\x1f\x7f\\\\:]/', $part)) {
            throw new NothizProductImportIssue('archive_path', ['path' => self::displayArchivePath($path)], 'The archive contains an unsafe path.');
        }
    }

    private static function displayArchivePath(string $path): string {
        if (strlen($path) > 1024) {
            $path = substr($path, 0, 1024) . '…';
        }
        return addcslashes($path, "\0..\37\177");
    }
}
