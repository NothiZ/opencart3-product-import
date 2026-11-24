<?php

class NothizProductImportStagedUpload {
    public const CHUNK_BYTES = 4 * 1024 * 1024;
    private const MAX_RANGES = 32;

    // The caller holds the job lock while appending chunks or checking completion.
    public static function append(string $path, int $total, int $offset, string $chunk): int {
        $bytes = strlen($chunk);
        if ($offset < 0 || $bytes < 1 || $bytes > self::CHUNK_BYTES || $total < $bytes || $offset > $total - $bytes) {
            throw new InvalidArgumentException('Invalid upload chunk size or offset.');
        }

        $sidecar = $path . '.ranges';
        clearstatcache(true, $path);
        clearstatcache(true, $sidecar);
        $ranges = self::readRanges($sidecar, $total);
        if ($ranges === null) {
            if (file_exists($path) || is_link($path) || file_exists($sidecar) || is_link($sidecar)) {
                throw new RuntimeException('OpenCart could not verify the staged upload.');
            }
            // Establish ownership before writing data so an interrupted first write can be retried.
            $ranges = [];
            self::writeRanges($sidecar, $total, $ranges);
        }

        $size = file_exists($path) ? @filesize($path) : 0;
        if (is_link($path) || (file_exists($path) && !is_file($path)) || $size === false || $size > $total
            || ($ranges && end($ranges)[1] > $size)) {
            throw new RuntimeException('OpenCart could not verify the staged upload.');
        }

        $end = $offset + $bytes;
        $mergedStart = $offset;
        $mergedEnd = $end;
        $next = [];
        foreach ($ranges as [$start, $stop]) {
            if ($offset < $stop && $end > $start) {
                // An acknowledgement can be lost after the server has already stored the chunk.
                if ($offset >= $start && $end <= $stop && @file_get_contents($path, false, null, $offset, $bytes) === $chunk) {
                    return $end;
                }
                throw new InvalidArgumentException('Upload chunks overlap.');
            }
            if ($stop === $offset) {
                $mergedStart = $start;
            } elseif ($end === $start) {
                $mergedEnd = $stop;
            } else {
                $next[] = [$start, $stop];
            }
        }
        $next[] = [$mergedStart, $mergedEnd];
        if (count($next) > self::MAX_RANGES) {
            throw new InvalidArgumentException('The upload has too many missing ranges.');
        }
        sort($next);

        $stream = @fopen($path, 'cb');
        if ($stream === false) {
            throw new RuntimeException('OpenCart could not store the upload.');
        }

        try {
            if (@fseek($stream, $offset) !== 0 || @fwrite($stream, $chunk) !== $bytes || !@fflush($stream)) {
                throw new RuntimeException('OpenCart could not write the upload chunk.');
            }
        } finally {
            fclose($stream);
        }

        // A failed write leaves these bytes unacknowledged and available for a retry.
        self::writeRanges($sidecar, $total, $next);
        return $end;
    }

    public static function complete(string $path, int $total): bool {
        clearstatcache(true, $path);
        return $total > 0 && !is_link($path) && is_file($path) && @filesize($path) === $total
            && self::readRanges($path . '.ranges', $total) === [[0, $total]];
    }

    private static function readRanges(string $sidecar, int $total): ?array {
        $contents = @file_get_contents($sidecar, false, null, 0, 2049);
        if ($contents === false || strlen($contents) > 2048) {
            return null;
        }
        $state = json_decode($contents);
        if (($state->total ?? null) !== $total || !is_array($state->ranges ?? null) || count($state->ranges) > self::MAX_RANGES) {
            return null;
        }

        $previousEnd = -1;
        foreach ($state->ranges as $range) {
            if (!is_array($range) || count($range) !== 2 || !is_int($range[0]) || !is_int($range[1])
                || $range[0] <= $previousEnd || $range[1] <= $range[0] || $range[1] > $total) {
                return null;
            }
            $previousEnd = $range[1];
        }
        return $state->ranges;
    }

    private static function writeRanges(string $sidecar, int $total, array $ranges): void {
        $pending = $sidecar . '.tmp';
        $contents = json_encode(['total' => $total, 'ranges' => $ranges]);
        if (@file_put_contents($pending, $contents) !== strlen($contents) || !@rename($pending, $sidecar)) {
            @unlink($pending);
            throw new RuntimeException('OpenCart could not save upload coverage.');
        }
    }
}
