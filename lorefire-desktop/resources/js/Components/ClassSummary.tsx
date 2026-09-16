import React from 'react'
import { Badge } from '@/Components/Badge'
import { Character } from '@/types'
import {
  backfillClassLevelsXp,
  formatClassLevelsLine,
  formatClassXpLine,
  normalizeClassLevels,
} from '@/lib/adnd2e'

interface Props {
  character: Character
  showXp?: boolean
  /** badge = compact chip (Characters Index). line = full-width wrapping text (Campaign party). */
  variant?: 'badge' | 'line'
}

export function characterClassDisplay(character: Character) {
  const path = character.class_path ?? 'single'
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
