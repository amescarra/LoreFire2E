<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LiveRecordingHonestyTest extends TestCase
{
    public function test_live_timer_freezes_and_surfaces_chunk_save_failure(): void
    {
        $root = dirname(__DIR__, 2);
        $ctx = file_get_contents($root.'/resources/js/Contexts/RecordingContext.tsx');
        $layout = file_get_contents($root.'/resources/js/Layouts/AppLayout.tsx');
        $live = file_get_contents($root.'/resources/js/Pages/Sessions/Live.tsx');
        $show = file_get_contents($root.'/resources/js/Pages/Sessions/Show.tsx');

        $this->assertIsString($ctx);
        $this->assertIsString($layout);
        $this->assertIsString($live);
        $this->assertIsString($show);

        $this->assertStringContainsString('recordingError', $ctx);
        $this->assertStringContainsString('recordingSaveFailed', $ctx);
        $this->assertStringContainsString('haltForSaveFailure', $ctx);
        $this->assertStringContainsString('status === 507', $ctx);
        $this->assertStringContainsString('chunkIndexRef.current = index + 1', $ctx);
        $this->assertStringNotContainsString('will retry on finalize', $ctx);

        $this->assertStringContainsString('Save failed ·', $layout);
        $this->assertStringContainsString('data-testid="live-recording-error"', $layout);
        $this->assertStringContainsString('recordingSaveFailed', $layout);

        $this->assertStringContainsString('Save failed ·', $live);
        $this->assertStringContainsString('live-session-recording-error', $live);
        $this->assertStringContainsString('recordingSaveFailed', $live);

        $this->assertStringContainsString('session-recording-error', $show);
        $this->assertStringContainsString('The timer is frozen', $show);
        $this->assertStringContainsString('recoverable-takes', $show);
        $this->assertStringContainsString('Use as session audio', $show);
        $this->assertStringContainsString('/record/recover', $show);
    }

    public function test_whisperx_does_not_mkdtemp_lorefire_ffmpeg_per_slice(): void
    {
        $root = dirname(__DIR__, 2);
        $runner = file_get_contents($root.'/resources/python/run_whisperx.py');
        $helper = file_get_contents($root.'/resources/python/lorefire_tmp.py');
        $enroll = file_get_contents($root.'/resources/python/extract_speaker_embeddings.py');

        $this->assertIsString($runner);
        $this->assertIsString($helper);
        $this->assertIsString($enroll);

        $this->assertStringNotContainsString('mkdtemp(prefix="lorefire_ffmpeg_")', $runner);
        $this->assertStringContainsString('ensure_ffmpeg_on_path()', $runner);
        $this->assertStringContainsString('ffmpeg-alias', $helper);
        $this->assertStringContainsString('sweep_stale', $helper);
        $this->assertStringContainsString('enroll_dir()', $enroll);
        $this->assertStringContainsString('cleanup_path', $enroll);
    }
}
