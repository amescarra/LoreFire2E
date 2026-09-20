<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AppIconTest extends TestCase
{
    private function desktopRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * PNG IHDR width/height (big-endian) at bytes 16 and 20.
     *
     * @return array{0:int,1:int}
     */
    private function pngSize(string $path): array
    {
        $this->assertFileExists($path);
        $data = file_get_contents($path);
        $this->assertIsString($data);
        $this->assertGreaterThan(24, strlen($data));
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($data, 0, 8));
        $width = unpack('N', substr($data, 16, 4))[1];
        $height = unpack('N', substr($data, 20, 4))[1];

        return [$width, $height];
    }

    public function test_nativephp_icon_png_is_square_and_at_least_512(): void
    {
        [$width, $height] = $this->pngSize($this->desktopRoot().'/public/icon.png');
        $this->assertSame($width, $height);
        $this->assertGreaterThanOrEqual(512, $width);
        $this->assertSame(1024, $width);
    }

    public function test_windows_ico_is_multi_size_png_container(): void
    {
        $path = $this->desktopRoot().'/public/icon.ico';
        $this->assertFileExists($path);
        $data = file_get_contents($path);
        $this->assertIsString($data);

        $reserved = unpack('v', substr($data, 0, 2))[1];
        $type = unpack('v', substr($data, 2, 2))[1];
        $count = unpack('v', substr($data, 4, 2))[1];
        $this->assertSame(0, $reserved);
        $this->assertSame(1, $type, 'ICO type 1 is the Windows app icon (ARM64 and x64).');
        $this->assertGreaterThanOrEqual(6, $count);

        $sizes = [];
        $offset = 6;
        for ($i = 0; $i < $count; $i++) {
            $entry = substr($data, $offset, 16);
            $width = ord($entry[0]);
            $bytes = unpack('V', substr($entry, 8, 4))[1];
            $imageOffset = unpack('V', substr($entry, 12, 4))[1];
            $sizes[] = $width === 0 ? 256 : $width;
            $this->assertSame("\x89PNG", substr($data, $imageOffset, 4));
            $this->assertGreaterThan(100, $bytes);
            $offset += 16;
        }

        $this->assertContains(16, $sizes);
        $this->assertContains(32, $sizes);
        $this->assertContains(256, $sizes);
    }

    public function test_electron_builder_linux_png_sizes_exist(): void
    {
        $root = $this->desktopRoot();
        foreach ([16, 24, 32, 48, 64, 96, 128, 256, 512] as $size) {
            [$width, $height] = $this->pngSize($root.'/public/icons/'.$size.'x'.$size.'.png');
            $this->assertSame([$size, $size], [$width, $height]);
        }

        [$logoW, $logoH] = $this->pngSize($root.'/public/logo.png');
        $this->assertSame([128, 128], [$logoW, $logoH]);

        $this->assertFileExists($root.'/public/icon.icns');
        $this->assertSame('icns', substr((string) file_get_contents($root.'/public/icon.icns'), 0, 4));
        $this->assertFileExists($root.'/public/IconTemplate.png');
        $this->assertFileExists($root.'/public/IconTemplate@2x.png');
    }

    public function test_generator_documents_nativephp_copy_paths(): void
    {
        $src = file_get_contents($this->desktopRoot().'/scripts/generate-app-icons.py');
        $this->assertIsString($src);
        $this->assertStringContainsString('public/icon.png', $src);
        $this->assertStringContainsString('public/icon.ico', $src);
        $this->assertStringContainsString('vendor/nativephp/electron/resources/js/{build,resources}/icon.png', $src);
        $this->assertStringContainsString('Windows ARM64', $src);
        $this->assertStringContainsString('architecture-independent', $src);
    }

    public function test_nativephp_installs_app_icon_copies_public_png_and_ico(): void
    {
        $trait = $this->desktopRoot().'/vendor/nativephp/electron/src/Traits/InstallsAppIcon.php';
        if (! is_file($trait)) {
            $this->markTestSkipped('vendor/nativephp/electron is not installed.');
        }

        $src = file_get_contents($trait);
        $this->assertIsString($src);
        $this->assertStringContainsString("public_path(\$name)", $src);
        $this->assertStringContainsString("public_path('icon.png')", $src);
        $this->assertStringContainsString("'icon.ico'", $src);
        $this->assertStringContainsString("'icon.icns'", $src);
        $this->assertStringContainsString('build/icon.png', $src);
        $this->assertStringContainsString('resources/icon.png', $src);
        $this->assertStringContainsString('build/icon.ico', $src);
        $this->assertStringContainsString('resources/icon.ico', $src);
        $this->assertStringContainsString('RecursiveDirectoryIterator', $src);
        $this->assertStringContainsString('NATIVEPHP_APP_ICON', file_get_contents($this->desktopRoot().'/vendor/nativephp/electron/src/Traits/ExecuteCommand.php'));
        $this->assertStringContainsString('NATIVEPHP_APP_ICON', file_get_contents($this->desktopRoot().'/vendor/nativephp/electron/resources/js/src/main/index.js'));
    }

    public function test_native_app_icon_publish_writes_electron_resources_and_out(): void
    {
        $base = sys_get_temp_dir().'/lorefire-icon-'.bin2hex(random_bytes(4));
        $public = $base.'/public';
        $res = $base.'/vendor/nativephp/electron/resources/js/resources';
        $out = $base.'/vendor/nativephp/electron/resources/js/out/main';
        mkdir($public, 0777, true);
        mkdir($res, 0777, true);
        mkdir($out, 0777, true);
        copy($this->desktopRoot().'/public/icon.png', $public.'/icon.png');
        copy($this->desktopRoot().'/public/icon.ico', $public.'/icon.ico');
        file_put_contents($out.'/icon.png', 'stale-cog');

        try {
            $written = \App\Support\NativeAppIcon::publish($base);
            $this->assertNotEmpty($written);
            $this->assertFileEquals($public.'/icon.png', $res.'/icon.png');
            $this->assertFileEquals($public.'/icon.png', $out.'/icon.png');
            $this->assertFileEquals($public.'/icon.ico', $base.'/vendor/nativephp/electron/resources/js/resources/icon.ico');
            $this->assertSame('lorefire-2e-dev', \App\Support\NativeAppIcon::SERVE_WM_CLASS);
        } finally {
            $this->removeTree($base);
        }
    }

    public function test_env_example_and_desktop_leave_lorefire_dev_cog_name(): void
    {
        $root = $this->desktopRoot();
        $env = file_get_contents($root.'/.env.example');
        $composer = file_get_contents($root.'/composer.json');
        $this->assertIsString($env);
        $this->assertIsString($composer);
        $this->assertStringContainsString('APP_NAME=Lorefire 2E', $env);
        $this->assertStringContainsString('NATIVEPHP_APP_ID=com.lorefire.lorefire2e', $env);
        $this->assertStringContainsString('nativephp-electron-app-icon.patch', $composer);
        $this->assertStringContainsString("->title('Lorefire 2E')", file_get_contents($root.'/app/Providers/NativeAppServiceProvider.php'));
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
