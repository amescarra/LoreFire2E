<?php

namespace App\Support;

/**
 * Publish the G3 fire/book icon into NativePHP Electron paths.
 *
 * native:serve copies via InstallsAppIcon, but electron-vite can keep a stale
 * cog in resources/ or out/. Call this before every serve (Linux launcher
 * and the patched InstallsAppIcon). Same PNG/ICO on Windows ARM — no arch split.
 */
class NativeAppIcon
{
    /** NativePHP slugs APP_NAME and appends -dev in development. */
    public const SERVE_WM_CLASS = 'lorefire-2e-dev';

    public const DISPLAY_NAME = 'Lorefire 2E';

    /**
     * @return list<string> destinations written
     */
    public static function publish(?string $basePath = null): array
    {
        $base = $basePath ?? base_path();
        $public = $base.'/public';
        $js = $base.'/vendor/nativephp/electron/resources/js';
        $written = [];

        $pairs = [
            ['icon.png', $js.'/build/icon.png'],
            ['icon.png', $js.'/resources/icon.png'],
            ['icon.ico', $js.'/build/icon.ico'],
            ['icon.ico', $js.'/resources/icon.ico'],
            ['icon.icns', $js.'/build/icon.icns'],
            ['icon.icns', $js.'/resources/icon.icns'],
            ['IconTemplate.png', $js.'/resources/IconTemplate.png'],
            ['IconTemplate@2x.png', $js.'/resources/IconTemplate@2x.png'],
        ];

        foreach ($pairs as [$name, $dest]) {
            $src = $public.'/'.$name;
            if (! is_file($src)) {
                continue;
            }
            $dir = dirname($dest);
            if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
                continue;
            }
            if (@copy($src, $dest)) {
                $written[] = $dest;
            }
        }

        $master = $public.'/icon.png';
        if (is_file($master) && is_dir($js.'/out')) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($js.'/out', \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && strcasecmp($file->getFilename(), 'icon.png') === 0) {
                    if (@copy($master, $file->getPathname())) {
                        $written[] = $file->getPathname();
                    }
                }
            }
        }

        return $written;
    }

    public static function publicPng(?string $basePath = null): string
    {
        return ($basePath ?? base_path()).'/public/icon.png';
    }
}
