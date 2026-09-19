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
        $this->assertFileExists(dirname(__DIR__, 2).'/resources/python/requirements-linux-5090.txt');
        $this->assertFileExists(dirname(__DIR__, 2).'/LINUX-5090.md');
        $this->assertFileExists(dirname(__DIR__, 2).'/scripts/linux-5090-setup.sh');
        $this->assertFileExists(dirname(__DIR__, 2).'/scripts/linux-5090-detect.sh');
    }
}
