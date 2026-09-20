<?php

namespace Tests\Unit;

use App\Support\VoiceprintEmbeddingExtractor;
use App\Support\WhisperxRunner;
use PHPUnit\Framework\TestCase;

class VoiceprintArmPathTest extends TestCase
{
    public function test_windows_arm_does_not_run_embedding_extraction(): void
    {
        $extractor = new VoiceprintEmbeddingExtractor;

        $this->assertFalse($extractor->isSupported('Windows', 'ARM64', ''));
        $this->assertFalse($extractor->isSupported('Windows', 'AMD64', 'ARM64'));
        $this->assertTrue($extractor->isSupported('Linux', 'x86_64', ''));
        $this->assertTrue($extractor->isSupported('Linux', 'aarch64', ''));
    }

    public function test_live_diarization_stays_off_on_windows_arm(): void
    {
        $runner = new WhisperxRunner;

        $this->assertFalse($runner->shouldDiarizeLive('Windows', 'ARM64', ''));
        $this->assertFalse($runner->shouldDiarizeLive('Windows', 'AMD64', 'ARM64'));
    }

    public function test_arm_scripts_and_live_job_keep_existing_paths(): void
    {
        $root = dirname(__DIR__, 2);
        $live = file_get_contents($root.'/app/Jobs/TranscribeLiveAudio.php');
        $transcribe = file_get_contents($root.'/app/Jobs/TranscribeAudio.php');
        $ps1 = file_get_contents($root.'/resources/python/setup.ps1');
        $serve = file_get_contents($root.'/scripts/native-serve.ps1');
        $armDoc = file_get_contents($root.'/WINDOWS-ARM.md');
        $python = file_get_contents($root.'/resources/python/extract_speaker_embeddings.py');

        $this->assertIsString($live);
        $this->assertStringContainsString('shouldDiarizeLive()', $live);
        $this->assertStringNotContainsString("transcribe(\$audioForWhisper, \$outAbs, true)", $live);

        $this->assertIsString($transcribe);
        $this->assertStringContainsString('VoiceprintResolver', $transcribe);
        $this->assertStringContainsString('WhisperxLanguages::command', $transcribe);

        $this->assertIsString($ps1);
        $this->assertStringContainsString('CUDA 11.8, optional GPU path', $ps1);
        $this->assertStringContainsString('torch==2.5.1', $ps1);
        $this->assertStringNotContainsString('extract_speaker_embeddings', $ps1);
        $this->assertStringNotContainsString('cu128', $ps1);

        $this->assertIsString($serve);
        $this->assertStringContainsString('Windows ARM64', $serve);
        $this->assertStringNotContainsString('voiceprint', $serve);

        $this->assertIsString($armDoc);
        $this->assertStringContainsString('ARM-native', $armDoc);
        $this->assertStringNotContainsString('extract_speaker_embeddings', $armDoc);

        $this->assertIsString($python);
        $this->assertStringContainsString('Windows ARM never depends on this script succeeding', $python);
        $this->assertStringContainsString('write_empty', $python);
    }

    public function test_session_and_campaign_ui_expose_save_and_elayas_rule(): void
    {
        $root = dirname(__DIR__, 2);
        $session = file_get_contents($root.'/resources/js/Pages/Sessions/Show.tsx');
        $voices = file_get_contents($root.'/resources/js/Pages/Campaigns/Voices.tsx');
        $campaign = file_get_contents($root.'/resources/js/Pages/Campaigns/Show.tsx');

        $this->assertIsString($session);
        $this->assertStringContainsString('Save voices for this campaign', $session);
        $this->assertStringContainsString('save_to_campaign', $session);
        $this->assertStringContainsString('update_voiceprint', $session);
        $this->assertStringContainsString('Update campaign voiceprint', $session);

        $this->assertIsString($voices);
        $this->assertStringContainsString('Elayas', $voices);
        $this->assertStringContainsString('Dungeon Master', $voices);
        $this->assertStringContainsString('Do not collapse', $voices);
        $this->assertStringContainsString('Record new voice', $voices);
        $this->assertStringContainsString('Stop', $voices);
        $this->assertStringContainsString('Voiceprint ready', $voices);
        $this->assertStringContainsString('useEnrollmentCapture', $voices);
        $this->assertStringContainsString('openCaptureStream', file_get_contents($root.'/resources/js/hooks/useEnrollmentCapture.ts'));
        $this->assertStringContainsString('Windows ARM stores enrollment audio but skips embedding extract', $voices);

        $this->assertIsString($campaign);
        $this->assertStringContainsString('Enrolled Voices', $campaign);
        $this->assertStringContainsString('Open Enrolled Voices', $campaign);
        $this->assertStringContainsString('href={`/campaigns/${campaign.id}/voices`}', $campaign);
        $this->assertGreaterThanOrEqual(3, substr_count($campaign, '/voices'));
    }
}
