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
}
