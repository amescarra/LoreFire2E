<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Services\PythonSetupService;
use App\Support\Linux5090;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PythonSetupServiceTest extends TestCase
{
    use RefreshDatabase;

    private PythonSetupService $service;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PythonSetupService::class);
        $this->logPath = $this->service->setupLogPath();
        if (! is_dir(dirname($this->logPath))) {
            mkdir(dirname($this->logPath), 0755, true);
        }
        if (file_exists($this->logPath)) {
            unlink($this->logPath);
        }
        $console = $this->service->setupConsoleLogPath();
        if (file_exists($console)) {
            unlink($console);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logPath)) {
            unlink($this->logPath);
        }
        $console = $this->service->setupConsoleLogPath();
        if (file_exists($console)) {
            unlink($console);
        }
        Linux5090::resetProbes();
        parent::tearDown();
    }

    public function test_missing_setup_script_marks_failed_with_error(): void
    {
        $service = new class extends PythonSetupService
        {
            public function setupScriptPath(): string
            {
                return '/tmp/lorefire-missing-setup-script.sh';
            }
        };

        $service->runSetup();

        $this->assertSame(PythonSetupService::STATUS_FAILED, AppSetting::get(PythonSetupService::SETTING_STATUS));
        $this->assertStringContainsString('Setup script not found', (string) AppSetting::get(PythonSetupService::SETTING_ERROR));
    }

    public function test_async_missing_script_does_not_stay_running(): void
    {
        $service = new class extends PythonSetupService
        {
            public function setupScriptPath(): string
            {
                return '/tmp/lorefire-missing-setup-script.sh';
            }
        };

        $service->runSetupAsync();

        $this->assertSame(PythonSetupService::STATUS_FAILED, AppSetting::get(PythonSetupService::SETTING_STATUS));
        $this->assertNotSame(PythonSetupService::STATUS_RUNNING, $service->getStatus());
    }

    public function test_watchdog_fails_legacy_running_with_no_log(): void
    {
        AppSetting::set(PythonSetupService::SETTING_STATUS, PythonSetupService::STATUS_RUNNING);
        AppSetting::set(PythonSetupService::SETTING_ERROR, '');

        $this->assertSame(PythonSetupService::STATUS_FAILED, $this->service->getStatus());
        $this->assertStringContainsString('no progress', (string) $this->service->getLastError());
    }

    public function test_watchdog_fails_when_hard_timeout_elapsed(): void
    {
        AppSetting::set(PythonSetupService::SETTING_STATUS, PythonSetupService::STATUS_RUNNING);
        AppSetting::set(PythonSetupService::SETTING_STARTED_AT, time() - PythonSetupService::SETUP_TIMEOUT_SECONDS - 10, 'integer');
        file_put_contents($this->logPath, "==> Installing WhisperX and dependencies...\npip still going?\n");

        $this->assertSame(PythonSetupService::STATUS_FAILED, $this->service->getStatus());
        $this->assertStringContainsString('timed out', (string) $this->service->getLastError());
        $this->assertStringContainsString('Installing WhisperX', (string) $this->service->getLastError());
    }

    public function test_watchdog_fails_when_log_stops_growing(): void
    {
        AppSetting::set(PythonSetupService::SETTING_STATUS, PythonSetupService::STATUS_RUNNING);
        AppSetting::set(
            PythonSetupService::SETTING_STARTED_AT,
            time() - PythonSetupService::STALE_LOG_SECONDS - 30,
            'integer'
        );
        file_put_contents($this->logPath, "==> Installing WhisperX and dependencies...\n");
        touch($this->logPath, time() - PythonSetupService::STALE_LOG_SECONDS - 30);

        $this->assertSame(PythonSetupService::STATUS_FAILED, $this->service->getStatus());
        $this->assertStringContainsString('stopped writing progress', (string) $this->service->getLastError());
    }

    public function test_watchdog_keeps_recent_running_job(): void
    {
        AppSetting::set(PythonSetupService::SETTING_STATUS, PythonSetupService::STATUS_RUNNING);
        AppSetting::set(PythonSetupService::SETTING_STARTED_AT, time() - 20, 'integer');
        file_put_contents($this->logPath, "==> Installing WhisperX and dependencies...\nCollecting whisperx\n");
        touch($this->logPath, time());

        $this->assertSame(PythonSetupService::STATUS_RUNNING, $this->service->getStatus());
        $this->assertNull($this->service->getLastError());
    }

    public function test_get_setup_log_returns_tail(): void
    {
        file_put_contents($this->logPath, str_repeat('A', 100)."TAIL_MARKER\n");

        $this->assertStringContainsString('TAIL_MARKER', $this->service->getSetupLog(50));
        unlink($this->logPath);
        $this->assertSame('', $this->service->getSetupLog());
    }

    public function test_fail_setup_includes_log_and_stops_running(): void
    {
        AppSetting::set(PythonSetupService::SETTING_STATUS, PythonSetupService::STATUS_RUNNING);
        file_put_contents($this->logPath, "ERROR: Could not find a version that satisfies the requirement torch\n");

        $this->service->failSetup('pip install failed');

        $this->assertSame(PythonSetupService::STATUS_FAILED, AppSetting::get(PythonSetupService::SETTING_STATUS));
        $error = (string) $this->service->getLastError();
        $this->assertStringContainsString('pip install failed', $error);
        $this->assertStringContainsString('Could not find a version', $error);
    }

    public function test_windows_async_command_merges_stderr_into_the_console_log(): void
    {
        $console = 'C:\\Users\\DM\\Lorefire\\storage\\logs\\python_setup.console.log';
        $cmd = $this->service->windowsAsyncCommand(
            'C:\\Program Files\\php\\php.exe',
            'C:\\Users\\DM\\Lorefire\\artisan',
            $console,
            false
        );

        $this->assertStringContainsString('cmd /c start /b', $cmd);
        $this->assertStringContainsString('2>&1', $cmd);
        $this->assertStringContainsString('python:setup', $cmd);
        $this->assertStringContainsString('python_setup.console.log', $cmd);
        $this->assertStringNotContainsString('RedirectStandardOutput', $cmd);
        $this->assertStringContainsString('"C:\\Program Files\\php\\php.exe"', $cmd);
        $this->assertDoesNotMatchRegularExpression('/>>\s*"[^"]*python_setup\.log"/', $cmd);
    }

    public function test_windows_spawn_redirects_to_console_log_not_the_php_log(): void
    {
        $cmd = $this->service->buildAsyncCommand(false, 'Windows');
        $main = $this->service->setupLogPath();
        $console = $this->service->setupConsoleLogPath();

        $this->assertStringContainsString('python_setup.console.log', $cmd);
        $this->assertStringContainsString('2>&1', $cmd);
        $this->assertStringNotContainsString($main.'"', $cmd);
        $this->assertStringContainsString(basename($console), $cmd);
        $this->assertSame(FILE_APPEND, $this->service->logWriteFlags('Windows'));
        $this->assertSame(FILE_APPEND | LOCK_EX, $this->service->logWriteFlags('Linux'));
    }

    public function test_windows_gpu_flag_is_appended(): void
    {
        $cmd = $this->service->windowsAsyncCommand('php.exe', 'artisan', 'setup.log', true);

        $this->assertStringContainsString('--gpu', $cmd);
    }

    public function test_linux_run_setup_fails_without_python312(): void
    {
        if (! Linux5090::isLinuxX86()) {
            $this->markTestSkipped('linux-5090 Python 3.12 gate is Linux x86_64 only.');
        }

        Linux5090::$python312Probe = fn () => false;
        $this->service->runSetup();

        $this->assertSame(PythonSetupService::STATUS_FAILED, AppSetting::get(PythonSetupService::SETTING_STATUS));
        $this->assertStringContainsString('deadsnakes', (string) $this->service->getLastError());
        $this->assertStringContainsString('python3.12', (string) $this->service->getLastError());
    }

    public function test_linux_async_gpu_flag_is_appended_without_changing_windows_cpu_default(): void
    {
        $linux = $this->service->buildAsyncCommand(true, 'Linux');
        $windowsCpu = $this->service->buildAsyncCommand(false, 'Windows');

        $this->assertStringContainsString('--gpu', $linux);
        $this->assertStringNotContainsString('--gpu', $windowsCpu);
        $this->assertStringContainsString('cmd /c start /b', $windowsCpu);
    }

    public function test_shared_payload_includes_log_and_reaps_stale_status(): void
    {
        AppSetting::set(PythonSetupService::SETTING_STATUS, PythonSetupService::STATUS_RUNNING);
        AppSetting::set(PythonSetupService::SETTING_STARTED_AT, time() - PythonSetupService::SETUP_TIMEOUT_SECONDS - 5, 'integer');
        file_put_contents($this->logPath, "pip hang line\n");

        $payload = $this->service->sharedPayload();

        $this->assertSame(PythonSetupService::STATUS_FAILED, $payload['status']);
        $this->assertStringContainsString('pip hang line', $payload['log']);
        $this->assertNotEmpty($payload['error']);
    }

    public function test_onboarding_page_shares_setup_log(): void
    {
        AppSetting::set(PythonSetupService::SETTING_STATUS, PythonSetupService::STATUS_RUNNING);
        AppSetting::set(PythonSetupService::SETTING_STARTED_AT, time() - 15, 'integer');
        file_put_contents($this->logPath, "==> Installing WhisperX and dependencies...\n");

        $this->get('/onboarding')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Onboarding/Index')
                ->where('python_setup.status', 'running')
                ->where('python_setup.log', fn ($log) => is_string($log) && str_contains($log, 'Installing WhisperX'))
            );
    }

    public function test_get_setup_log_includes_console_and_php_logs(): void
    {
        file_put_contents($this->logPath, "==> Installing WhisperX and dependencies...\n");
        file_put_contents($this->service->setupConsoleLogPath(), "[python:setup] Starting WhisperX environment setup (CPU mode)…\n");

        $combined = $this->service->getSetupLog();
        $this->assertStringContainsString('Installing WhisperX', $combined);
        $this->assertStringContainsString('Starting WhisperX environment setup', $combined);
    }

    public function test_write_setup_log_does_not_throw_when_the_file_is_already_open(): void
    {
        file_put_contents($this->logPath, "seed\n");
        $handle = fopen($this->logPath, 'a');
        $this->assertIsResource($handle);

        $this->service->writeSetupLog("==> Installing WhisperX and dependencies...\n");
        fclose($handle);

        $this->assertStringContainsString('Installing WhisperX', $this->service->getSetupLog());
    }

    public function test_overlapping_async_setup_is_skipped_while_running(): void
    {
        AppSetting::set(PythonSetupService::SETTING_STATUS, PythonSetupService::STATUS_RUNNING);
        AppSetting::set(PythonSetupService::SETTING_STARTED_AT, time() - 10, 'integer');
        file_put_contents($this->logPath, "==> Installing WhisperX and dependencies...\n");

        $this->service->runSetupAsync();

        $this->assertSame(PythonSetupService::STATUS_RUNNING, AppSetting::get(PythonSetupService::SETTING_STATUS));
        $this->assertStringContainsString('already running', $this->service->getSetupLog());
    }

    public function test_forced_retry_starts_even_if_marked_running(): void
    {
        $service = new class extends PythonSetupService
        {
            public function setupScriptPath(): string
            {
                return '/tmp/lorefire-missing-setup-script.sh';
            }
        };

        AppSetting::set(PythonSetupService::SETTING_STATUS, PythonSetupService::STATUS_RUNNING);
        AppSetting::set(PythonSetupService::SETTING_STARTED_AT, time() - 10, 'integer');

        $service->runSetupAsync(force: true);

        $this->assertSame(PythonSetupService::STATUS_FAILED, AppSetting::get(PythonSetupService::SETTING_STATUS));
        $this->assertStringContainsString('Setup script not found', (string) AppSetting::get(PythonSetupService::SETTING_ERROR));
    }

    public function test_watchdog_ignores_lock_error_spam_as_progress(): void
    {
        AppSetting::set(PythonSetupService::SETTING_STATUS, PythonSetupService::STATUS_RUNNING);
        AppSetting::set(
            PythonSetupService::SETTING_STARTED_AT,
            time() - PythonSetupService::STALE_LOG_SECONDS - 30,
            'integer'
        );
        $lockNoise = "file_put_contents(...\\python_setup.log): Failed to open stream: Resource temporarily unavailable\n";
        file_put_contents($this->logPath, str_repeat($lockNoise, 8));
        touch($this->logPath, time());

        $this->assertFalse($this->service->logHasPipOutput());
        $this->assertSame(PythonSetupService::STATUS_FAILED, $this->service->getStatus());
        $this->assertStringContainsString('stopped writing progress', (string) $this->service->getLastError());
    }

    public function test_watchdog_does_not_fail_immediately_on_a_lock_error(): void
    {
        AppSetting::set(PythonSetupService::SETTING_STATUS, PythonSetupService::STATUS_RUNNING);
        AppSetting::set(PythonSetupService::SETTING_STARTED_AT, time() - 20, 'integer');
        file_put_contents(
            $this->logPath,
            "file_put_contents(...\\python_setup.log): Failed to open stream: Resource temporarily unavailable\n"
        );

        $this->assertSame(PythonSetupService::STATUS_RUNNING, $this->service->getStatus());
        $this->assertNull($this->service->getLastError());
    }

    public function test_setup_scripts_are_hang_resistant(): void
    {
        $ps1 = file_get_contents(base_path('resources/python/setup.ps1'));
        $this->assertIsString($ps1);
        $this->assertStringContainsString('PYTHONUNBUFFERED', $ps1);
        $this->assertStringContainsString('--prefer-binary', $ps1);
        $this->assertStringContainsString('--only-binary=:all:', $ps1);
        $this->assertStringContainsString('AllowFailure', $ps1);
        $this->assertStringContainsString('TIMEOUT', $ps1);
        $this->assertStringContainsString('Installing WhisperX and dependencies', $ps1);

        $sh = file_get_contents(base_path('resources/python/setup.sh'));
        $this->assertIsString($sh);
        $this->assertStringContainsString('PYTHONUNBUFFERED', $sh);
        $this->assertStringContainsString('--prefer-binary', $sh);
        $this->assertStringContainsString('model preload skipped', $sh);
    }

    public function test_setup_ps1_is_safe_for_windows_powershell_51(): void
    {
        $path = base_path('resources/python/setup.ps1');
        $ps1 = file_get_contents($path);
        $this->assertIsString($ps1);

        foreach (unpack('C*', $ps1) as $offset => $byte) {
            $this->assertLessThan(
                128,
                $byte,
                'setup.ps1 must be ASCII. Non-ASCII at byte '.$offset.' (0x'.dechex($byte).') breaks PowerShell 5.1 UTF-8-without-BOM parsing.'
            );
        }

        $this->assertDoesNotMatchRegularExpression(
            '/Write-Host\s+"[^"\n]*\(\s*\w+\s*,/',
            $ps1,
            'Write-Host double-quoted (word, word) is a PS 5.1 ParserError if quotes desync.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/-ArgumentList\\s+@\\('-c',\\s*\"/",
            $ps1,
            'Python -c must not sit in a double-quoted PowerShell string.'
        );

        $withoutHereStrings = preg_replace("/@'.*?'@/s", '', $ps1);
        $this->assertStringNotContainsString('getattr(', $withoutHereStrings);
        $this->assertStringNotContainsString('__version__', $withoutHereStrings);
        $this->assertStringContainsString('$modelCode = @\'', $ps1);
        $this->assertStringContainsString('$whisperxVerifyCode = @\'', $ps1);
        $this->assertStringContainsString('$torchVerifyCode = @\'', $ps1);
        $this->assertStringContainsString('Write-Host \'==> Pre-downloading WhisperX base model (optional, 3 min cap)...\'', $ps1);

        $this->assertPowerShellScriptIsAsciiAndParses($path, 'setup.ps1');
    }

    public function test_download_runtime_ps1_is_safe_for_windows_powershell_51(): void
    {
        $path = base_path('resources/python/download_runtime.ps1');
        $ps1 = file_get_contents($path);
        $this->assertIsString($ps1);

        $this->assertPowerShellScriptIsAsciiAndParses($path, 'download_runtime.ps1');

        $this->assertMatchesRegularExpression(
            "/else \\{\\s*'x86_64-pc-windows-msvc'\\s*\\}/s",
            $ps1,
            'Default PBS platform must stay x86_64-pc-windows-msvc for torch 2.5.1 CPU wheels.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/else \\{\\s*'[^']*aarch64[^']*'\\s*\\}/",
            $ps1
        );
        $this->assertDoesNotMatchRegularExpression('/Write-Host\s+"/m', $ps1);
        $this->assertDoesNotMatchRegularExpression('/Write-Error\s+"/m', $ps1);
        $this->assertStringNotContainsString('`python', $ps1);
        $this->assertStringNotContainsString('install from the Microsoft Store', $ps1);
    }

    public function test_setup_pins_pip_below_24_1_and_omegaconf_2_3(): void
    {
        $ps1 = file_get_contents(base_path('resources/python/setup.ps1'));
        $this->assertIsString($ps1);
        $this->assertStringContainsString("'pip==24.0'", $ps1);
        $this->assertStringNotContainsString("'pip', 'setuptools'", $ps1);
        $this->assertStringContainsString('--only-binary=:all:', $ps1);
        $this->assertStringContainsString('Get-TimedCommandExitCode', $ps1);
        $this->assertStringContainsString('WindowsApps', $ps1);

        $sh = file_get_contents(base_path('resources/python/setup.sh'));
        $this->assertIsString($sh);
        $this->assertStringContainsString('pip==24.0', $sh);

        $req = file_get_contents(base_path('resources/python/requirements.txt'));
        $this->assertIsString($req);
        $this->assertMatchesRegularExpression('/^omegaconf>=2\.3\.0,<3/m', $req);
        $this->assertDoesNotMatchRegularExpression('/^omegaconf==2\.1/m', $req);
    }

    public function test_setup_ps1_does_not_treat_null_exitcode_as_failure(): void
    {
        $ps1 = file_get_contents(base_path('resources/python/setup.ps1'));
        $this->assertIsString($ps1);

        $this->assertStringContainsString('function Get-TimedCommandExitCode', $ps1);
        $this->assertStringContainsString('WaitForExit', $ps1);
        $this->assertStringContainsString('Refresh()', $ps1);
        $this->assertStringContainsString('$null -eq $code', $ps1);
        $this->assertStringNotContainsString('$code -eq \'\'', $ps1);
        $this->assertStringContainsString('treating as 0', $ps1);
        $this->assertStringContainsString('Successfully installed', $ps1);
        $this->assertStringContainsString('$exitCode = Get-TimedCommandExitCode', $ps1);
        $this->assertStringContainsString('if ($exitCode -ne 0)', $ps1);
        $this->assertStringNotContainsString('if ($process.ExitCode -ne 0)', $ps1);
    }

    public function test_setup_ps1_rejects_microsoft_store_python_stubs(): void
    {
        $ps1 = file_get_contents(base_path('resources/python/setup.ps1'));
        $this->assertIsString($ps1);

        $this->assertStringContainsString('WindowsApps', $ps1);
        $this->assertStringContainsString('9009', $ps1);
        $this->assertStringContainsString("'-3.12'", $ps1);
        $this->assertStringContainsString('Get-RealPythonVersion', $ps1);
        $this->assertStringContainsString('download_runtime.ps1', $ps1);
        $this->assertStringContainsString('Skipping empty or unsupported version', $ps1);

        $this->assertLessThan(
            strpos($ps1, "'python3'"),
            strpos($ps1, "'-3.12'"),
            'py -3.12 must be tried before the python3 Store alias.'
        );
        $this->assertLessThan(
            strpos($ps1, "'python3'"),
            strpos($ps1, "'python'"),
            'A real python.exe name must be tried before python3.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/Or install Python 3\.9\+ system-wide/',
            $ps1
        );
        $this->assertStringNotContainsString('install from the Microsoft Store', $ps1);
    }

    private function assertPowerShellScriptIsAsciiAndParses(string $path, string $label): void
    {
        $ps1 = file_get_contents($path);
        $this->assertIsString($ps1);

        foreach (unpack('C*', $ps1) as $offset => $byte) {
            $this->assertLessThan(
                128,
                $byte,
                $label.' must be ASCII. Non-ASCII at byte '.$offset.' (0x'.dechex($byte).') breaks PowerShell 5.1 UTF-8-without-BOM parsing.'
            );
        }

        $shells = [];
        foreach (['pwsh', 'powershell'] as $bin) {
            $found = trim((string) shell_exec('command -v '.escapeshellarg($bin).' 2>/dev/null'));
            if ($found !== '') {
                $shells[] = $found;
            }
        }
        if ($shells === []) {
            $this->assertTrue(true, 'No PowerShell on this host; ASCII/shape checks above are the CI gate.');

            return;
        }

        $quoted = str_replace("'", "''", $path);
        $parse = '[void][System.Management.Automation.Language.Parser]::ParseFile(\''.$quoted.'\', [ref]$null, [ref]$errs); if ($errs) { $errs | ForEach-Object { $_.ToString() }; exit 1 }';
        $cmd = $shells[0].' -NoProfile -NonInteractive -Command '.escapeshellarg($parse);
        exec($cmd, $output, $code);
        $this->assertSame(0, $code, 'PowerShell failed to parse '.$label.":\n".implode("\n", $output));
    }

    public function test_python_setup_status_json_does_not_use_inertia(): void
    {
        AppSetting::set(PythonSetupService::SETTING_STATUS, PythonSetupService::STATUS_RUNNING);
        AppSetting::set(PythonSetupService::SETTING_STARTED_AT, time() - 15, 'integer');
        file_put_contents($this->logPath, "==> Installing WhisperX and dependencies...\n");

        $response = $this->getJson('/python-setup-status');
        $response->assertOk()->assertJsonPath('status', 'running');
        $this->assertFalse($response->headers->has('X-Inertia'));
    }
}
