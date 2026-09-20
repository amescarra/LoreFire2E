<?php

namespace Tests\Unit;

use App\Models\AppSetting;
use App\Support\Linux5090;
use App\Support\WhisperxLanguages;
use App\Support\WindowsArm64Php;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Linux5090Test extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Linux5090::resetProbes();
        putenv('LOREFIRE_LINUX5090_CUDA');
        putenv('LOREFIRE_LINUX5090_VRAM_MB');
        parent::tearDown();
    }

    public function test_detects_linux_x86_and_rejects_windows_arm(): void
    {
        $this->assertTrue(Linux5090::isLinuxX86('Linux', 'x86_64'));
        $this->assertTrue(Linux5090::isLinuxX86('Linux', 'amd64'));
        $this->assertFalse(Linux5090::isLinuxX86('Linux', 'aarch64'));
        $this->assertFalse(Linux5090::isLinuxX86('Windows', 'x86_64'));
        $this->assertFalse(Linux5090::isLinuxX86('Darwin', 'arm64'));

        $this->assertTrue(Linux5090::isWindowsArmPath('Windows', 'ARM64', ''));
        $this->assertFalse(Linux5090::isWindowsArmPath('Linux', 'ARM64', ''));
        $this->assertFalse(WindowsArm64Php::osIsWindowsArm64('ARM64', '', 'Linux'));
    }

    public function test_cuda_stack_requires_linux_x86_and_nvidia(): void
    {
        Linux5090::$cudaProbe = fn () => true;
        putenv('LOREFIRE_LINUX5090_VRAM_MB=32607');

        if (PHP_OS_FAMILY === 'Linux' && in_array(php_uname('m'), ['x86_64', 'amd64'], true)) {
            $this->assertTrue(Linux5090::shouldUseCudaStack());
            $this->assertSame(32, Linux5090::whisperBatchSize());
            $this->assertSame('large-v3', Linux5090::whisperModelDefault());
            $this->assertSame('qwen2.5:32b', Linux5090::ollamaModelDefault());
            $this->assertSame([
                '--device', 'cuda',
                '--compute-type', 'float16',
                '--batch-size', '32',
            ], Linux5090::whisperxCliArgs());
            $this->assertSame(8192, Linux5090::ollamaOptions()['num_ctx']);
        } else {
            $this->assertFalse(Linux5090::shouldUseCudaStack());
            $this->assertSame([], Linux5090::whisperxCliArgs());
            $this->assertSame([], Linux5090::ollamaOptions());
        }
    }

    public function test_cuda_override_off_never_injects_gpu_flags(): void
    {
        putenv('LOREFIRE_LINUX5090_CUDA=0');
        Linux5090::$cudaProbe = fn () => true;

        $this->assertFalse(Linux5090::shouldUseCudaStack());
        $this->assertSame([], Linux5090::whisperxCliArgs());
        $this->assertSame([], Linux5090::ollamaOptions());

        $cmd = WhisperxLanguages::command('python', 'run_whisperx.py', 'a.webm', 'out.json');
        $this->assertNotContains('cuda', $cmd);
        $this->assertNotContains('--batch-size', $cmd);
    }

    public function test_apply_defaults_only_when_cuda_and_keys_missing(): void
    {
        putenv('LOREFIRE_LINUX5090_CUDA=0');
        Linux5090::applyRuntimeDefaults();
        $this->assertNull(AppSetting::get('whisperx_model'));
        $this->assertNull(AppSetting::get('llm_provider'));

        if (! Linux5090::isLinuxX86()) {
            $this->markTestSkipped('Defaults apply only on Linux x86_64 hosts.');
        }

        putenv('LOREFIRE_LINUX5090_CUDA=1');
        putenv('LOREFIRE_LINUX5090_VRAM_MB=32607');
        Linux5090::applyRuntimeDefaults();

        $this->assertSame('large-v3', AppSetting::get('whisperx_model'));
        $this->assertSame('ollama', AppSetting::get('llm_provider'));
        $this->assertSame('qwen2.5:32b', AppSetting::get('ollama_model'));

        AppSetting::set('whisperx_model', 'tiny');
        Linux5090::applyRuntimeDefaults();
        $this->assertSame('tiny', AppSetting::get('whisperx_model'));
    }

    public function test_ollama_payload_unchanged_without_cuda(): void
    {
        putenv('LOREFIRE_LINUX5090_CUDA=0');
        $payload = ['model' => 'llama3', 'stream' => false];

        $this->assertSame($payload, Linux5090::withOllamaOptions($payload));
    }

    public function test_whisperx_requires_cpython_312_on_linux_x86(): void
    {
        $this->assertStringContainsString('deadsnakes', Linux5090::missingPython312Message());
        $this->assertStringContainsString('python3.12-venv', Linux5090::DEADSNAKES_INSTALL);

        if (Linux5090::isLinuxX86()) {
            $this->assertTrue(Linux5090::whisperxNeedsCpython312());
            Linux5090::$python312Probe = fn () => false;
            $this->assertFalse(Linux5090::hasWhisperxCpython312());
        } else {
            $this->assertFalse(Linux5090::whisperxNeedsCpython312());
        }
    }

    public function test_windows_arm_scripts_are_untouched(): void
    {
        $root = dirname(__DIR__, 2);
        $ps1 = file_get_contents($root.'/resources/python/setup.ps1');
        $serve = file_get_contents($root.'/scripts/native-serve.ps1');
        $armDoc = file_get_contents($root.'/WINDOWS-ARM.md');

        $this->assertIsString($ps1);
        $this->assertStringContainsString('CUDA 11.8, optional GPU path', $ps1);
        $this->assertStringContainsString('torch==2.5.1', $ps1);
        $this->assertStringNotContainsString('cu128', $ps1);
        $this->assertStringNotContainsString('linux-5090', $ps1);
        $this->assertStringNotContainsString('qwen2.5:32b', $ps1);

        $this->assertIsString($serve);
        $this->assertStringContainsString('Windows ARM64', $serve);
        $this->assertStringContainsString('Keep ARM64 Node', $serve);
        $this->assertStringNotContainsString('linux-5090', $serve);

        $this->assertIsString($armDoc);
        $this->assertStringContainsString('ARM-native', $armDoc);
        $this->assertStringContainsString('winget install PHP.PHP.8.4', $armDoc);
        $this->assertStringNotContainsString('cu128', $armDoc);
    }

    public function test_setup_sh_has_linux_5090_cuda_path_and_cpu_flag(): void
    {
        $sh = file_get_contents(dirname(__DIR__, 2).'/resources/python/setup.sh');
        $this->assertIsString($sh);
        $this->assertStringContainsString('--cpu', $sh);
        $this->assertStringContainsString('linux-5090', $sh);
        $this->assertStringContainsString('cu128', $sh);
        $this->assertStringContainsString('requirements-linux-5090.txt', $sh);
        $this->assertStringContainsString('CUDA 11.8, optional GPU path', $sh);
        $this->assertStringContainsString('torch==2.5.1', $sh);
        $this->assertStringContainsString('pip==24.0', $sh);
        $this->assertStringContainsString('python3.12', $sh);
        $this->assertStringContainsString('Do not fall back to python3 / 3.14', $sh);
        $this->assertStringContainsString('ppa:deadsnakes/ppa', $sh);
        $req5090 = file_get_contents(dirname(__DIR__, 2).'/resources/python/requirements-linux-5090.txt');
        $this->assertIsString($req5090);
        $this->assertDoesNotMatchRegularExpression('/^ctranslate2>=/m', $req5090);
        $this->assertStringContainsString('ctranslate2==4.4.0', $req5090);
        $this->assertFileExists(dirname(__DIR__, 2).'/LINUX-5090.md');
        $this->assertFileExists(dirname(__DIR__, 2).'/scripts/linux-5090-setup.sh');
        $this->assertFileExists(dirname(__DIR__, 2).'/scripts/linux-5090-detect.sh');
        $this->assertFileExists(dirname(__DIR__, 2).'/scripts/linux-5090-audio.sh');
    }

    public function test_setup_docs_prefer_archive_php_and_skip_ondrej_on_resolute(): void
    {
        $root = dirname(__DIR__, 2);
        $md = file_get_contents($root.'/LINUX-5090.md');
        $sh = file_get_contents($root.'/scripts/linux-5090-setup.sh');
        $this->assertIsString($md);
        $this->assertIsString($sh);

        $this->assertStringContainsString('resolute', $md);
        $this->assertStringContainsString('php-cli php-xml php-mbstring', $md);
        $this->assertStringContainsString('Do **not** add `ppa:ondrej/php`', $md);
        $this->assertStringContainsString('ondrej-ubuntu-php-*.list', $md);
        $this->assertStringContainsString('Noble (24.04) and Jammy (22.04) only', $md);
        $this->assertStringContainsString('3.14', $md);
        $this->assertStringContainsString('deadsnakes', $md);
        $this->assertStringContainsString('Python 3.12 is required for WhisperX', $md);
        $this->assertStringContainsString('ppa:deadsnakes/ppa', $md);
        $this->assertStringContainsString('python3.12 python3.12-venv python3.12-dev', $md);

        $this->assertStringContainsString('ppa:deadsnakes/ppa', $sh);
        $this->assertStringContainsString('ubuntu_codename', $sh);
        $this->assertStringContainsString('print_php_install_help', $sh);
        $this->assertStringContainsString('jammy|noble', $sh);
        $this->assertStringContainsString('Do NOT add ppa:ondrej/php', $sh);
        $this->assertStringContainsString('ondrej-ubuntu-php-*.sources', $sh);
        $this->assertStringContainsString('php-cli php-xml php-mbstring php-sqlite3', $sh);
        $this->assertDoesNotMatchRegularExpression(
            '/composer\.lock needs PHP 8\.4\+[\s\S]{0,120}add-apt-repository -y ppa:ondrej\/php/',
            $sh,
            'PHP 8.4 help must not unconditionally tell every Ubuntu (including resolute) to add ondrej/php.'
        );
    }

    public function test_linux_setup_creates_sqlite_and_migrates_before_whisperx(): void
    {
        $root = dirname(__DIR__, 2);
        $sh = file_get_contents($root.'/scripts/linux-5090-setup.sh');
        $md = file_get_contents($root.'/LINUX-5090.md');
        $ps1 = file_get_contents($root.'/resources/python/setup.ps1');
        $this->assertIsString($sh);
        $this->assertIsString($md);
        $this->assertIsString($ps1);

        $touchPos = strpos($sh, 'touch database/database.sqlite');
        $migratePos = strpos($sh, 'php artisan migrate --force');
        $venvPos = strpos($sh, 'WhisperX venv');
        $this->assertNotFalse($touchPos);
        $this->assertNotFalse($migratePos);
        $this->assertNotFalse($venvPos);
        $this->assertLessThan($migratePos, $touchPos);
        $this->assertLessThan($venvPos, $migratePos);
        $this->assertStringContainsString('touch database/nativephp.sqlite', $sh);
        $this->assertStringContainsString('Do not set DB_DATABASE to nativephp.sqlite', $sh);

        $this->assertStringContainsString('database/database.sqlite', $md);
        $this->assertStringContainsString('database/nativephp.sqlite', $md);

        $this->assertStringNotContainsString('touch database/database.sqlite', $ps1);
        $this->assertStringNotContainsString('linux-5090', $ps1);
    }

    public function test_php_bin_linux_x64_requests_8_4_when_8_5_zip_missing(): void
    {
        $this->assertSame(
            '/tmp/php-bin/bin/linux/x64/php-8.5.zip',
            Linux5090::phpBinLinuxX64ZipPath('/tmp/php-bin/bin', '8.5')
        );
        $this->assertFalse(Linux5090::phpBinHasLinuxX64Zip('/tmp/definitely-missing-php-bin', '8.5'));

        $dir = sys_get_temp_dir().'/lorefire-php-bin-'.bin2hex(random_bytes(4)).'/bin';
        mkdir($dir.'/linux/x64', 0777, true);
        try {
            file_put_contents($dir.'/linux/x64/php-8.3.zip', 'x');
            file_put_contents($dir.'/linux/x64/php-8.4.zip', 'x');

            $this->assertSame('8.4', Linux5090::phpBinServeVersion($dir, '8.5', 'Linux', 'x86_64'));
            $this->assertSame('8.4', Linux5090::phpBinServeVersion($dir, '8.4', 'Linux', 'x86_64'));
            $this->assertSame('8.5', Linux5090::phpBinServeVersion($dir, '8.5', 'Windows', 'x86_64'));
            $this->assertSame('8.5', Linux5090::phpBinServeVersion($dir, '8.5', 'Linux', 'aarch64'));
            $this->assertFalse(Linux5090::shouldUseSystemPhpForServe('Linux', 'x86_64', $dir));

            unlink($dir.'/linux/x64/php-8.4.zip');
            $this->assertSame('8.3', Linux5090::phpBinServeVersion($dir, '8.5', 'Linux', 'x86_64'));

            unlink($dir.'/linux/x64/php-8.3.zip');
            $this->assertSame('8.5', Linux5090::phpBinServeVersion($dir, '8.5', 'Linux', 'x86_64'));
            $this->assertTrue(Linux5090::shouldUseSystemPhpForServe('Linux', 'x86_64', $dir));
        } finally {
            @unlink($dir.'/linux/x64/php-8.3.zip');
            @unlink($dir.'/linux/x64/php-8.4.zip');
            @rmdir($dir.'/linux/x64');
            @rmdir($dir.'/linux');
            @rmdir($dir);
        }

        $this->assertFalse(Linux5090::shouldUseSystemPhpForServe('Linux', 'aarch64'));
        $this->assertFalse(Linux5090::shouldUseSystemPhpForServe('Windows', 'x86_64'));

        $previous = getenv('NATIVEPHP_PHP_EXECUTABLE') ?: null;
        $previousVer = getenv('NATIVEPHP_PHP_BINARY_VERSION') ?: null;
        putenv('NATIVEPHP_PHP_EXECUTABLE');
        putenv('NATIVEPHP_PHP_BINARY_VERSION');
        unset($_ENV['NATIVEPHP_PHP_EXECUTABLE'], $_ENV['NATIVEPHP_PHP_BINARY_VERSION']);

        try {
            $this->assertNull(Linux5090::servePhpExecutable('Windows', 'ARM64', '/tmp/missing-php-bin'));

            if (Linux5090::isLinuxX86() && is_file(PHP_BINARY)) {
                $this->assertSame(
                    PHP_BINARY,
                    Linux5090::servePhpExecutable('Linux', 'x86_64', '/tmp/definitely-missing-php-bin')
                );
            }
        } finally {
            if ($previous) {
                putenv('NATIVEPHP_PHP_EXECUTABLE='.$previous);
                $_ENV['NATIVEPHP_PHP_EXECUTABLE'] = $previous;
            } else {
                putenv('NATIVEPHP_PHP_EXECUTABLE');
                unset($_ENV['NATIVEPHP_PHP_EXECUTABLE']);
            }
            if ($previousVer) {
                putenv('NATIVEPHP_PHP_BINARY_VERSION='.$previousVer);
                $_ENV['NATIVEPHP_PHP_BINARY_VERSION'] = $previousVer;
            } else {
                putenv('NATIVEPHP_PHP_BINARY_VERSION');
                unset($_ENV['NATIVEPHP_PHP_BINARY_VERSION']);
            }
        }
    }

    public function test_nativephp_php_bin_docs_and_electron_patch_cover_linux_8_5(): void
    {
        $root = dirname(__DIR__, 2);
        $md = file_get_contents($root.'/LINUX-5090.md');
        $sh = file_get_contents($root.'/scripts/linux-5090-setup.sh');
        $composer = file_get_contents($root.'/composer.json');
        $patch = file_get_contents($root.'/patches/nativephp-electron-windows-arm64-system-php.patch');
        $this->assertIsString($md);
        $this->assertIsString($sh);
        $this->assertIsString($composer);
        $this->assertIsString($patch);

        $this->assertStringContainsString('php-8.5.zip', $md);
        $this->assertStringContainsString('php-8.3.zip`, `php-8.4.zip` only', $md);
        $this->assertStringContainsString('NATIVEPHP_PHP_BINARY_VERSION', $md);
        $this->assertStringContainsString('Laravel config for the zip version', $md);
        $this->assertStringContainsString('selects `NATIVEPHP_PHP_BINARY_VERSION=8.4`', $md);
        $this->assertStringContainsString('ln -sfn php-8.4.zip php-8.5.zip', $md);
        $this->assertStringContainsString('if [ -L "$zip" ]; then rm -f "$zip"; fi', $md);
        $this->assertStringContainsString('win/arm64', $md);

        $this->assertStringContainsString('NATIVEPHP_PHP_BINARY_VERSION=8.4', $sh);
        $this->assertStringContainsString('Removing temp symlink', $sh);
        $this->assertStringContainsString('Do not ln -s php-8.4.zip php-8.5.zip', $sh);
        $this->assertStringContainsString('vendor/nativephp/php-bin/bin/linux/x64', $sh);

        $this->assertStringContainsString('"nativephp/php-bin": "^1.2"', $composer);

        $this->assertStringContainsString('linuxX64Serve', $patch);
        $this->assertStringContainsString('nativephpPhpBinaryVersion', $patch);
        $this->assertStringContainsString('linuxX64SystemPhpWhenBinMissing', $patch);
        $this->assertStringContainsString('winArmServe', $patch);
        $this->assertStringContainsString('windowsArm64SystemPhp', $patch);
        $this->assertStringContainsString('using php-\' + fallback + \'.zip', $patch);
        $this->assertStringContainsString('Packaged Windows ARM64 is blocked', $patch);
        $this->assertStringContainsString('php.exe on PATH', $patch);

        $this->assertStringContainsString('chrome-sandbox', $md);
        $this->assertStringContainsString('sudo chown root:root', $md);
        $this->assertStringContainsString('sudo chmod 4755', $md);
        $this->assertStringContainsString('ELECTRON_DISABLE_SANDBOX=1', $md);
        $this->assertStringContainsString('setuid_sandbox_host', $md);
        $this->assertStringContainsString('chrome-sandbox', $sh);
        $this->assertStringContainsString('sudo chown root:root', $sh);
        $this->assertStringContainsString('sudo chmod 4755', $sh);
        $this->assertStringContainsString('ELECTRON_DISABLE_SANDBOX=1', $sh);
        $this->assertStringContainsString('print_chrome_sandbox_help', $sh);
        $armDoc = file_get_contents($root.'/WINDOWS-ARM.md');
        $this->assertIsString($armDoc);
        $this->assertStringNotContainsString('ELECTRON_DISABLE_SANDBOX', $armDoc);
        $this->assertStringNotContainsString('chrome-sandbox', $armDoc);
    }

    public function test_linux_desktop_launcher_cds_and_serves_with_installed_icon(): void
    {
        $root = dirname(__DIR__, 2);
        $launch = file_get_contents($root.'/scripts/linux-5090-launch.sh');
        $desktop = file_get_contents($root.'/scripts/lorefire-2e.desktop');
        $md = file_get_contents($root.'/LINUX-5090.md');
        $setup = file_get_contents($root.'/scripts/linux-5090-setup.sh');
        $arm = file_get_contents($root.'/WINDOWS-ARM.md');
        $ps1 = file_get_contents($root.'/scripts/native-serve.ps1');
        $this->assertIsString($launch);
        $this->assertIsString($desktop);
        $this->assertIsString($md);
        $this->assertIsString($setup);
        $this->assertIsString($arm);
        $this->assertIsString($ps1);

        $this->assertStringContainsString('cd "$DESKTOP"', $launch);
        $this->assertStringContainsString('php artisan native:serve', $launch);
        $this->assertStringContainsString('exec php artisan native:serve', $launch);
        $this->assertStringContainsString('[ -t 0 ]', $launch);
        $this->assertStringContainsString('script -qefc', $launch);
        $this->assertStringContainsString('native-serve.desktop.log', $launch);
        $this->assertStringContainsString('/dev/tty', $launch);
        $this->assertStringContainsString('public/icon.png', $launch);
        $this->assertStringContainsString('ELECTRON_DISABLE_SANDBOX=1', $launch);
        $this->assertStringContainsString('chrome-sandbox', $launch);
        $this->assertStringContainsString('sudo chown root:root', $launch);
        $this->assertStringContainsString('sudo chmod 4755', $launch);
        $this->assertStringContainsString('--install-desktop', $launch);
        $this->assertStringContainsString('lorefire-2e.desktop', $launch);
        $this->assertStringContainsString('native-serve.ps1', $launch);
        $this->assertStringContainsString('ICON="$DESKTOP/public/icon.png"', $launch);

        $this->assertStringContainsString('Name=Lorefire 2E', $desktop);
        $this->assertStringContainsString('Icon=../public/icon.png', $desktop);
        $this->assertStringContainsString('linux-5090-launch.sh', $desktop);
        $this->assertStringContainsString('Type=Application', $desktop);

        $this->assertStringContainsString('linux-5090-launch.sh', $md);
        $this->assertStringContainsString('lorefire-2e.desktop', $md);
        $this->assertStringContainsString('script -qefc', $md);
        $this->assertStringContainsString('/dev/tty', $md);
        $this->assertStringContainsString('exec php artisan native:serve', $md);
        $this->assertStringContainsString('public/icon.png', $md);
        $this->assertStringContainsString('public/icon.ico', $md);
        $this->assertStringContainsString('generate-app-icons.py', $md);
        $this->assertStringContainsString('--install-desktop', $md);

        $this->assertStringContainsString('linux-5090-launch.sh', $setup);
        $this->assertStringContainsString('--install-desktop', $setup);

        $this->assertStringContainsString('public/icon.ico', $arm);
        $this->assertStringNotContainsString('ELECTRON_DISABLE_SANDBOX', $arm);
        $this->assertStringNotContainsString('chrome-sandbox', $arm);
        $this->assertStringNotContainsString('linux-5090-launch.sh', $ps1);
        $this->assertStringNotContainsString('script -qefc', $arm);
        $this->assertStringNotContainsString('script -qefc', $ps1);
        $this->assertStringContainsString('php artisan native:serve', $ps1);
    }
}
