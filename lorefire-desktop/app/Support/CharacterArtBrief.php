<?php

namespace App\Support;

use App\Models\Character;
use App\Models\InventoryItem;

/**
 * Sheet-truth visual brief for image prompts.
 *
 * Appearance text is copied as written. Clothing, armor, weapons, and
 * carried gear come only from equipped inventory. Unequipped pack items
 * are omitted so the image model is not invited to depict them.
 */
class CharacterArtBrief
{
    public const NO_INVENT_GEAR = 'Do not invent extra weapons, armor, or magic items.';

    /**
     * CLIPTextEncodeLumina2 accepts any user_prompt string, including empty.
     * A short negative steers the sampler away from gear the sheet did not list.
     */
    public const GEAR_HALLUCINATION_NEGATIVE = 'extra weapons, extra armor, invented magic items, armor colors not described, weapons not described, physical traits not on the character sheet';

    /**
     * @param  list<array{name: string, quantity: int, detail: ?string}>  $equipped
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $race,
        public readonly ?string $subrace,
        public readonly ?string $className,
        public readonly ?string $subclass,
        public readonly ?int $level,
        public readonly ?string $appearance,
        public readonly ?string $visualMannerism,
        public readonly array $equipped,
    ) {}

    public static function from(Character $character): self
    {
        $character->loadMissing('inventoryItems');

        $equipped = $character->inventoryItems
            ->filter(fn (InventoryItem $item) => (bool) $item->equipped)
            ->sortBy(fn (InventoryItem $item) => mb_strtolower((string) $item->name))
            ->map(function (InventoryItem $item) {
                $name = trim((string) $item->name);

                return [
                    'name' => $name,
                    'quantity' => max(1, (int) $item->quantity),
                    'detail' => self::itemDetail($item),
                ];
            })
            ->filter(fn (array $item) => $item['name'] !== '')
            ->values()
            ->all();

        $appearance = trim((string) ($character->appearance_description ?? ''));

        return new self(
            name: (string) $character->name,
            race: self::blankToNull($character->race),
            subrace: self::blankToNull($character->subrace),
            className: self::blankToNull($character->class),
            subclass: self::blankToNull($character->subclass),
            level: $character->level ? (int) $character->level : null,
            appearance: $appearance !== '' ? $appearance : null,
            visualMannerism: self::visualMannerism($character->mannerisms),
            equipped: $equipped,
        );
    }

    /**
     * Rules embedded in the art-director prompt. Gear and body traits
     * may only be copied from the party block; the scene itself may
     * still be taken from the session text.
     */
    public static function artDirectorInstructions(): string
    {
        return <<<'TEXT'
2. CHARACTERS: For every character present, use only the matching PARTY MEMBERS block. The scene's environment, objects, creatures, weather, and lighting still come from the session text.
   - Race, class, and level may be stated only as written on that block.
   - If an Appearance line is present, use that text for face, body, and distinguishing features. Do not add hair, eyes, skin, height, scars, or other physical traits the Appearance line does not state.
   - If Appearance is unrecorded, say the appearance is unrecorded and depict only the race and class already on the sheet. Do not invent detailed face or body traits.
   - Clothing, armor, weapons, and carried items may ONLY come from that character's Equipped list and from their Appearance text. If Equipped is "none", write plain undetailed clothing and no weapons, armor, or magic items.
   - Never invent magic items, colors of armor, or weapons that are not listed. Missing gear stays missing.
   - A visible mannerism, when present, may be used as a short pose cue. Do not depict mannerisms that are not visual, and do not turn a mannerism into extra equipment.
TEXT;
    }

    public static function withGearNegative(?string $negative): string
    {
        $negative = trim((string) $negative);
        $gear = self::GEAR_HALLUCINATION_NEGATIVE;
        if ($negative === '') {
            return $gear;
        }
        if (str_contains(mb_strtolower($negative), 'invented magic items')) {
            return $negative;
        }

        return $negative.', '.$gear;
    }

    public function identityLine(): string
    {
        $race = $this->race && $this->subrace
            ? "{$this->race} ({$this->subrace})"
            : $this->race;
        $class = $this->className && $this->subclass
            ? "{$this->className} ({$this->subclass})"
            : $this->className;

        $attrs = array_filter([
            $race,
            $class,
            $this->level ? "level {$this->level}" : null,
        ]);
        $summary = implode(', ', $attrs);

        return '- '.$this->name.($summary !== '' ? " — {$summary}" : '');
    }

    public function raceAndClassPhrase(): string
    {
        $race = $this->race ?? 'human';
        if ($this->subrace) {
            $race .= ' ('.$this->subrace.')';
        }
        $class = $this->className ?? 'adventurer';
        if ($this->subclass) {
            $class .= ' ('.$this->subclass.')';
        }

        return $race.' '.$class;
    }

    /**
     * Party-member block for the art-director model.
     */
    public function contextBlock(): string
    {
        $lines = [$this->identityLine()];

        if ($this->appearance !== null) {
            $lines[] = '  Appearance: '.$this->appearance;
        } else {
            $lines[] = '  Appearance: unrecorded. Depict only the race and class above. Do not invent detailed face, hair, eye, skin, height, or body traits.';
        }

        if ($this->visualMannerism !== null) {
            $lines[] = '  Visible mannerism: '.$this->visualMannerism;
        }

        $lines[] = '  Equipped (depict these only): '.$this->equippedPhrase();
        if ($this->equipped === []) {
            $lines[] = '  Clothing: plain undetailed clothing. No weapons, armor, or magic items are equipped.';
        }

        return implode("\n", $lines);
    }

    /**
     * Lines appended to a direct image prompt (portrait or party).
     *
     * @return list<string>
     */
    public function imagePromptLines(): array
    {
        $lines = [];

        if ($this->appearance !== null) {
            $lines[] = 'Appearance: '.$this->appearance;
        } else {
            $lines[] = 'Appearance is not recorded. Depict only the listed race and class. Do not invent detailed face, hair, eye, skin, height, or body traits.';
        }

        if ($this->visualMannerism !== null) {
            $lines[] = 'Visible mannerism: '.$this->visualMannerism;
        }

        $lines[] = 'Equipped (depict these only): '.$this->equippedPhrase().'.';
        if ($this->equipped === []) {
            $lines[] = 'Wear plain undetailed clothing only.';
        }
        $lines[] = self::NO_INVENT_GEAR;

        return $lines;
    }

    public function imagePromptBlock(): string
    {
        return implode("\n", $this->imagePromptLines());
    }

    public function equippedPhrase(): string
    {
        if ($this->equipped === []) {
            return 'none';
        }

        return collect($this->equipped)
            ->map(function (array $item) {
                $label = $item['name'];
                if (($item['quantity'] ?? 1) > 1) {
                    $label .= ' ×'.$item['quantity'];
                }
                if (! empty($item['detail'])) {
                    $label .= ' ('.$item['detail'].')';
                }

                return $label;
            })
            ->implode('; ');
    }

    private static function itemDetail(InventoryItem $item): ?string
    {
        $parts = [];
        $description = trim((string) ($item->description ?? ''));
        if ($description !== '') {
            $parts[] = self::shorten($description, 120);
        }

        $properties = $item->properties;
        if (is_array($properties) && $properties !== []) {
            $flat = self::flattenProperties($properties);
            if ($flat !== '') {
                $parts[] = self::shorten($flat, 80);
            }
        }

        if ($item->is_magical) {
            $parts[] = 'magical';
        }

        $detail = implode('; ', $parts);

        return $detail !== '' ? $detail : null;
    }

    /**
     * @param  array<int|string, mixed>  $properties
     */
    private static function flattenProperties(array $properties): string
    {
        $bits = [];
        foreach ($properties as $key => $value) {
            if (is_int($key)) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $bits[] = trim((string) $value);
                }

                continue;
            }
            if (is_bool($value)) {
                if ($value) {
                    $bits[] = (string) $key;
                }

                continue;
            }
            if (is_scalar($value) && trim((string) $value) !== '') {
                $bits[] = $key.': '.trim((string) $value);
            }
        }

        return implode(', ', $bits);
    }

    private static function visualMannerism(mixed $mannerisms): ?string
    {
        $text = trim((string) $mannerisms);
        if ($text === '') {
            return null;
        }

        $parts = preg_split('/(?<=[.!?])\s+/u', $text) ?: [$text];
        $visual = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '' && self::isVisuallyRelevant($part)) {
                $visual[] = $part;
            }
        }

        if ($visual === []) {
            return null;
        }

        return self::shorten(implode(' ', $visual), 160);
    }

    private static function isVisuallyRelevant(string $text): bool
    {
        return (bool) preg_match(
            '/\b(stance|posture|gait|limp|limps|stride|walks|walking|stands|standing|sits|sitting|leans|leaning|crouch|crouches|kneel|kneels|gesture|gestures|fidget|fidgets|twitch|smile|smiles|smiling|scowl|scowls|glare|glares|frown|frowns|grin|grins|expression|scar|scars|tattoo|tattoos|cloak|hood|cowl|hilt|grip|grips|holds|holding|hand|hands|bows|nods|slouch|slouches|hunched|barefoot|braid|braids|beard|shoulder|shoulders|pose|paces|pacing)\b/iu',
            $text,
        );
    }

    private static function shorten(string $text, int $max): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 3)).'...';
    }

    private static function blankToNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }
}
