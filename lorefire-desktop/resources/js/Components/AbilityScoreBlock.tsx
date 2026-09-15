import React from 'react'
import {
  AbilityKey,
  abilityAdjustmentLines,
  formatAbilityScore,
  formatSigned,
  primaryAdjustment,
  primaryAdjustmentLabel,
} from '@/lib/adnd2e'

interface AbilityScoreBlockProps {
  ability: AbilityKey
  label: string
  score: number
  exceptional?: string | null
  characterClass?: string
  variant?: 'sheet' | 'form' | 'live' | 'chip'
  scoreControl?: React.ReactNode
}

export function AbilityScoreBlock({
  ability,
  label,
  score,
  exceptional = null,
  characterClass = 'Fighter',
  variant = 'sheet',
  scoreControl,
}: AbilityScoreBlockProps) {
  const lines = abilityAdjustmentLines(ability, score, exceptional, characterClass)
  const displayScore = formatAbilityScore(ability, score, exceptional)
  const primary = formatSigned(primaryAdjustment(ability, score, exceptional, characterClass))
  const primaryLabel = primaryAdjustmentLabel(ability)

  if (variant === 'chip') {
    return (
      <div className="text-center" data-testid={`ability-chip-${ability}`}>
        <div className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]">{label}</div>
        <div className="text-sm font-mono text-[var(--color-rune-bright)] leading-tight">{primary}</div>
        <div className="text-[9px] uppercase tracking-wide text-[var(--color-text-dim)]">{primaryLabel}</div>
      </div>
    )
  }

  const compact = variant === 'live'
  const lineClass = compact
    ? 'text-[8px] leading-tight'
    : 'text-[9px] leading-tight'

  return (
    <div
      data-testid={`ability-block-${ability}`}
      className={
        variant === 'form'
          ? 'flex flex-col items-stretch gap-1'
          : compact
            ? 'flex flex-col items-stretch py-1 px-1.5 rounded'
            : 'runic-card p-2.5 flex flex-col items-stretch gap-1'
      }
      style={compact ? { background: 'var(--color-deep)', border: '1px solid var(--color-border)' } : undefined}
    >
      <span className={`uppercase tracking-widest text-[var(--color-text-dim)] text-center ${compact ? 'text-[9px]' : 'text-[10px]'}`}>
        {label}
      </span>
      {variant === 'form' && scoreControl
        ? scoreControl
        : (
          <span className={`font-heading text-center text-[var(--color-text-white)] leading-none ${compact ? 'text-sm font-bold' : 'text-xl'}`}>
            {displayScore}
          </span>
        )}
      <div className={`flex justify-center items-baseline gap-1 font-mono ${compact ? 'text-[9px]' : 'text-xs'} text-[var(--color-rune)]`}>
        <span>{primary}</span>
        <span className="uppercase tracking-wide text-[var(--color-text-dim)] text-[9px]">{primaryLabel}</span>
      </div>
      <dl className={`mt-0.5 grid grid-cols-[auto_1fr] gap-x-1.5 gap-y-px font-mono ${lineClass} text-[var(--color-text-dim)]`}>
        {lines.map(line => (
          <React.Fragment key={line.label}>
            <dt className="uppercase tracking-wide text-right opacity-80">{line.label}</dt>
            <dd className="text-[var(--color-text-base)] tabular-nums">{line.value}</dd>
          </React.Fragment>
        ))}
      </dl>
    </div>
  )
}
