<?php

class NothizProductImportPrivateStorage {
    private const APACHE_RULES = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";

    public static function protect(string $directory): bool {
        $directory = rtrim($directory, '/\\');
        clearstatcache(true, $directory);
        return !is_link($directory) && is_dir($directory)
            && self::ensureFile($directory . '/.htaccess', self::APACHE_RULES)
            && self::ensureFile($directory . '/index.html', '');
    }

    private static function ensureFile(string $path, string $contents): bool {
        // Exclusive creation prevents a preexisting file or link from being overwritten.
        if (is_link($path)) {
            return false;
        }
        $file = @fopen($path, 'xb');
        if ($file === false) {
            clearstatcache(true, $path);
            return !is_link($path) && is_file($path)
                && @file_get_contents($path, false, null, 0, strlen($contents) + 1) === $contents;
        }
        $written = @fwrite($file, $contents);
        $closed = @fclose($file);
        if ($written !== strlen($contents) || !$closed) {
            @unlink($path);
            return false;
        }
        return true;
    }
}
