<?php

namespace App\Support;

/**
 * Compact 2E procedures briefing for local Oracle models.
 *
 * Wording is original and derived from this app's engine
 * (Adnd2e, Adnd2eRacialKits). Do not paste rulebook prose or tables.
 */
class Adnd2eOracleBriefing
{
    /** Max campaign.notes characters injected into the Oracle prompt. */
    public const NOTES_CAP = 4000;

    /** Max character.backstory characters injected per character. */
    public const BACKSTORY_CAP = 1800;

    public static function markdown(): string
    {
        $death = Adnd2e::DEATH_THRESHOLD;
        $warriorHd = Adnd2e::hitDie('Fighter');
        $priestHd = Adnd2e::hitDie('Cleric');
        $rogueHd = Adnd2e::hitDie('Thief');
        $wizardHd = Adnd2e::hitDie('Mage');

        $w1 = Adnd2e::thac0('Fighter', 1);
        $w5 = Adnd2e::thac0('Fighter', 5);
        $w10 = Adnd2e::thac0('Fighter', 10);
        $p1 = Adnd2e::thac0('Cleric', 1);
        $p4 = Adnd2e::thac0('Cleric', 4);
        $r1 = Adnd2e::thac0('Thief', 1);
        $r5 = Adnd2e::thac0('Thief', 5);
        $m1 = Adnd2e::thac0('Mage', 1);
        $m6 = Adnd2e::thac0('Mage', 6);

        $needUnarmored = Adnd2e::numberNeededToHit(20, 10);
        $needAc0 = Adnd2e::numberNeededToHit(20, 0);

        $saves = implode('; ', Adnd2e::SAVE_CATEGORIES);
        $schools = implode(', ', Adnd2e::SPECIALIST_SCHOOLS);
        $classes = implode(', ', Adnd2e::CLASSES);
        $races = implode(', ', Adnd2e::RACES);

        $mageMem = Adnd2e::memorizationCapacity('Mage', 1, 10);
        $specMem = Adnd2e::memorizationCapacity('Mage', 1, 10, 'Illusionist');
        $mageL1 = $mageMem[1] ?? 0;
        $specL1 = $specMem[1] ?? 0;
        $leatherAc = Adnd2e::armorBaseAc('leather');
        $chainAc = Adnd2e::armorBaseAc('chain mail');
        $plateAc = Adnd2e::armorBaseAc('plate mail');
        $longSword = Adnd2e::weaponStats('long sword');
        $clericSpheres = Adnd2e::priestSpheres('Cleric');
        $str10enc = Adnd2e::encumbranceThresholds(10);

        $lines = [
            '## Lorefire 2E procedures (app engine)',
            '',
            'This table uses Advanced Dungeons & Dragons 2nd Edition as implemented in Lorefire 2E. Do not answer as 5th Edition. Explain in your own words. Do not quote rulebooks.',
            '',
            '### Combat',
            '- Lower THAC0 is better. Warrior (Fighter, Paladin, Ranger) THAC0 at 1/5/10: '.$w1.'/'.$w5.'/'.$w10.'. Priest (Cleric, Druid) at 1/4: '.$p1.'/'.$p4.'. Rogue (Thief, Bard, and Psionicist) at 1/5: '.$r1.'/'.$r5.'. Wizard (Mage) at 1/6: '.$m1.'/'.$m6.'.',
            '- Armor Class is descending (10 unarmored; lower is better). Number needed on d20 = THAC0 minus descending AC. Example: THAC0 20 vs AC 10 needs '.$needUnarmored.'; vs AC 0 needs '.$needAc0.'. This app treats 1 as a miss and 20 as a hit.',
            '- Armor base AC: leather '.$leatherAc.', chain mail '.$chainAc.', plate mail '.$plateAc.'. Shield '.Adnd2e::SHIELD_AC_BONUS.'.',
            '- Hit dice by group: warrior '.$warriorHd.', priest '.$priestHd.', rogue '.$rogueHd.', wizard '.$wizardHd.'.',
            '- Initiative: d10, lower acts first. Dexterity reaction is subtracted. Long sword SM/L/speed: '.($longSword['sm'] ?? '?').'/'.($longSword['l'] ?? '?').'/'.($longSword['speed'] ?? '?').'.',
            '- STR 10 encumbrance none/severe lb: '.$str10enc['none'].'/'.$str10enc['severe'].'. Movement drops by category (none / light / moderate / heavy / severe).',
            '',
            '### Saves, rest, vitality',
            '- Five save categories (roll d20 >= target): '.$saves.'.',
            '- Overnight rest only: recover 1 hit point if above '.$death.', rememorize, reset daily class abilities. No short/long-rest cycle.',
            '- Vitality: 0 unconscious; negative is dying; dead at '.$death.'.',
            '- During a recorded live session, clearly spoken sheet changes (HP, gold, XP, inventory names, named memorized spells) are applied as play happens. You may also confirm a sheet write if asked (for example, mark a named memorized spell cast). Do not invent unknown spells.',
            '',
            '### Magic',
            '- Vancian memorization (counts by spell level). A 1st-level generalist Mage memorizes '.$mageL1.' first-level spell. A specialist (school recorded as kit) memorizes '.$specL1.' at that level (one extra per school level they can already memorize). Priests may gain extra first- and second-level capacity from high Wisdom.',
            '- Cleric sphere tags major: '.implode(', ', $clericSpheres['major']).'; minor: '.implode(', ', $clericSpheres['minor']).'. Names only.',
            '- Spell headers (name/class/level/tag/components/time/range/duration; optional M names) are Adnd2eSpellCatalog. Example: Fireball Mage L3 invocation. No effect prose.',
            '- Gear weight and magical bonus are Adnd2eEquipmentCatalog (plate mail +1 AC 2; long sword +2 bonus +2). Artifacts are name + tags only.',
            '- Creature stubs are AC/HD/THAC0/dmg labels for math only. Prefer campaign NPC hooks over monster-manual dumps.',
            '- Classes in this app: '.$classes.'. Races: '.$races.'.',
            '- Psionicist is a class (aliases: Psion, Psionic, Psionics). Combat figures use the rogue group. PSP totals and typed power names are sheet fields the player fills; they are not simulated. Do not invent 5th Edition psionics. Do not treat Psionicist as a kit.',
            '',
            '### Advancement and the kit field',
            '- Single-class: one class. Multi-class: classes advance together; combined THAC0 is the best (lowest); saves take the best (lowest) per category.',
            '- Dual-class on this table is the house switch (humans and others): original class first, then the new class. Begin a new class at 6th in the original; switch back when the new class is 5th. Until then the new class is active; original abilities stay on the sheet. Do not apply PHB dual-class XP penalties.',
            '- Kit / specialist is one optional free-text field for every class, including Psionicist. Suggestions are names only, filtered by race and required class(es). Specialist schools ('.$schools.') appear for Mages. Racial-handbook kit names (for example Bladesinger) appear only when race and class path match — Bladesinger is Elf + Fighter/Mage, not a generic Mage/wizard-handbook kit. Do not invent kit benefit tables. Discipline names are not kits.',
            '- Weapon and non-weapon proficiencies on the sheet are names only.',
            '',
            'When campaign or character rows are provided, use the sheet\'s stored THAC0, descending AC, HP, class_path, kit, backstory, and campaign notes. Do not invent those numbers or origin stories.',
        ];

        return implode("\n", $lines);
    }

    /**
     * Full Oracle system prompt: briefing always, then optional campaign block.
     *
     * @param  array<string, mixed>  $context
     */
    public static function systemPrompt(array $context, ?string $question = null): string
    {
        $lines = [];
        $lines[] = 'You are the Oracle — a wise, slightly enigmatic advisor to a tabletop group using Lorefire 2E. You can answer questions about Advanced Dungeons & Dragons 2nd Edition mechanics (THAC0, descending Armor Class, Vancian memorization, weapon and non-weapon proficiencies, 2E saving-throw categories, surprise, initiative on a d10), their characters, session history, NPCs, and anything else they need. Do not answer as if the table were using 5th Edition. Be helpful, clear, and concise. You may use markdown for formatting. When answering rules questions, explain the mechanic in your own words — do not quote copyrighted rulebook text. Never invent official spell text, PHB/DMG/Complete Handbook pages, or table numbers that are not in the Engine lookup or the procedures briefing. For core numeric and table questions, you MUST use Engine lookup results when that section is present; if it says the engine cannot answer, say you do not have that in the Lorefire 2E engine rather than guessing. Campaign notes and character backstory may still inform story rulings. When referencing their specific characters or campaign, use the data provided.';
        $lines[] = '';
        $lines[] = self::markdown();

        $question = trim((string) $question);
        if ($question !== '') {
            $lookup = Adnd2eOracleRulesLookup::markdown($question, $context);
            if ($lookup !== '') {
                $lines[] = '';
                $lines[] = $lookup;
            }
        }

        if (! empty($context['campaigns'])) {
            $lines[] = '';
            $lines[] = '## Campaign Data';
            foreach ($context['campaigns'] as $campaign) {
                if (! is_array($campaign)) {
                    continue;
                }
                $lines[] = '';
                $lines[] = '### Campaign: '.($campaign['name'] ?? 'Unnamed');
                if (! empty($campaign['description'])) {
                    $lines[] = (string) $campaign['description'];
                }

                $notes = self::clipNarrative($campaign['notes'] ?? '', self::NOTES_CAP);
                if ($notes !== null) {
                    $lines[] = '';
                    $lines[] = '**Campaign notes:**';
                    $lines[] = $notes;
                }

                if (! empty($campaign['characters']) && is_array($campaign['characters'])) {
                    $lines[] = '';
                    $lines[] = '**Characters:**';
                    foreach ($campaign['characters'] as $c) {
                        if (! is_array($c)) {
                            continue;
                        }
                        $lines[] = self::formatCharacterLine($c);
                        $backstory = self::clipNarrative($c['backstory'] ?? '', self::BACKSTORY_CAP);
                        if ($backstory !== null) {
                            $lines[] = '  **Backstory:**';
                            $lines[] = '  '.str_replace("\n", "\n  ", $backstory);
                        }
                    }
                }

                if (! empty($campaign['npcs']) && is_array($campaign['npcs'])) {
                    $lines[] = '';
                    $lines[] = '**NPCs:**';
                    foreach ($campaign['npcs'] as $npc) {
                        if (! is_array($npc)) {
                            continue;
                        }
                        $lines[] = self::formatNpcLine($npc);
                    }
                }

                if (! empty($campaign['game_sessions']) && is_array($campaign['game_sessions'])) {
                    $lines[] = '';
                    $lines[] = '**Recent Sessions:**';
                    foreach ($campaign['game_sessions'] as $s) {
                        if (! is_array($s)) {
                            continue;
                        }
                        $line = '- Session '.($s['session_number'] ?? '?').': '.($s['title'] ?? 'Untitled');
                        if (! empty($s['played_at'])) {
                            $line .= ' ('.$s['played_at'].')';
                        }
                        $lines[] = $line;
                        if (! empty($s['session_notes'])) {
                            $lines[] = '  '.str_replace("\n", "\n  ", trim((string) $s['session_notes']));
                        }
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Prefer stored sheet figures over invented combat numbers.
     *
     * @param  array<string, mixed>  $c
     */
    public static function formatCharacterLine(array $c): string
    {
        $line = '- '.($c['name'] ?? 'Unknown');
        if (! empty($c['race'])) {
            $line .= ', '.$c['race'];
        }
        if (! empty($c['class'])) {
            $line .= ' '.$c['class'];
        }
        if (($c['level'] ?? null) !== null && $c['level'] !== '') {
            $line .= ' (Level '.$c['level'].')';
        }
        if (! empty($c['class_path'])) {
            $line .= ' ['.$c['class_path'].']';
        }
        if (! empty($c['subclass'])) {
            $line .= ' kit: '.$c['subclass'];
        }
        if (($c['current_hp'] ?? null) !== null) {
            $line .= ' | HP: '.$c['current_hp'].'/'.($c['max_hp'] ?? '?');
        }
        if (($c['armor_class'] ?? null) !== null && $c['armor_class'] !== '') {
            $line .= ' | AC: '.$c['armor_class'].' (descending)';
        }
        if (($c['thac0'] ?? null) !== null && $c['thac0'] !== '') {
            $line .= ' | THAC0: '.$c['thac0'];
        }
        if (($c['gold'] ?? null) !== null && $c['gold'] !== '') {
            $line .= ' | Gold: '.$c['gold'];
        }
        if (($c['experience_points'] ?? null) !== null && $c['experience_points'] !== '') {
            $line .= ' | XP: '.$c['experience_points'];
        }

        $abilityBits = [];
        foreach (['strength' => 'STR', 'dexterity' => 'DEX', 'constitution' => 'CON', 'intelligence' => 'INT', 'wisdom' => 'WIS', 'charisma' => 'CHA'] as $ability => $abbrev) {
            if (! isset($c[$ability]) || $c[$ability] === '' || $c[$ability] === null) {
                continue;
            }
            $exceptional = $ability === 'strength' ? ($c['exceptional_strength'] ?? null) : null;
            $abilityBits[] = $abbrev.' '.Adnd2e::formatAbilityScore(
                $ability,
                (int) $c[$ability],
                is_string($exceptional) || is_int($exceptional) ? (string) $exceptional : null
            );
        }
        if ($abilityBits !== []) {
            $line .= ' | '.implode(' ', $abilityBits);
        }

        return $line;
    }

    /**
     * Campaign NPC hooks + optional stat-block labels. No description/notes prose.
     *
     * @param  array<string, mixed>  $npc
     */
    public static function formatNpcLine(array $npc): string
    {
        $line = '- '.($npc['name'] ?? 'Unknown');
        if (! empty($npc['race'])) {
            $line .= ', '.$npc['race'];
        }
        if (! empty($npc['role'])) {
            $line .= ', '.$npc['role'];
        }
        if (! empty($npc['location'])) {
            $line .= ' @ '.$npc['location'];
        }
        if (! empty($npc['attitude'])) {
            $line .= ', '.$npc['attitude'];
        }
        if (! empty($npc['last_seen'])) {
            $line .= ', last seen '.$npc['last_seen'];
        }
        if (! empty($npc['tags']) && is_array($npc['tags'])) {
            $line .= ', tags '.implode('/', $npc['tags']);
        }
        if (array_key_exists('is_alive', $npc) && $npc['is_alive'] === false) {
            $line .= ' [dead]';
        }

        $block = $npc['stat_block'] ?? null;
        if (is_array($block)) {
            $bits = [];
            foreach (['ac' => 'AC', 'hd' => 'HD', 'thac0' => 'THAC0', 'dmg' => 'dmg', 'damage' => 'dmg'] as $key => $label) {
                if (! isset($block[$key]) || $block[$key] === '' || $block[$key] === null) {
                    continue;
                }
                if ($key === 'damage' && isset($block['dmg'])) {
                    continue;
                }
                $bits[] = $label.' '.$block[$key];
            }
            if ($bits !== []) {
                $line .= ' | '.implode(', ', $bits);
            }
        }

        return $line;
    }

    /**
     * Trim campaign notes / character backstory for the prompt.
     * Returns null when empty so callers can omit the section.
     */
    public static function clipNarrative(mixed $value, int $max): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max)).' (truncated)';
    }
}
