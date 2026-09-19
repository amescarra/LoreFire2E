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

        $this->assertFileExists($phpJs);
        $this->assertStringContainsString('NATIVEPHP_PHP_EXECUTABLE', file_get_contents($phpJs));
        $this->assertStringContainsString('winArmServe', file_get_contents($phpJs));
        $this->assertStringContainsString('Packaged Windows ARM64 is blocked', file_get_contents($phpJs));

        $this->assertFileExists($indexJs);
        $this->assertStringContainsString('NATIVEPHP_PHP_EXECUTABLE', file_get_contents($indexJs));
        $this->assertStringContainsString('php.exe from PATH', file_get_contents($indexJs));

        $this->assertFileExists($trait);
        $traitSrc = file_get_contents($trait);
        $this->assertStringContainsString('windowsArm64SystemPhp', $traitSrc);
        $this->assertStringContainsString('linuxX64SystemPhpWhenBinMissing', $traitSrc);
        $this->assertStringContainsString('nativephpPhpBinaryVersion', $traitSrc);
        $this->assertStringContainsString('winArmServe', file_get_contents($phpJs));
        $this->assertStringContainsString('linuxX64Serve', file_get_contents($phpJs));
        $this->assertStringContainsString('Linux: launching system PHP', file_get_contents($indexJs));
        $this->assertStringContainsString('Windows ARM64: launching system PHP', file_get_contents($indexJs));
    }
}
