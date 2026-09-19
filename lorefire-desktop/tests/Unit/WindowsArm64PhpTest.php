<?php

namespace Tests\Unit;

use App\Support\WindowsArm64Php;
use PHPUnit\Framework\TestCase;

class WindowsArm64PhpTest extends TestCase
{
    private ?string $previousExecutable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousExecutable = getenv('NATIVEPHP_PHP_EXECUTABLE') ?: null;
        putenv('NATIVEPHP_PHP_EXECUTABLE');
        unset($_ENV['NATIVEPHP_PHP_EXECUTABLE']);
    }

    protected function tearDown(): void
    {
        if ($this->previousExecutable) {
            putenv('NATIVEPHP_PHP_EXECUTABLE='.$this->previousExecutable);
            $_ENV['NATIVEPHP_PHP_EXECUTABLE'] = $this->previousExecutable;
        } else {
            putenv('NATIVEPHP_PHP_EXECUTABLE');
            unset($_ENV['NATIVEPHP_PHP_EXECUTABLE']);
        }
        parent::tearDown();
    }

    public function test_detects_windows_arm64_os_from_processor_env(): void
    {
        $this->assertTrue(WindowsArm64Php::osIsWindowsArm64('ARM64', '', 'Windows'));
        $this->assertTrue(WindowsArm64Php::osIsWindowsArm64('AMD64', 'ARM64', 'Windows'));
        $this->assertFalse(WindowsArm64Php::osIsWindowsArm64('AMD64', '', 'Windows'));
        $this->assertFalse(WindowsArm64Php::osIsWindowsArm64('ARM64', '', 'Linux'));
    }

    public function test_detects_arm_php_machine(): void
    {
        $this->assertTrue(WindowsArm64Php::phpIsArm64('ARM64'));
        $this->assertTrue(WindowsArm64Php::phpIsArm64('aarch64'));
        $this->assertFalse(WindowsArm64Php::phpIsArm64('AMD64'));
        $this->assertFalse(WindowsArm64Php::phpIsArm64('x86_64'));
    }

    public function test_serve_environment_is_empty_when_not_windows_arm_php(): void
    {
        if (PHP_OS_FAMILY === 'Windows' && WindowsArm64Php::phpIsArm64()) {
            $this->markTestSkipped('This host is Windows ARM PHP.');
        }

        $this->assertSame([], WindowsArm64Php::serveEnvironment());
        $this->assertNull(WindowsArm64Php::executable());
    }

    public function test_configured_executable_is_used_when_the_file_exists(): void
    {
        putenv('NATIVEPHP_PHP_EXECUTABLE='.PHP_BINARY);
        $_ENV['NATIVEPHP_PHP_EXECUTABLE'] = PHP_BINARY;

        $this->assertSame(PHP_BINARY, WindowsArm64Php::executable());
        $this->assertSame(
            ['NATIVEPHP_PHP_EXECUTABLE' => PHP_BINARY],
            WindowsArm64Php::serveEnvironment()
        );
    }

    public function test_missing_configured_path_is_ignored(): void
    {
        putenv('NATIVEPHP_PHP_EXECUTABLE=/definitely/missing/php.exe');
        $_ENV['NATIVEPHP_PHP_EXECUTABLE'] = '/definitely/missing/php.exe';

        if (PHP_OS_FAMILY === 'Windows' && WindowsArm64Php::phpIsArm64()) {
            $this->assertSame(PHP_BINARY, WindowsArm64Php::executable());
        } else {
            $this->assertNull(WindowsArm64Php::executable());
        }
    }

    public function test_electron_patch_skips_missing_win_arm64_zip(): void
    {
        $root = dirname(__DIR__, 2);
        $phpJs = $root.'/vendor/nativephp/electron/resources/js/php.js';
        $indexJs = $root.'/vendor/nativephp/electron/resources/js/src/main/index.js';
        $trait = $root.'/vendor/nativephp/electron/src/Traits/ExecuteCommand.php';
        $patch = $root.'/patches/nativephp-electron-windows-arm64-system-php.patch';

        // Git source of truth. cweagans does not re-apply a changed patch
        // onto an already-installed nativephp/electron, so a pull without
        // `composer install` can leave vendor on the older Windows-ARM-only
        // hunks. Linux markers are asserted here so composer test stays
        // green after pull; vendor Linux hunks are checked when present.
        $this->assertFileExists($patch);
        $patchSrc = file_get_contents($patch);
        $this->assertStringContainsString('linuxX64SystemPhpWhenBinMissing', $patchSrc);
        $this->assertStringContainsString('nativephpPhpBinaryVersion', $patchSrc);
        $this->assertStringContainsString('linuxX64Serve', $patchSrc);
        $this->assertStringContainsString('windowsArm64SystemPhp', $patchSrc);
        $this->assertStringContainsString('winArmServe', $patchSrc);

        $this->assertFileExists($phpJs);
        $phpJsSrc = file_get_contents($phpJs);
        $this->assertStringContainsString('NATIVEPHP_PHP_EXECUTABLE', $phpJsSrc);
        $this->assertStringContainsString('winArmServe', $phpJsSrc);
        $this->assertStringContainsString('Packaged Windows ARM64 is blocked', $phpJsSrc);

        $this->assertFileExists($indexJs);
        $indexSrc = file_get_contents($indexJs);
        $this->assertStringContainsString('NATIVEPHP_PHP_EXECUTABLE', $indexSrc);
        $this->assertStringContainsString('php.exe from PATH', $indexSrc);
        $this->assertStringContainsString('Windows ARM64: launching system PHP', $indexSrc);

        $this->assertFileExists($trait);
        $traitSrc = file_get_contents($trait);
        $this->assertStringContainsString('windowsArm64SystemPhp', $traitSrc);

        if (str_contains($traitSrc, 'linuxX64SystemPhpWhenBinMissing')) {
            $this->assertStringContainsString('nativephpPhpBinaryVersion', $traitSrc);
            $this->assertStringContainsString('linuxX64Serve', $phpJsSrc);
            $this->assertStringContainsString('Linux: launching system PHP', $indexSrc);
        }
    }
}
