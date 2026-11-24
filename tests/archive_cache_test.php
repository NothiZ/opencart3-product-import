<?php

$library = dirname(__DIR__) . '/upload/system/library/nothiz_product_import/archive_cache.php';
if (!is_file($library)) {
    throw new RuntimeException('The reusable ZIP archive cache is missing');
}
require $library;

function cacheCheck($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cacheJob(string $root): string {
    $job = $root . '/nothiz_product_import_' . bin2hex(random_bytes(16));
    mkdir($job);
    return $job . '/images.zip';
}

function cacheZip(string $path, string $contents): array {
    $zip = new ZipArchive();
    cacheCheck($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) === true, 'Could not create the fixture ZIP');
    $zip->addFromString('Category/sku/image.png', $contents);
    $zip->close();
    return [hash_file('sha256', $path), filesize($path)];
}

function cacheDeleteFixture(string $directory): void {
    foreach (new FilesystemIterator($directory) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            cacheDeleteFixture($entry->getPathname());
        } elseif ($entry->isDir() && DIRECTORY_SEPARATOR === '\\') {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

$root = sys_get_temp_dir() . '/nothiz-archive-cache-test-' . bin2hex(random_bytes(8));
mkdir($root);
$cacheDirectory = $root . '/nothiz_product_import_archive_cache';
$index = ['sku' => ['categories' => ['Category'], 'entries' => ['Category/sku/image.png']]];

try {
    $source = cacheJob($root);
    [$sha, $bytes] = cacheZip($source, 'fixture');
    $cache = new NothizProductImportArchiveCache($root);
    $destination = cacheJob($root);
    cacheCheck($cache->reuse($sha, $bytes, $destination) === null && !file_exists($destination), 'A miss wrote an archive');
    cacheCheck($cache->reuse($sha, $bytes, $destination, $source) === ['index' => null], 'An exact legacy ZIP was not attached');
    cacheCheck(hash_file('sha256', $destination) === $sha, 'Legacy ZIP bytes differ');
    cacheCheck(is_file(dirname($source) . '/.htaccess') && is_file(dirname($source) . '/index.html'), 'Legacy reuse did not backfill directory protection');
    cacheCheck($cache->reuse(str_repeat('a', 64), $bytes, cacheJob($root), $source) === null, 'A legacy ZIP with a different digest was accepted');
    cacheCheck($cache->reuse($sha, $bytes + 1, cacheJob($root), $source) === null, 'A legacy ZIP with a different length was accepted');

    $cache->remember($sha, $source, $index);
    cacheCheck(is_file($cacheDirectory . '/.htaccess') && strpos(file_get_contents($cacheDirectory . '/.htaccess'), 'Require all denied') !== false, 'The cache did not block direct Apache access');
    cacheCheck(is_file($cacheDirectory . '/index.html') && file_get_contents($cacheDirectory . '/index.html') === '', 'The cache did not create a blank default page');
    unlink($cacheDirectory . '/.htaccess');
    $cache->remember($sha, $source, ['other' => $index['sku']]);
    cacheCheck(is_file($cacheDirectory . '/.htaccess'), 'An existing cache directory was not protected');
    unlink($source);
    $cache = new NothizProductImportArchiveCache($root);
    $freshJob = cacheJob($root);
    cacheCheck($cache->reuse($sha, $bytes + 1, $freshJob) === null, 'The cache accepted a different byte count');
    cacheCheck($cache->reuse($sha, $bytes, $freshJob) === ['index' => $index], 'A new cache instance did not reuse the original index');
    cacheCheck(hash_file('sha256', $freshJob) === $sha, 'The cached attachment has different bytes');
    cacheCheck($cache->reuse($sha, $bytes, $freshJob) === null && hash_file('sha256', $freshJob) === $sha, 'The cache overwrote a staged archive');
    cacheCheck($cache->reuse('../' . $sha, $bytes, cacheJob($root)) === null, 'A digest with path separators was accepted');
    cacheCheck($cache->reuse(strtoupper($sha), $bytes, cacheJob($root)) === null, 'A noncanonical digest was accepted');
    cacheCheck($cache->reuse($sha, $bytes, $root . '/images.zip') === null && !file_exists($root . '/images.zip'), 'The cache attached outside a staging directory');

    $lock = fopen($cacheDirectory . '/lock', 'c+b');
    cacheCheck(flock($lock, LOCK_EX | LOCK_NB), 'Could not lock the cache fixture');
    try {
        cacheCheck($cache->reuse($sha, $bytes, cacheJob($root), $destination) === ['index' => null], 'A busy cache blocked legacy reuse');
    } finally {
        fclose($lock);
    }

    touch($cacheDirectory . '/' . $sha . '.json', time() - NothizProductImportArchiveCache::TTL - 1);
    cacheCheck($cache->reuse($sha, $bytes, cacheJob($root)) === null, 'An expired ZIP was reused');
    cacheCheck(!file_exists($cacheDirectory . '/' . $sha . '.zip'), 'Expiry did not remove the cached ZIP');
    cacheCheck(hash_file('sha256', $freshJob) === $sha, 'Expiry removed a live job archive');

    foreach (range(0, NothizProductImportArchiveCache::MAX_ARCHIVES) as $number) {
        $job = cacheJob($root);
        [$digest, $size] = cacheZip($job, 'fixture-' . $number);
        $cache->remember($digest, $job, $index);
        if ($number === 0) {
            $first = $digest;
            touch($cacheDirectory . '/' . $first . '.json', time() - 60);
        }
    }
    cacheCheck(count(glob($cacheDirectory . '/*.zip')) === NothizProductImportArchiveCache::MAX_ARCHIVES, 'The cache exceeded its archive count');
    cacheCheck(!file_exists($cacheDirectory . '/' . $first . '.json'), 'The oldest entry survived quota cleanup');

    $entry = $cacheDirectory . '/' . $digest;
    $versionOne = json_encode(['version' => 1, 'sha256' => $digest, 'bytes' => $size, 'index' => $index]);
    foreach ([$versionOne, '{"index":{"sku":[]}}', '', null] as $oldManifest) {
        if ($oldManifest === null) {
            unlink($entry . '.json');
        } else {
            file_put_contents($entry . '.json', $oldManifest);
        }
        $replacement = cacheJob($root);
        cacheCheck($cache->reuse($digest, $size, $replacement) === ['index' => null], 'A verified ZIP with an obsolete or invalid index was not reused for rescanning');
        cacheCheck(hash_file('sha256', $replacement) === $digest && hash_file('sha256', $entry . '.zip') === $digest, 'Index recovery lost the verified ZIP');
        $cache->remember($digest, $replacement, $index);
        cacheCheck($cache->reuse($digest, $size, cacheJob($root)) === ['index' => $index], 'Rescanning did not replace the unusable index');
    }

    unlink($entry . '.json');
    mkdir($entry . '.json');
    $cache->remember($digest, $job, $index);
    cacheCheck(is_dir($entry . '.json') && !file_exists($entry . '.json.cache-tmp') && hash_file('sha256', $entry . '.zip') === $digest, 'A failed index replacement left a temporary file or lost the verified ZIP');
    rmdir($entry . '.json');

    $cache->remember($digest, $job, $index);
    unlink($cacheDirectory . '/' . $digest . '.zip');
    file_put_contents($cacheDirectory . '/' . $digest . '.zip', str_repeat('x', $size));
    cacheCheck($cache->reuse($digest, $size, cacheJob($root)) === null, 'A same-size change to a cached ZIP went undetected');

    $blocked = $root . '/blocked';
    mkdir($blocked);
    $blockedSource = cacheJob($blocked);
    [$legacySha, $legacyBytes] = cacheZip($blockedSource, 'fallback');
    file_put_contents($blocked . '/nothiz_product_import_archive_cache', 'leave in place');
    $blockedCache = new NothizProductImportArchiveCache($blocked);
    $blockedCache->remember($legacySha, $blockedSource, $index);
    cacheCheck($blockedCache->reuse($legacySha, $legacyBytes, cacheJob($blocked), $blockedSource) === ['index' => null], 'An unavailable cache blocked legacy reuse');
    cacheCheck($blockedCache->reuse($legacySha, $legacyBytes, cacheJob($root), $blockedSource) === null, 'A different upload root was accepted');

    unlink($blocked . '/nothiz_product_import_archive_cache');
    if (@symlink($cacheDirectory, $blocked . '/nothiz_product_import_archive_cache')) {
        $blockedCache->remember($legacySha, $blockedSource, $index);
        cacheCheck(!file_exists($cacheDirectory . '/' . $legacySha . '.zip'), 'Cache publication followed a symbolic link');
    }

    $private = dirname(cacheJob($root));
    cacheCheck(NothizProductImportPrivateStorage::protect($private), 'A job directory could not be protected');
    cacheCheck(NothizProductImportPrivateStorage::protect($private), 'A protected job directory could not be reopened');
    file_put_contents($private . '/index.html', 'custom content');
    cacheCheck(!NothizProductImportPrivateStorage::protect($private) && file_get_contents($private . '/index.html') === 'custom content', 'Directory protection overwrote a custom index page');
    file_put_contents($private . '/.htaccess', 'custom rules');
    cacheCheck(!NothizProductImportPrivateStorage::protect($private) && file_get_contents($private . '/.htaccess') === 'custom rules', 'Directory protection overwrote custom rules');
    unlink($private . '/.htaccess');
    $outside = $root . '/outside';
    file_put_contents($outside, 'untouched');
    if (@symlink($outside, $private . '/.htaccess')) {
        cacheCheck(!NothizProductImportPrivateStorage::protect($private) && file_get_contents($outside) === 'untouched', 'Directory protection followed a symbolic link');
    }
    if (@symlink($private, $root . '/linked-job')) {
        cacheCheck(!NothizProductImportPrivateStorage::protect($root . '/linked-job/'), 'Directory protection followed a symbolic directory link');
    }

    echo "Archive cache checks passed\n";
} finally {
    cacheDeleteFixture($root);
}
