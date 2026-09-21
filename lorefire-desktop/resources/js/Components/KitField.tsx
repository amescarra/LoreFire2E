import React from 'react'
import { Input, Select } from '@/Components/Input'
import { SPECIALIST_SCHOOLS, suggestedRacialKits, kitClassNames } from '@/lib/adnd2e'

const OTHER = '__other__'

interface Props {
  race: string
  entries: Array<{ class: string; level: number }>
  value: string
  onChange: (value: string) => void
}

export function KitField({ race, entries, value, onChange }: Props) {
  const filled = entries.filter(e => e.class)
  const names = kitClassNames(filled)
  const hasMage = names.includes('Mage')
  const kits = suggestedRacialKits(race, filled)
  const listed = new Set<string>([
    ...kits,
    ...(hasMage ? SPECIALIST_SCHOOLS : []),
  ])
  const custom = value !== '' && !listed.has(value)
  const selectValue = custom ? OTHER : value
  const showSuggestions = hasMage || kits.length > 0

  if (!showSuggestions) {
    return (
      <Input
        label="Kit"
        value={value}
        onChange={e => onChange(e.target.value)}
        placeholder="Optional kit"
      />
    )
  }

  return (
    <div className="flex flex-col gap-3">
      <Select
        label="Kit or specialist school"
        value={selectValue}
        onChange={e => {
          const next = e.target.value
          if (next === OTHER) {
            onChange(custom ? value : '')
            return
          }
          onChange(next)
        }}
        hint="Names only. Racial-handbook kits are listed on the page and listed first in the dropdown."
      >
        <option value="">{hasMage ? 'Generalist mage' : 'No kit'}</option>
        {kits.length > 0 && (
          <optgroup label="Racial handbook kits">
            {kits.map(k => <option key={k} value={k}>{k}</option>)}
          </optgroup>
        )}
        {hasMage && (
          <optgroup label="Specialist school">
            {SPECIALIST_SCHOOLS.map(s => <option key={s} value={s}>{s}</option>)}
          </optgroup>
        )}
        <option value={OTHER}>Other (type a name)</option>
      </Select>

      {kits.length > 0 && (
        <div data-testid="racial-handbook-kits" className="flex flex-col gap-2">
          <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]">
            Racial handbook kits
          </p>
          <div className="flex flex-wrap gap-2">
            {kits.map(k => {
              const selected = value === k
              return (
                <button
                  key={k}
                  type="button"
                  data-kit={k}
                  onClick={() => onChange(k)}
                  className={
                    'px-2.5 py-1 text-xs border rounded transition-colors ' +
                    (selected
                      ? 'border-[var(--color-rune)] text-[var(--color-rune-bright)] bg-[rgba(212,160,23,0.12)]'
                      : 'border-[var(--color-border)] text-[var(--color-text-bright)] hover:border-[var(--color-rune-dim)]')
                  }
                >
                  {k}
                </button>
              )
            })}
          </div>
        </div>
      )}

      {custom || selectValue === OTHER ? (
        <Input
          label="Kit name"
          value={value}
          onChange={e => onChange(e.target.value)}
          placeholder="Optional kit"
        />
      ) : null}
    </div>
  )
}
