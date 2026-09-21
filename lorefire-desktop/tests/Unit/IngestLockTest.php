<?php

namespace Tests\Unit;

use App\Support\IngestLock;
use PHPUnit\Framework\TestCase;

class IngestLockTest extends TestCase
{
    public function test_official_importers_are_forbidden_and_user_cards_are_allowed(): void
    {
        $this->assertTrue(IngestLock::allows(IngestLock::KIND_USER_CARDS));
        $this->assertTrue(IngestLock::allows(IngestLock::KIND_TABLE_LAW));
        $this->assertTrue(IngestLock::allows(IngestLock::KIND_WHISPERX_TRANSCRIPTS));
        $this->assertFalse(IngestLock::allows(IngestLock::KIND_OFFICIAL_PDF));
        $this->assertFalse(IngestLock::allows(IngestLock::KIND_RULEBOOK_OCR));
        $this->assertFalse(IngestLock::allows(IngestLock::KIND_RAG));
        $this->assertFalse(IngestLock::allows(IngestLock::KIND_HANDBOOK_PROSE));
        $this->assertFalse(IngestLock::allows(IngestLock::KIND_FGG));
        $this->assertFalse(class_exists(\App\Support\OfficialPdfImporter::class));
        $this->assertFalse(class_exists(\App\Http\Controllers\RulebookOcrController::class));

        $this->expectException(\InvalidArgumentException::class);
        IngestLock::assertSafeWrite(['excerpt' => 'copied handbook paragraph']);
    }

    public function test_reject_official_importer_always_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        IngestLock::rejectOfficialImporter();
    }
}
