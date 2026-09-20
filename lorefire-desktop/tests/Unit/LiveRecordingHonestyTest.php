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
        $this->assertStringContainsString('data-testid="live-recording-toolbar"', $live);
        $this->assertStringContainsString('data-testid="live-toolbar-stop"', $live);
        $this->assertStringContainsString('data-testid="live-toolbar-start"', $live);
        $this->assertStringContainsString('data-testid="live-recording-elapsed"', $live);
        $this->assertStringContainsString("recordingSaveFailed ? 'Save / Stop' : 'Stop'", $live);
        $this->assertStringContainsString('startRecording', $live);
        $this->assertStringContainsString('stopRecording', $live);
        $this->assertStringContainsString('registerOnFinalized', $live);

        $this->assertStringContainsString('data-testid="app-recording-stop"', $layout);
        $this->assertStringContainsString('stopRecording', $layout);
        $this->assertStringContainsString("recordingSaveFailed ? 'Save / Stop' : 'Stop'", $layout);

        $this->assertStringContainsString('session-recording-error', $show);
        $this->assertStringContainsString('The timer is frozen', $show);
        $this->assertStringContainsString('recoverable-takes', $show);
        $this->assertStringContainsString('Use as session audio', $show);
        $this->assertStringContainsString('/record/recover', $show);
    }

    public function test_live_page_exposes_always_visible_start_and_stop(): void
    {
        $root = dirname(__DIR__, 2);
        $live = file_get_contents($root.'/resources/js/Pages/Sessions/Live.tsx');
        $layout = file_get_contents($root.'/resources/js/Layouts/AppLayout.tsx');
        $ctx = file_get_contents($root.'/resources/js/Contexts/RecordingContext.tsx');

        $this->assertIsString($live);
        $this->assertIsString($layout);
        $this->assertIsString($ctx);

        $mainPos = strpos($live, 'export default function Live');
        $toolbarPos = strpos($live, 'data-testid="live-recording-toolbar"');
        $stopPos = strpos($live, 'data-testid="live-toolbar-stop"');
        $startPos = strpos($live, 'data-testid="live-toolbar-start"');
        $sessionPanelPos = strpos($live, 'function SessionPanel');

        $this->assertNotFalse($mainPos);
        $this->assertNotFalse($toolbarPos);
        $this->assertNotFalse($stopPos);
        $this->assertNotFalse($startPos);
        $this->assertNotFalse($sessionPanelPos);
        $this->assertGreaterThan($mainPos, $toolbarPos, 'Stop/Record must live on the Live page header, not only the Session tab');
        $this->assertGreaterThan($mainPos, $stopPos);
        $this->assertGreaterThan($mainPos, $startPos);
        $this->assertGreaterThan($sessionPanelPos, $toolbarPos);

        $this->assertStringContainsString('onClick={stopRecording}', $live);
        $this->assertStringContainsString('onClick={handleStartRecording}', $live);
        $this->assertStringContainsString('Save / Stop', $live);
        $this->assertStringContainsString('data-testid="live-toolbar-start"', $live);
        $this->assertMatchesRegularExpression('/data-testid="live-toolbar-start"[\s\S]*?Record[\s\S]*?<\/Button>/', $live);

        $this->assertStringContainsString('onClick={stopRecording}', $layout);
        $this->assertStringContainsString('data-testid="app-recording-stop"', $layout);
        $this->assertStringContainsString('data-testid="live-recording-badge"', $layout);
        $this->assertStringContainsString('className="no-drag flex items-center gap-2 min-w-0"', $layout);

        $this->assertStringContainsString('startRecording', $ctx);
        $this->assertStringContainsString('stopRecording', $ctx);
        $this->assertStringContainsString('openCaptureStream', $ctx);
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

        $this->assertStringNotContainsString('_tempfile.mkdtemp', $runner);
        $this->assertStringNotContainsString("prefix=\"lorefire_ffmpeg_\"", $runner);
        $this->assertStringContainsString('ensure_ffmpeg_on_path()', $runner);
        $this->assertStringContainsString('ffmpeg-alias', $helper);
        $this->assertStringContainsString('sweep_stale', $helper);
        $this->assertStringContainsString('enroll_dir()', $enroll);
        $this->assertStringContainsString('cleanup_path', $enroll);
    }

    public function test_identify_speakers_exposes_play_stop_and_session_clip_route(): void
    {
        $root = dirname(__DIR__, 2);
        $show = file_get_contents($root.'/resources/js/Pages/Sessions/Show.tsx');
        $routes = file_get_contents($root.'/routes/web.php');
        $controller = file_get_contents($root.'/app/Http/Controllers/ChunkedAudioController.php');
        $extractor = file_get_contents($root.'/app/Support/SpeakerClipExtractor.php');
        $windows = file_get_contents($root.'/app/Support/SpeakerClipWindows.php');
        $resolver = file_get_contents($root.'/app/Support/VoiceprintResolver.php');

        $this->assertIsString($show);
        $this->assertIsString($routes);
        $this->assertIsString($controller);
        $this->assertIsString($extractor);
        $this->assertIsString($windows);
        $this->assertIsString($resolver);

        $this->assertStringContainsString('function SpeakerIdentificationPanel', $show);
        $this->assertStringContainsString('function SpeakerClipPlayer', $show);
        $this->assertStringContainsString('data-testid="speaker-clip-player"', $show);
        $this->assertStringContainsString('data-testid="speaker-clip-play"', $show);
        $this->assertStringContainsString('data-testid="speaker-clip-scrub"', $show);
        $this->assertStringContainsString("playing ? 'Stop' : 'Play'", $show);
        $this->assertStringContainsString('/speakers/${encodeURIComponent(label)}/clip', $show);
        $this->assertStringContainsString('hasAudio={!!liveAudioPath}', $show);
        $this->assertStringContainsString('clipWindowsForLabel', $show);
        $this->assertStringContainsString('CLIP_MAX_SECONDS = 25', $show);
        $this->assertStringContainsString('type="range"', $show);
        $this->assertStringContainsString('new Audio()', $show);

        $this->assertStringContainsString("speakers/{label}/clip", $routes);
        $this->assertStringContainsString('speakerClip', $routes);
        $this->assertStringContainsString("where('label', 'SPEAKER_[0-9]+')", $routes);

        $this->assertStringContainsString('function speakerClip', $controller);
        $this->assertStringContainsString('SpeakerClipExtractor', $controller);
        $this->assertStringContainsString("Content-Type' => 'audio/wav'", $controller);
        $this->assertStringContainsString("inline; filename=", $controller);

        $this->assertStringContainsString('atrim=start=', $extractor);
        $this->assertStringContainsString('concat=n=', $extractor);
        $this->assertStringContainsString('loadTranscriptSegments', $extractor);
        $this->assertStringContainsString('MAX_SECONDS = 25.0', $windows);
        $this->assertStringContainsString('MAX_SEGMENTS = 3', $windows);

        $this->assertStringContainsString('Does not rewrite SPEAKER_N inside transcript.json', $resolver);
        $this->assertStringContainsString('applyToSession', $resolver);
    }
}
