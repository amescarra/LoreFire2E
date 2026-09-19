<?php

namespace Tests\Unit;

use App\Models\AppSetting;
use App\Support\Linux5090;
use App\Support\WhisperxLanguages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WhisperxLanguagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('LOREFIRE_LINUX5090_CUDA=0');
        Linux5090::resetProbes();
    }

    protected function tearDown(): void
    {
        Linux5090::resetProbes();
        putenv('LOREFIRE_LINUX5090_CUDA');
        putenv('LOREFIRE_LINUX5090_VRAM_MB');
        parent::tearDown();
    }

    public function test_default_allowlist_is_english_and_spanish(): void
    {
        $this->assertSame(['en', 'es'], WhisperxLanguages::allowlist());
        $this->assertSame('en,es', WhisperxLanguages::csv());
    }

    public function test_legacy_forced_english_becomes_en_es(): void
    {
        AppSetting::set('whisperx_language', 'en');
        $this->assertSame(['en', 'es'], WhisperxLanguages::allowlist());
    }

    public function test_legacy_auto_becomes_en_es(): void
    {
        AppSetting::set('whisperx_language', 'auto');
        $this->assertSame(['en', 'es'], WhisperxLanguages::allowlist());
    }

    public function test_stored_allowlist_is_honored(): void
    {
        AppSetting::set('whisperx_languages', 'es');
        $this->assertSame(['es'], WhisperxLanguages::allowlist());
    }

    public function test_unknown_codes_are_dropped(): void
    {
        $this->assertSame(['en', 'es'], WhisperxLanguages::parse('en,fr,es,zh'));
        $this->assertSame(['en', 'es'], WhisperxLanguages::parse(''));
    }

    public function test_english_only_model_names_are_coerced(): void
    {
        $this->assertTrue(WhisperxLanguages::isEnglishOnlyModel('large-v3.en'));
        $this->assertSame('large-v3', WhisperxLanguages::coerceModel('large-v3.en'));
        $this->assertSame('medium', WhisperxLanguages::coerceModel('medium.en'));
        $this->assertSame('tiny', WhisperxLanguages::coerceModel('tiny.en'));
        $this->assertSame('base', WhisperxLanguages::coerceModel('base.en'));
        $this->assertSame('small', WhisperxLanguages::coerceModel('small.en'));
        $this->assertSame('base', WhisperxLanguages::coerceModel('not-a-model'));
    }

    public function test_cli_passes_languages_not_hardcoded_en(): void
    {
        AppSetting::set('whisperx_languages', 'en,es');
        AppSetting::set('whisperx_model', 'base');

        $cmd = WhisperxLanguages::command('python', 'run_whisperx.py', 'a.webm', 'out.json');

        $this->assertContains('--languages', $cmd);
        $this->assertContains('en,es', $cmd);
        $this->assertNotContains('--language', $cmd);
        $this->assertNotContains('cuda', $cmd);
    }

    public function test_cli_adds_cuda_flags_only_on_linux_5090_stack(): void
    {
        if (! Linux5090::isLinuxX86()) {
            $this->markTestSkipped('linux-5090 CLI flags apply on Linux x86_64.');
        }

        putenv('LOREFIRE_LINUX5090_CUDA=1');
        putenv('LOREFIRE_LINUX5090_VRAM_MB=32607');

        $cmd = WhisperxLanguages::command('python', 'run_whisperx.py', 'a.webm', 'out.json');

        $this->assertContains('--device', $cmd);
        $this->assertContains('cuda', $cmd);
        $this->assertContains('--compute-type', $cmd);
        $this->assertContains('float16', $cmd);
        $this->assertContains('--batch-size', $cmd);
        $this->assertContains('32', $cmd);
    }

    public function test_python_clamp_rejects_french_and_coerces_en_models(): void
    {
        $script = base_path('resources/python/whisperx_languages.py');
        $this->assertFileExists($script);

        $process = new Process(['python3', $script]);
        $process->setTimeout(15);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $this->assertSame('ok', trim($process->getOutput()));
    }
}
