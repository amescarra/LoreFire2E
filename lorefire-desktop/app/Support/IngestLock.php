<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\LegalDocument;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Ingest allowlist. Official PDF / OCR / RAG / handbook prose are forbidden.
 * Allowed: user cards, table law, WhisperX transcripts, FG&G after opt-in + OGL row.
 */
class IngestLock
{
    public const KIND_USER_CARDS = 'user_cards';

    public const KIND_TABLE_LAW = 'table_law';

    public const KIND_WHISPERX_TRANSCRIPTS = 'whisperx_transcripts';

    public const KIND_FGG = 'fgg';

    public const KIND_OFFICIAL_PDF = 'official_pdf';

    public const KIND_RULEBOOK_OCR = 'rulebook_ocr';

    public const KIND_RAG = 'rag';

    public const KIND_HANDBOOK_PROSE = 'handbook_prose';

    public const FGG_SETTING_KEY = 'fgg_opt_in';

    public const OGL_CODE = 'OGL';

    public const POLICY_CODE = 'INGEST_POLICY';

    public const ORACLE_LABEL_FGG = 'FG&G';

    /**
     * @return list<string>
     */
    public static function allowedKinds(): array
    {
        $kinds = [
            self::KIND_USER_CARDS,
            self::KIND_TABLE_LAW,
            self::KIND_WHISPERX_TRANSCRIPTS,
        ];
        if (self::fggAllowed()) {
            $kinds[] = self::KIND_FGG;
        }

        return $kinds;
    }

    /**
     * @return list<string>
     */
    public static function forbiddenKinds(): array
    {
        return [
            self::KIND_OFFICIAL_PDF,
            self::KIND_RULEBOOK_OCR,
            self::KIND_RAG,
            self::KIND_HANDBOOK_PROSE,
        ];
    }

    public static function allows(string $kind): bool
    {
        if (in_array($kind, self::forbiddenKinds(), true)) {
            return false;
        }

        if ($kind === self::KIND_FGG) {
            return self::fggAllowed();
        }

        return in_array($kind, [
            self::KIND_USER_CARDS,
            self::KIND_TABLE_LAW,
            self::KIND_WHISPERX_TRANSCRIPTS,
        ], true);
    }

    public static function assertAllowed(string $kind): void
    {
        if (! self::allows($kind)) {
            throw new RuntimeException(self::denyMessage($kind));
        }
    }

    public static function denyMessage(string $kind): string
    {
        if ($kind === self::KIND_FGG) {
            return 'FG&G is off until the table opts in and the OGL row is on file. Official handbook prose is never ingested.';
        }

        return 'Official PDF, OCR, RAG, and handbook prose ingest is forbidden. Allowed sources are user cards, table law, WhisperX transcripts, and FG&G after opt-in.';
    }

    /**
     * Hard stop for any official rulebook importer.
     */
    public static function rejectOfficialImporter(?string $kind = null): never
    {
        throw new RuntimeException(self::denyMessage($kind ?? self::KIND_OFFICIAL_PDF));
    }

    /**
     * Keys that must never appear on card/citation writes.
     *
     * @return list<string>
     */
    public static function forbiddenWriteKeys(): array
    {
        return [
            'excerpt',
            'official_text',
            'rulebook_text',
            'pdf',
            'ocr',
            'rag',
            'handbook_prose',
            'paste',
            'quoted_text',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function assertSafeWrite(array $payload): void
    {
        foreach (self::forbiddenWriteKeys() as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
                throw new InvalidArgumentException('This write is not allowed. Do not store official excerpts or importer payloads.');
            }
        }
    }

    public static function fggAllowed(): bool
    {
        return self::fggOptedIn() && self::oglRowPresent();
    }

    public static function fggOptedIn(): bool
    {
        if (class_exists(AppSetting::class)) {
            try {
                $flag = AppSetting::get(self::FGG_SETTING_KEY, false);
                if (filter_var($flag, FILTER_VALIDATE_BOOLEAN)) {
                    return true;
                }
            } catch (Throwable) {
            }
        }

        try {
            if (class_exists(LegalDocument::class)) {
                $ogl = LegalDocument::query()->where('code', self::OGL_CODE)->first();
                if ($ogl && (bool) $ogl->opted_in) {
                    return true;
                }
            }
        } catch (Throwable) {
        }

        return false;
    }

    public static function oglRowPresent(): bool
    {
        try {
            if (! class_exists(LegalDocument::class)) {
                return false;
            }
            $ogl = LegalDocument::query()->where('code', self::OGL_CODE)->first();

            return $ogl !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public static function setFggOptIn(bool $optIn): void
    {
        if (class_exists(AppSetting::class)) {
            AppSetting::set(self::FGG_SETTING_KEY, $optIn ? '1' : '0', 'boolean');
        }
        try {
            if (class_exists(LegalDocument::class)) {
                LegalDocument::query()->updateOrCreate(
                    ['code' => self::OGL_CODE],
                    [
                        'title' => 'OGL / FG&G gate',
                        'kind' => 'ogl',
                        'opted_in' => $optIn,
                        'notes' => 'Fan-generated and OGL material stays off until the table opts in. This row is the OGL marker. It does not store license prose.',
                    ]
                );
            }
        } catch (Throwable) {
        }
    }

    /**
     * @return list<array{code: string, title: string, kind: string, opted_in: bool, notes: string}>
     */
    public static function legalDocumentSeed(): array
    {
        return [
            [
                'code' => self::POLICY_CODE,
                'title' => 'Ingest allowlist',
                'kind' => 'policy',
                'opted_in' => true,
                'notes' => 'Allowed: user cards, table law, WhisperX transcripts. FG&G only after explicit opt-in plus the OGL row. Official PDF, OCR, RAG, and handbook prose are forbidden.',
            ],
            [
                'code' => self::OGL_CODE,
                'title' => 'OGL / FG&G gate',
                'kind' => 'ogl',
                'opted_in' => false,
                'notes' => 'Fan-generated and OGL material stays off until the table opts in. This row is the OGL marker. It does not store license prose.',
            ],
        ];
    }

    public static function seedLegalDocuments(): void
    {
        if (! class_exists(LegalDocument::class)) {
            return;
        }
        foreach (self::legalDocumentSeed() as $row) {
            LegalDocument::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'title' => $row['title'],
                    'kind' => $row['kind'],
                    'opted_in' => $row['opted_in'],
                    'notes' => $row['notes'],
                ]
            );
        }
    }
}
