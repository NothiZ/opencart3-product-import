<?php
require dirname(__DIR__) . '/upload/system/library/nothiz_product_import/staged_upload.php';

function uploadCheck($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function uploadRejects(callable $attempt, string $message): void {
    try {
        $attempt();
    } catch (InvalidArgumentException | RuntimeException $error) {
        return;
    }
    throw new RuntimeException($message);
}

$directory = sys_get_temp_dir() . '/nothiz-upload-test-' . bin2hex(random_bytes(8));
mkdir($directory);
$file = $directory . '/images.zip';
$first = random_bytes(NothizProductImportStagedUpload::CHUNK_BYTES);
$second = random_bytes(strlen($first));
$tail = 'last';
$total = strlen($first) + strlen($second) + strlen($tail);

try {
    uploadCheck(NothizProductImportStagedUpload::append($file, $total, $total - strlen($tail), $tail) === $total, 'Out-of-order tail was not acknowledged');
    uploadCheck(NothizProductImportStagedUpload::CHUNK_BYTES === 4 * 1024 * 1024, 'The chunk limit is not 4 MiB');
    uploadCheck(filesize($file) === $total, 'The out-of-order tail did not exercise sparse file completion');
    uploadCheck(!NothizProductImportStagedUpload::complete($file, $total), 'A sparse file was treated as complete');
    uploadCheck(NothizProductImportStagedUpload::append($file, $total, 0, $first) === strlen($first), 'Initial offset is wrong');
    uploadCheck(NothizProductImportStagedUpload::append($file, $total, 0, $first) === strlen($first), 'Repeated chunk is not idempotent');
    uploadRejects(function () use ($file, $total, $second) {
        NothizProductImportStagedUpload::append($file, $total, 0, $second);
    }, 'A different chunk was treated as a retry');
    uploadRejects(function () use ($file, $total, $first) {
        NothizProductImportStagedUpload::append($file, $total, strlen($first), $first . 'x');
    }, 'An oversized chunk was accepted');
    uploadRejects(function () use ($file, $total, $first) {
        NothizProductImportStagedUpload::append($file, $total, strlen($first) - 1, substr($first, -1) . 'x');
    }, 'An overlapping chunk was accepted');
    uploadRejects(function () use ($file, $total) {
        NothizProductImportStagedUpload::append($file, $total, $total - 4, 'too long');
    }, 'A file exceeding its declared size was accepted');
    uploadCheck(!NothizProductImportStagedUpload::complete($file, $total), 'An interior gap was treated as complete');
    uploadCheck(NothizProductImportStagedUpload::append($file, $total, strlen($first), $second) === $total - 4, 'Second offset is wrong');
    uploadCheck(NothizProductImportStagedUpload::complete($file, $total), 'A fully covered upload was treated as incomplete');
    uploadCheck(NothizProductImportStagedUpload::append($file, $total, $total - 4, $tail) === $total, 'Final offset is wrong');
    uploadCheck(file_get_contents($file) === $first . $second . $tail, 'Staged bytes differ from the original');

    $cached = $directory . '/cached.zip';
    file_put_contents($cached, 'cached');
    uploadRejects(function () use ($cached) {
        NothizProductImportStagedUpload::append($cached, 7, 6, 'x');
    }, 'A prepopulated archive was modified');
    uploadCheck(file_get_contents($cached) === 'cached', 'The cached archive changed');
    uploadCheck(!NothizProductImportStagedUpload::complete($cached, 6), 'An archive without coverage was treated as staged');

    $recoverable = $directory . '/recoverable.zip';
    NothizProductImportStagedUpload::append($recoverable, 6, 3, 'end');
    mkdir($recoverable . '.ranges.tmp');
    try {
        uploadRejects(function () use ($recoverable) {
            NothizProductImportStagedUpload::append($recoverable, 6, 0, 'top');
        }, 'A failed coverage write was acknowledged');
        uploadCheck(!NothizProductImportStagedUpload::complete($recoverable, 6), 'Bytes without committed coverage were treated as complete');
    } finally {
        rmdir($recoverable . '.ranges.tmp');
    }
    uploadCheck(NothizProductImportStagedUpload::append($recoverable, 6, 0, 'top') === 3, 'A chunk could not be retried after a failed coverage write');
    uploadCheck(NothizProductImportStagedUpload::complete($recoverable, 6), 'Retried coverage was not saved');

    $fragmented = $directory . '/fragmented.zip';
    for ($offset = 1; $offset < 64; $offset += 2) {
        NothizProductImportStagedUpload::append($fragmented, 66, $offset, 'x');
    }
    uploadRejects(function () use ($fragmented) {
        NothizProductImportStagedUpload::append($fragmented, 66, 65, 'x');
    }, 'Excessive fragmentation was accepted');
    NothizProductImportStagedUpload::append($fragmented, 66, 2, 'x');
    uploadCheck(NothizProductImportStagedUpload::append($fragmented, 66, 65, 'x') === 66, 'Merging adjacent intervals did not free coverage capacity');
    echo "Staged upload checks passed\n";
} finally {
    foreach (glob($directory . '/*') as $stagedPath) {
        unlink($stagedPath);
    }
    rmdir($directory);
}
