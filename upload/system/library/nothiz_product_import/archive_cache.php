<?php

class NothizProductImportArchiveCache {
    public const TTL = 72 * 60 * 60;
    public const MAX_ARCHIVES = 3;
    public const MAX_BYTES = 1024 * 1024 * 1024;

    private const INDEX_VERSION = 1;
    private const INDEX_BYTES = 24 * 1024 * 1024;
    private const DIGEST = '/\A[0-9a-f]{64}\z/';
    private $root;
    private $directory;

    public function __construct(string $uploadRoot) {
        $root = realpath($uploadRoot);
        $this->root = $root !== false && is_dir($root) ? self::normalize($root) : null;
        $this->directory = $this->root . '/nothiz_product_import_archive_cache';
    }

    /**
     * The caller holds the destination job lock. A legacy hit has no reusable image index.
     *
     * @return array{index:array|null}|null
     */
    public function reuse(string $sha, int $bytes, string $destination, ?string $legacyPath = null): ?array {
        if (!preg_match(self::DIGEST, $sha) || $bytes <= 0 || $bytes > self::MAX_BYTES
            || !$this->isJobArchive($destination) || !self::isVacant($destination)) {
            return null;
        }

        $lock = $this->lock(false);
        if ($lock !== false) {
            try {
                $this->prune();
                $entry = $this->directory . '/' . $sha;
                $cachedBytes = self::size($entry . '.zip');
                if ($cachedBytes === $bytes) {
                    $index = $this->readIndex($entry . '.json', $sha, $bytes);
                    if ($index === null || @hash_file('sha256', $entry . '.zip') !== $sha) {
                        $this->remove($entry);
                    } elseif ($this->attach($entry . '.zip', $destination, $bytes)) {
                        @touch($entry . '.json');
                        return ['index' => $index];
                    }
                }
            } finally {
                fclose($lock);
            }
        }

        if ($legacyPath !== null && $this->isJobArchive($legacyPath) && self::size($legacyPath) === $bytes
            && @hash_file('sha256', $legacyPath) === $sha && $this->attach($legacyPath, $destination, $bytes)) {
            return ['index' => null];
        }
        return null;
    }

    /** The caller has verified the archive digest and successfully built its image index. */
    public function remember(string $sha, string $source, array $index): void {
        if (!preg_match(self::DIGEST, $sha) || !$this->isJobArchive($source)) {
            return;
        }
        $bytes = self::size($source);
        if ($bytes <= 0 || $bytes > self::MAX_BYTES) {
            return;
        }
        $metadata = json_encode([
            'version' => self::INDEX_VERSION,
            'sha256' => $sha,
            'bytes' => $bytes,
            'index' => $index,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($metadata === false || strlen($metadata) > self::INDEX_BYTES) {
            return;
        }

        $lock = $this->lock(true);
        if ($lock === false) {
            return;
        }
        try {
            $this->prune();
            $entry = $this->directory . '/' . $sha;
            if (!self::isVacant($entry . '.json') || !$this->attach($source, $entry . '.zip', $bytes)) {
                return;
            }

            $manifest = @fopen($entry . '.json', 'xb');
            if ($manifest === false) {
                @unlink($entry . '.zip');
                return;
            }
            $written = @fwrite($manifest, $metadata);
            $closed = @fclose($manifest);
            if ($written !== strlen($metadata) || !$closed) {
                $this->remove($entry);
                return;
            }
            $this->prune($entry);
        } finally {
            fclose($lock);
        }
    }

    private function lock(bool $create) {
        clearstatcache(true, $this->directory);
        if ($this->root === null || is_link($this->directory)) {
            return false;
        }
        if ($create && !is_dir($this->directory)) {
            @mkdir($this->directory, 0700);
        }
        $actual = realpath($this->directory);
        if ($actual === false || self::normalize($actual) !== $this->directory || !is_dir($this->directory)) {
            return false;
        }

        $path = $this->directory . '/lock';
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            return false;
        }
        $lock = @fopen($path, 'c+b');
        if ($lock === false) {
            return false;
        }
        // Allow another administrator's cache operation to finish before treating the archive as unavailable.
        $deadline = microtime(true) + 2.0;
        while (!@flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($lock);
                return false;
            }
            usleep(50000);
        }
        return $lock;
    }

    private function prune(string $preferred = ''): void {
        $prefixes = [];
        foreach (@scandir($this->directory) ?: [] as $name) {
            if (preg_match('/\A([0-9a-f]{64})\.(?:zip|json)\z/', $name, $entry)) {
                $prefixes[$this->directory . '/' . $entry[1]] = true;
            } elseif (preg_match('/\A[0-9a-f]{64}\.zip\.cache-tmp\z/', $name)) {
                // A cache copy may have stopped before its atomic rename.
                @unlink($this->directory . '/' . $name);
            }
        }

        $remaining = [];
        $cutoff = time() - self::TTL;
        foreach ($prefixes as $entry => $unused) {
            $bytes = self::size($entry . '.zip');
            $indexBytes = self::size($entry . '.json');
            $mtime = $indexBytes > 0 && $indexBytes <= self::INDEX_BYTES ? @filemtime($entry . '.json') : false;
            if ($bytes <= 0 || $bytes > self::MAX_BYTES || $mtime === false || $mtime <= $cutoff) {
                $this->remove($entry);
                continue;
            }
            $remaining[$entry] = ['bytes' => $bytes, 'mtime' => $entry === $preferred ? PHP_INT_MAX : $mtime];
        }

        uasort($remaining, function (array $left, array $right): int {
            return $right['mtime'] <=> $left['mtime'];
        });
        $used = 0;
        $count = 0;
        foreach ($remaining as $entry => $metadata) {
            if ($count >= self::MAX_ARCHIVES || $metadata['bytes'] > self::MAX_BYTES - $used) {
                $this->remove($entry);
                continue;
            }
            $used += $metadata['bytes'];
            $count++;
        }
    }

    private function remove(string $entry): void {
        @unlink($entry . '.json');
        @unlink($entry . '.zip');
    }

    private function readIndex(string $path, string $sha, int $bytes): ?array {
        $length = self::size($path);
        if ($length <= 0 || $length > self::INDEX_BYTES) {
            return null;
        }
        $raw = @file_get_contents($path, false, null, 0, self::INDEX_BYTES + 1);
        $metadata = is_string($raw) ? json_decode($raw, true, 8) : null;
        if (($metadata['version'] ?? null) !== self::INDEX_VERSION || ($metadata['sha256'] ?? null) !== $sha
            || ($metadata['bytes'] ?? null) !== $bytes || empty($metadata['index']) || !is_array($metadata['index'])) {
            return null;
        }
        foreach ($metadata['index'] as $group) {
            if (!is_array($group) || !self::isStringList($group['categories'] ?? null, 2)
                || !self::isStringList($group['entries'] ?? null, 100)) {
                return null;
            }
        }
        return $metadata['index'];
    }

    private static function isStringList($values, int $limit): bool {
        if (!is_array($values) || !$values || count($values) > $limit || array_values($values) !== $values) {
            return false;
        }
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                return false;
            }
        }
        return true;
    }

    private function isJobArchive(string $path): bool {
        $directory = dirname($path);
        $job = basename($directory);
        if ($this->root === null || basename($path) !== 'images.zip'
            || !preg_match('/\Anothiz_product_import_[0-9a-f]{32}\z/', $job)
            || is_link($path) || is_link($directory) || !is_dir($directory)) {
            return false;
        }
        $actual = realpath($directory);
        return $actual !== false && self::normalize($actual) === $this->root . '/' . $job;
    }

    private static function normalize(string $path): string {
        return rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $path), '/');
    }

    private static function size(string $path): int {
        clearstatcache(true, $path);
        return !is_link($path) && is_file($path) ? (int) @filesize($path) : 0;
    }

    private static function isVacant(string $path): bool {
        clearstatcache(true, $path);
        return !file_exists($path) && !is_link($path);
    }

    private function attach(string $source, string $destination, int $bytes): bool {
        if (!self::isVacant($destination)) {
            return false;
        }
        // Completed ZIPs are immutable and can share disk space until each job is cleaned up.
        if (function_exists('link') && @link($source, $destination)) {
            return true;
        }

        // The cache or job lock protects the destination check and atomic publication.
        $temporary = $destination . '.cache-tmp';
        $input = @fopen($source, 'rb');
        if ($input === false) {
            return false;
        }
        $output = @fopen($temporary, 'xb');
        if ($output === false) {
            fclose($input);
            return false;
        }
        $copied = @stream_copy_to_stream($input, $output, $bytes + 1);
        fclose($input);
        $closed = @fclose($output);
        if ($copied === $bytes && $closed && self::isVacant($destination) && @rename($temporary, $destination)) {
            return true;
        }
        @unlink($temporary);
        return false;
    }
}
