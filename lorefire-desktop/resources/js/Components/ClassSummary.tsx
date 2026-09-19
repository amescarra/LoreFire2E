import React from 'react'
import { Badge } from '@/Components/Badge'
import { Character } from '@/types'
import {
  backfillClassLevelsXp,
  formatClassLevelsLine,
  formatClassXpLine,
  normalizeClassLevels,
  resolveClassPath,
} from '@/lib/adnd2e'

interface Props {
  character: Character
  showXp?: boolean
  /** badge = compact chip (Characters Index). line = Campaign party gold line. */
  variant?: 'badge' | 'line'
}

export function characterClassDisplay(character: Character) {
  const path = resolveClassPath(character.class_path, character.class_levels, character.class)
  const entries = backfillClassLevelsXp(
    normalizeClassLevels(character.class_levels, character.class, character.level, path),
    path,
    character.experience_points ?? 0,
  )
  return {
    path,
    entries,
    levelsLine: formatClassLevelsLine(entries, path) || `Lv ${character.level}`,
    xpLine: formatClassXpLine(entries, path, true, true),
    xpLineFull: formatClassXpLine(entries, path, false, false),
  }
}

/** Same subtitle Characters Index uses: race class — kit · player · XP. */
export function characterListSubtitle(character: Character): string {
  const { xpLine } = characterClassDisplay(character)
  let line = `${character.race} ${character.class}`
  if (character.subclass) line += ` — ${character.subclass}`
  if (character.player_name) line += ` · ${character.player_name}`
  if (xpLine) line += ` · ${xpLine}`
  return line
}

export function ClassSummary({ character, showXp = true, variant = 'badge' }: Props) {
  const { levelsLine, xpLine } = characterClassDisplay(character)

  if (variant === 'line') {
    return (
      <p className="text-[11px] font-medium text-[var(--color-rune)] tracking-wide break-words">
        {levelsLine}
        {showXp && xpLine ? ` · ${xpLine}` : ''}
      </p>
    )
  }

  return (
    <div className="flex flex-col gap-0.5 shrink-0">
      <Badge variant="rune" className="normal-case tracking-wider whitespace-nowrap">{levelsLine}</Badge>
      {showXp && xpLine && (
        <span className="text-[10px] font-mono text-[var(--color-text-dim)] tracking-wide whitespace-nowrap">
          {xpLine}
        </span>
      )}
    </div>
  )
}
