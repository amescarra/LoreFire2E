<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Support\SpeakerClipExtractor;
use App\Support\SpeakerClipWindows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SpeakerClipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::disk('local')->deleteDirectory('sessions');
    }

    protected function tearDown(): void
    {
        SpeakerClipExtractor::resetOverride();
        Storage::disk('local')->deleteDirectory('sessions');
        parent::tearDown();
    }

    /**
     * @return array{0: Campaign, 1: \App\Models\GameSession}
     */
    protected function sessionWithAudioAndTranscript(): array
    {
        $campaign = Campaign::factory()->create(['name' => 'Suor Noir']);
        $session = $campaign->gameSessions()->create([
            'title' => 'The Ward Holds',
            'session_number' => 3,
        ]);

        Storage::disk('local')->put("sessions/{$session->id}/audio.wav", $this->silentWav());
        Storage::disk('local')->put("sessions/{$session->id}/transcript/transcript.json", json_encode([
            'language' => 'en',
            'segments' => [
                ['start' => 0.5, 'end' => 2.0, 'text' => 'The ward holds.', 'speaker' => 'SPEAKER_00'],
                ['start' => 2.2, 'end' => 4.0, 'text' => 'I roll to disbelieve.', 'speaker' => 'SPEAKER_01'],
                ['start' => 8.0, 'end' => 10.0, 'text' => 'Stay behind me.', 'speaker' => 'SPEAKER_00'],
            ],
        ]));
        $session->update([
            'audio_path' => "sessions/{$session->id}/audio.wav",
            'transcript_path' => "sessions/{$session->id}/transcript/transcript.json",
            'transcription_status' => 'done',
        ]);

        return [$campaign, $session];
    }

    public function test_clip_route_streams_inline_wav_for_unresolved_label(): void
    {
        [, $session] = $this->sessionWithAudioAndTranscript();
        $clipPath = Storage::disk('local')->path("sessions/{$session->id}/clips/SPEAKER_00-test.wav");
        if (! is_dir(dirname($clipPath))) {
            mkdir(dirname($clipPath), 0755, true);
        }
        file_put_contents($clipPath, $this->silentWav());

        SpeakerClipExtractor::$extractOverride = fn () => [
            'path' => $clipPath,
            'windows' => [['start' => 0.5, 'end' => 2.0]],
            'error' => null,
            'status' => 200,
        ];

        $response = $this->get("/sessions/{$session->id}/speakers/SPEAKER_00/clip");
        $response->assertOk();
        $response->assertHeader('content-type', 'audio/wav');
        $this->assertStringContainsString('inline', (string) $response->headers->get('content-disposition'));
        $file = $response->baseResponse->getFile();
        $this->assertSame(realpath($clipPath), $file->getRealPath());
        $this->assertStringStartsWith('RIFF', (string) file_get_contents($file->getPathname()));
    }

    public function test_clip_route_rejects_invalid_label_and_unknown_speaker(): void
    {
        [, $session] = $this->sessionWithAudioAndTranscript();

        $this->get("/sessions/{$session->id}/speakers/../clip")
            ->assertNotFound();

        $this->get("/sessions/{$session->id}/speakers/Elayas/clip")
            ->assertNotFound();

        $this->get("/sessions/{$session->id}/speakers/SPEAKER_09/clip")
            ->assertNotFound()
            ->assertJsonFragment(['error' => 'No timed speech found for this speaker label.']);
    }

    public function test_clip_route_requires_session_audio(): void
    {
        $campaign = Campaign::factory()->create();
        $session = $campaign->gameSessions()->create(['title' => 'No Audio']);

        $this->get("/sessions/{$session->id}/speakers/SPEAKER_00/clip")
            ->assertNotFound()
            ->assertJsonFragment(['error' => 'No audio file found for this session.']);
    }

    public function test_real_ffmpeg_cuts_a_short_wav_montage_for_the_label(): void
    {
        $ffmpeg = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));
        if ($ffmpeg === '' || ! is_executable($ffmpeg)) {
            $this->markTestSkipped('ffmpeg is not available');
        }

        [, $session] = $this->sessionWithAudioAndTranscript();
        $audioAbs = Storage::disk('local')->path($session->audio_path);
        file_put_contents($audioAbs, $this->sineWav(12.0));

        SpeakerClipExtractor::$ffmpegOverride = $ffmpeg;
        $result = app(SpeakerClipExtractor::class)->extract($session->fresh(), 'SPEAKER_00');

        $this->assertNull($result['error'], $result['error'] ?? '');
        $this->assertSame(200, $result['status']);
        $this->assertIsString($result['path']);
        $this->assertFileExists($result['path']);
        $this->assertGreaterThan(64, filesize($result['path']));
        $this->assertCount(2, $result['windows']);
        $this->assertLessThanOrEqual(SpeakerClipWindows::MAX_SECONDS, SpeakerClipWindows::duration($result['windows']));

        $probe = trim((string) shell_exec(
            'ffprobe -v error -show_entries format=duration -of csv=p=0 '.escapeshellarg($result['path']).' 2>/dev/null'
        ));
        if (is_numeric($probe)) {
            $this->assertGreaterThan(2.0, (float) $probe);
            $this->assertLessThan(5.0, (float) $probe);
        }
    }

    protected function silentWav(): string
    {
        return $this->pcmWav(array_fill(0, 800, 0), 8000);
    }

    protected function sineWav(float $seconds, int $rate = 8000): string
    {
        $n = (int) ($seconds * $rate);
        $samples = [];
        for ($i = 0; $i < $n; $i++) {
            $samples[] = (int) round(sin(2 * M_PI * 440 * $i / $rate) * 12000);
        }

        return $this->pcmWav($samples, $rate);
    }

    /**
     * @param  list<int>  $samples
     */
    protected function pcmWav(array $samples, int $rate): string
    {
        $data = '';
        foreach ($samples as $sample) {
            $data .= pack('v', $sample < 0 ? $sample + 65536 : $sample);
        }
        $dataSize = strlen($data);

        return 'RIFF'.pack('V', 36 + $dataSize).'WAVEfmt '.pack('V', 16)
            .pack('v', 1).pack('v', 1).pack('V', $rate).pack('V', $rate * 2)
            .pack('v', 2).pack('v', 16).'data'.pack('V', $dataSize).$data;
    }
}
