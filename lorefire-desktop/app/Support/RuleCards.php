<?php

namespace App\Support;

use App\Models\Citation;
use App\Models\KitCard;
use App\Models\MonsterCard;
use App\Models\RacialOptionCard;
use App\Models\SpellCard;
use Throwable;

/**
 * User-entered 2E cards. Short original summaries only.
 */
class RuleCards
{
    public const KIND_SPELL = 'spell';

    public const KIND_MONSTER = 'monster';

    public const KIND_KIT = 'kit';

    public const KIND_RACIAL = 'racial_option';

    public const ORACLE_LABEL = 'CARD';

    /**
     * @return array<string, class-string>
     */
    public static function models(): array
    {
        return [
            self::KIND_SPELL => SpellCard::class,
            self::KIND_MONSTER => MonsterCard::class,
            self::KIND_KIT => KitCard::class,
            self::KIND_RACIAL => RacialOptionCard::class,
        ];
    }

    /**
     * @return list<array{kind: string, name: string, source_code: ?string, effect_summary: string, citation_work: ?string, citation_year: ?int, citation_pages: ?string, citation_topic: ?string, user_verified: bool, id: int}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::models() as $kind => $class) {
            try {
                foreach ($class::query()->orderBy('name')->get() as $card) {
                    $out[] = self::serialize($kind, $card);
                }
            } catch (Throwable) {
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function search(string $question): array
    {
        $hits = [];
        foreach (self::all() as $card) {
            $name = (string) $card['name'];
            if ($name === '') {
                continue;
            }
            if (preg_match('/\b'.preg_quote($name, '/').'\b/i', $question)) {
                $hits[] = $card;
            }
        }

        return $hits;
    }

    /**
     * @param  object{id: mixed, name: mixed, source_code: mixed, effect_summary: mixed, citation_work: mixed, citation_year: mixed, citation_pages: mixed, citation_topic: mixed, user_verified: mixed}  $card
     * @return array{kind: string, name: string, source_code: ?string, effect_summary: string, citation_work: ?string, citation_year: ?int, citation_pages: ?string, citation_topic: ?string, user_verified: bool, id: int}
     */
    public static function serialize(string $kind, object $card): array
    {
        return [
            'kind' => $kind,
            'id' => (int) $card->id,
            'name' => (string) $card->name,
            'source_code' => $card->source_code !== null ? (string) $card->source_code : null,
            'effect_summary' => (string) $card->effect_summary,
            'citation_work' => $card->citation_work !== null ? (string) $card->citation_work : null,
            'citation_year' => $card->citation_year !== null ? (int) $card->citation_year : null,
            'citation_pages' => $card->citation_pages !== null ? (string) $card->citation_pages : null,
            'citation_topic' => $card->citation_topic !== null ? (string) $card->citation_topic : null,
            'user_verified' => (bool) $card->user_verified,
        ];
    }

    /**
     * Pages stay unknown unless a citation row names them.
     *
     * @return list<array{work: string, year: ?int, pages: ?string, topic: string, source_code: ?string}>
     */
    public static function citationsFor(string $topicOrWork): array
    {
        try {
            $q = trim($topicOrWork);
            if ($q === '') {
                return [];
            }
            $rows = Citation::query()
                ->where('topic', 'like', '%'.$q.'%')
                ->orWhere('work', 'like', '%'.$q.'%')
                ->limit(8)
                ->get();
            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'work' => (string) $row->work,
                    'year' => $row->year !== null ? (int) $row->year : null,
                    'pages' => $row->pages !== null ? (string) $row->pages : null,
                    'topic' => (string) $row->topic,
                    'source_code' => $row->source_code !== null ? (string) $row->source_code : null,
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }
}
