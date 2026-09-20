<?php

namespace App\Support;

use Symfony\Component\Process\Process;

/**
 * Dedicated LoreFire temp under storage (not unbounded /tmp).
 *
 * Live WhisperX used to drop ~77MB lorefire_ffmpeg_* dirs in tmpfs on every
 * slice. PHP uploads also land in sys_get_temp_dir() — when that fills,
 * chunk writes stall while the Live timer keeps running.
 */
class AppTemp
{
    public const FFMPEG_DIR_PREFIX = 'lorefire_ffmpeg_';

    public const ENROLL_PREFIX = 'lorefire-enroll-';

    public const DEFAULT_MAX_AGE = 600;

    public const DEFAULT_MAX_BYTES = 536870912;

    public static function root(): string
    {
        $override = getenv('LOREFIRE_TMP') ?: ($_ENV['LOREFIRE_TMP'] ?? null);
        $path = is_string($override) && $override !== ''
            ? $override
            : storage_path('app/tmp');

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        return $path;
    }

    /**
     * Point PHP / child processes at the app temp so ffmpeg leftovers and
     * upload scratch do not fill a 15G tmpfs.
     */
    public static function install(): void
    {
        $root = self::root();
        putenv('LOREFIRE_TMP='.$root);
        $_ENV['LOREFIRE_TMP'] = $root;
        putenv('TMPDIR='.$root);
        $_ENV['TMPDIR'] = $root;
        @ini_set('upload_tmp_dir', $root);
        @ini_set('sys_temp_dir', $root);
    }

    /**
     * Extra env keys for Symfony Process (merged with the inherited env).
     *
     * @return array<string, string>
     */
    public static function processEnv(): array
    {
        $root = self::root();

        return [
            'LOREFIRE_TMP' => $root,
            'TMPDIR' => $root,
            'TMP' => $root,
            'TEMP' => $root,
        ];
    }

    public static function applyToProcess(Process $process): void
    {
        $process->setEnv(self::processEnv());
    }

    /**
     * Remove leftover lorefire_ffmpeg_* / lorefire-enroll-* under system temp
     * and the app temp. Returns the number of paths removed.
     */
    public static function sweep(int $maxAgeSeconds = self::DEFAULT_MAX_AGE, int $maxBytes = self::DEFAULT_MAX_BYTES): int
    {
        $now = time();
        $removed = 0;

        foreach (self::candidateTempRoots() as $root) {
            $entries = @scandir($root);
            if (! is_array($entries)) {
                continue;
            }
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                if (! str_starts_with($name, self::FFMPEG_DIR_PREFIX) && ! str_starts_with($name, self::ENROLL_PREFIX)) {
                    continue;
                }
                $path = $root.DIRECTORY_SEPARATOR.$name;
                $mtime = @filemtime($path) ?: 0;
                if ($maxAgeSeconds > 0 && ($now - $mtime) < $maxAgeSeconds) {
                    continue;
                }
                if (self::removePath($path)) {
                    $removed++;
                }
            }
        }

        $appRoot = self::root();
        $alias = $appRoot.DIRECTORY_SEPARATOR.'ffmpeg-alias';
        $work = [];
        $total = 0;
        $entries = @scandir($appRoot);
        if (is_array($entries)) {
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..' || $name === 'ffmpeg-alias') {
                    continue;
                }
                if (str_starts_with($name, self::FFMPEG_DIR_PREFIX) || str_starts_with($name, self::ENROLL_PREFIX)) {
                    continue;
                }
                $path = $appRoot.DIRECTORY_SEPARATOR.$name;
                if (realpath($path) && realpath($alias) && realpath($path) === realpath($alias)) {
                    continue;
                }
                $mtime = @filemtime($path) ?: 0;
                $age = $now - $mtime;
                $size = is_dir($path) ? self::dirSize($path) : (int) (@filesize($path) ?: 0);
                if ($maxAgeSeconds > 0 && $age >= $maxAgeSeconds && in_array($name, ['enroll', 'work'], true)) {
                    if (self::removePath($path)) {
                        $removed++;
                    }

                    continue;
                }
                $work[] = [$age, $size, $path];
                $total += $size;
            }
        }

        if ($total > $maxBytes) {
            usort($work, fn ($a, $b) => $b[0] <=> $a[0]);
            foreach ($work as [$age, $size, $path]) {
                if (self::removePath($path)) {
                    $removed++;
                }
                $total -= $size;
                if ($total <= $maxBytes) {
                    break;
                }
            }
        }

        return $removed;
    }

    /**
     * @return list<string>
     */
    public static function candidateTempRoots(): array
    {
        $seen = [];
        $out = [];
        foreach ([
            sys_get_temp_dir(),
            getenv('TMPDIR') ?: null,
            getenv('TMP') ?: null,
            getenv('TEMP') ?: null,
            '/tmp',
            self::root(),
        ] as $raw) {
            if (! is_string($raw) || $raw === '' || ! is_dir($raw)) {
                continue;
            }
            $real = realpath($raw) ?: $raw;
            if (isset($seen[$real])) {
                continue;
            }
            $seen[$real] = true;
            $out[] = $real;
        }

        return $out;
    }

    public static function removePath(string $path): bool
    {
        if (! file_exists($path) && ! is_link($path)) {
            return false;
        }
        if (is_dir($path) && ! is_link($path)) {
            $items = @scandir($path);
            if (is_array($items)) {
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') {
                        continue;
                    }
                    self::removePath($path.DIRECTORY_SEPARATOR.$item);
                }
            }

            return @rmdir($path);
        }

        return @unlink($path);
    }

    public static function dirSize(string $path): int
    {
        $total = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $total += (int) $file->getSize();
            }
        }

        return $total;
    }
}
