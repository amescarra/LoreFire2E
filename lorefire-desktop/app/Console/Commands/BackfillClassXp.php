<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Support\Adnd2e;
use Illuminate\Console\Command;

/**
 * One-time helper: copy characters.experience_points onto class_levels.xp
 * using the documented rules. Does not invent multi-class splits.
 *
 * - single-class: copy the total onto the only entry
 * - dual-class: copy the total onto the current (last) class
 * - multi-class: leave per-class xp empty
 */
class BackfillClassXp extends Command
{
    protected $signature = 'lorefire:backfill-class-xp {--dry-run : Report changes without writing}';

    protected $description = 'Copy legacy experience_points onto class_levels.xp (single/dual only; no multi splits)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $updated = 0;
        $skipped = 0;

        Character::query()->orderBy('id')->each(function (Character $character) use ($dry, &$updated, &$skipped) {
            $path = (string) ($character->class_path ?? 'single');
            $entries = Adnd2e::normalizeClassLevels(
                $character->class_levels,
                (string) $character->class,
                (int) $character->level,
                $path,
            );
            $filled = Adnd2e::backfillClassLevelsXp($entries, $path, $character->experience_points);
            $total = Adnd2e::derivedExperiencePoints($filled, $character->experience_points);

            if ($filled === $entries && $total === (int) $character->experience_points) {
                $skipped++;

                return;
            }

            $this->line(sprintf(
                '%s %s → %s (xp %s)',
                $dry ? '[dry]' : '[write]',
                $character->name,
                Adnd2e::formatClassLevelsLine($filled, $path),
                Adnd2e::formatClassXpLine($filled, $path, false, true) ?: '—',
            ));

            if (! $dry) {
                $character->update([
                    'class_levels' => $filled,
                    'experience_points' => $total,
                ]);
            }
            $updated++;
        });

        $this->info(($dry ? 'Would update ' : 'Updated ').$updated.' character(s); '.$skipped.' unchanged.');

        return self::SUCCESS;
    }
}
