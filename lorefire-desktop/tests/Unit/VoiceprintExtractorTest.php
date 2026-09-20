<?php

namespace Tests\Unit;

use App\Support\VoiceprintEmbeddingExtractor;
use PHPUnit\Framework\TestCase;

class VoiceprintExtractorTest extends TestCase
{
    protected function tearDown(): void
    {
        VoiceprintEmbeddingExtractor::resetOverride();
        parent::tearDown();
    }

    public function test_extract_detailed_passes_through_error_when_speakers_empty(): void
    {
        VoiceprintEmbeddingExtractor::$extractOverride = fn () => [
            'speakers' => [],
            'error' => 'No embedding model loaded (gated repo)',
        ];

        $detailed = (new VoiceprintEmbeddingExtractor)->extractDetailed('/tmp/enrollment.webm');

        $this->assertSame([], $detailed['speakers']);
        $this->assertSame('No embedding model loaded (gated repo)', $detailed['error']);
        $this->assertFalse($detailed['skipped']);
    }

    public function test_extract_detailed_returns_speakers_without_error(): void
    {
        VoiceprintEmbeddingExtractor::$extractOverride = fn () => [
            'model' => 'test-embedding',
            'speakers' => ['enrollment' => [0.2, 0.8, 0.0]],
        ];

        $detailed = (new VoiceprintEmbeddingExtractor)->extractDetailed('/tmp/enrollment.webm');

        $this->assertSame([0.2, 0.8, 0.0], $detailed['speakers']['enrollment']);
        $this->assertSame('test-embedding', $detailed['model']);
        $this->assertNull($detailed['error']);
        $this->assertFalse($detailed['skipped']);
    }

    public function test_windows_arm_skip_has_clear_error_and_empty_speakers(): void
    {
        VoiceprintEmbeddingExtractor::$supportedOverride = false;

        $detailed = (new VoiceprintEmbeddingExtractor)->extractDetailed('/tmp/enrollment.webm');

        $this->assertTrue($detailed['skipped']);
        $this->assertSame([], $detailed['speakers']);
        $this->assertStringContainsString('Windows ARM', (string) $detailed['error']);
    }
}
