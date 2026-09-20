<?php

namespace Tests\Unit;

use App\Support\SpeakerClipWindows;
use PHPUnit\Framework\TestCase;

class SpeakerClipWindowsTest extends TestCase
{
    public function test_picks_first_three_segments_for_the_requested_label(): void
    {
        $windows = SpeakerClipWindows::forLabel([
            ['speaker' => 'SPEAKER_00', 'start' => 1.0, 'end' => 3.0, 'text' => 'Ward holds.'],
            ['speaker' => 'SPEAKER_01', 'start' => 3.2, 'end' => 8.0, 'text' => 'I roll.'],
            ['speaker' => 'SPEAKER_00', 'start' => 10.0, 'end' => 12.5, 'text' => 'Stay back.'],
            ['speaker_label' => 'SPEAKER_00', 'start' => 20.0, 'end' => 22.0, 'text' => 'Now.'],
            ['speaker' => 'SPEAKER_00', 'start' => 30.0, 'end' => 34.0, 'text' => 'Later.'],
        ], 'SPEAKER_00');

        $this->assertCount(3, $windows);
        $this->assertSame(1.0, $windows[0]['start']);
        $this->assertSame(3.0, $windows[0]['end']);
        $this->assertSame(10.0, $windows[1]['start']);
        $this->assertSame(20.0, $windows[2]['start']);
        $this->assertEqualsWithDelta(6.5, SpeakerClipWindows::duration($windows), 0.01);
    }

    public function test_caps_total_duration_and_skips_tiny_fragments(): void
    {
        $windows = SpeakerClipWindows::forLabel([
            ['speaker' => 'SPEAKER_02', 'start' => 0.0, 'end' => 0.2, 'text' => 'uh'],
            ['speaker' => 'SPEAKER_02', 'start' => 1.0, 'end' => 20.0, 'text' => 'long'],
            ['speaker' => 'SPEAKER_02', 'start' => 40.0, 'end' => 80.0, 'text' => 'also long'],
        ], 'SPEAKER_02');

        $this->assertCount(2, $windows);
        $this->assertEqualsWithDelta(12.0, $windows[0]['end'] - $windows[0]['start'], 0.01);
        $this->assertEqualsWithDelta(12.0, $windows[1]['end'] - $windows[1]['start'], 0.01);
        $this->assertLessThanOrEqual(SpeakerClipWindows::MAX_SECONDS, SpeakerClipWindows::duration($windows));
    }

    public function test_reads_raw_transcript_json_speaker_field(): void
    {
        $json = json_encode([
            'language' => 'en',
            'segments' => [
                ['start' => 4.2, 'end' => 6.8, 'text' => 'The ward holds.', 'speaker' => 'SPEAKER_00'],
            ],
        ]);

        $windows = SpeakerClipWindows::fromTranscriptJson((string) $json, 'SPEAKER_00');
        $this->assertCount(1, $windows);
        $this->assertSame(4.2, $windows[0]['start']);
        $this->assertSame(6.8, $windows[0]['end']);
        $this->assertSame([], SpeakerClipWindows::fromTranscriptJson((string) $json, 'SPEAKER_09'));
    }

    public function test_label_validation(): void
    {
        $this->assertTrue(SpeakerClipWindows::isValidLabel('SPEAKER_00'));
        $this->assertTrue(SpeakerClipWindows::isValidLabel('SPEAKER_12'));
        $this->assertFalse(SpeakerClipWindows::isValidLabel('Elayas'));
        $this->assertFalse(SpeakerClipWindows::isValidLabel('../audio'));
        $this->assertFalse(SpeakerClipWindows::isValidLabel('SPEAKER_'));
    }
}
