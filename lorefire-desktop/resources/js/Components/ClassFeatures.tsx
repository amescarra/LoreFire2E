import React from 'react'
import { normalizeClass } from '@/lib/adnd2e'

interface Props {
  characterClass: string
  level: number
  charisma: number
  wisdom: number
  value: Record<string, unknown>
  onChange: (next: Record<string, unknown>) => void
}

function Field({ label, hint, value, min, max, onChange }: {
  label: string
  hint?: string
  value: number
  min: number
  max: number
  onChange: (v: number) => void
}) {
  return (
    <div className="flex flex-col gap-1">
      <label className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]">{label}</label>
      {hint && <p className="text-[10px] text-[var(--color-text-dim)] opacity-70">{hint}</p>}
      <input
        type="number"
        min={min}
        max={max}
        value={value}
        onChange={e => onChange(parseInt(e.target.value) || 0)}
        className="w-full text-center bg-[var(--color-deep)] border border-[var(--color-border)] rounded py-1.5 text-[var(--color-text-white)] font-heading text-sm focus:outline-none focus:border-[var(--color-rune)]"
      />
    </div>
  )
}

function CheckRow({ label, checked, onChange }: { label: string; checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <label className="flex items-center gap-2 cursor-pointer">
      <input type="checkbox" checked={checked} onChange={e => onChange(e.target.checked)} className="accent-[var(--color-rune)]" />
      <span className="text-xs text-[var(--color-text-base)]">{label}</span>
    </label>
  )
}

const THIEF_SKILLS = [
  ['pick_pockets', 'Pick Pockets'],
  ['open_locks', 'Open Locks'],
  ['find_remove_traps', 'Find/Remove Traps'],
  ['move_silently', 'Move Silently'],
  ['hide_in_shadows', 'Hide in Shadows'],
  ['detect_noise', 'Detect Noise'],
  ['climb_walls', 'Climb Walls'],
  ['read_languages', 'Read Languages'],
] as const

export function ClassFeatures({ characterClass, level, value, onChange }: Props) {
  const set = (key: string, v: unknown) => onChange({ ...value, [key]: v })
  const num = (key: string, fallback: number) => (typeof value[key] === 'number' ? value[key] as number : fallback)
  const cls = normalizeClass(characterClass)

  if (cls === 'Fighter') {
    return (
      <div className="flex flex-col gap-3">
        <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]">Warrior abilities</p>
        <InputRow label="Weapon specialization" value={String(value.weapon_specialization ?? '')} onChange={v => set('weapon_specialization', v)} placeholder="e.g. Long sword" />
        <Field label="Attacks / round" hint="1 at low level; 3/2 then 2/1 as a warrior advances" value={num('attacks_per_round', 1)} min={1} max={4} onChange={v => set('attacks_per_round', v)} />
      </div>
    )
  }

  if (cls === 'Paladin') {
    const layMax = num('lay_on_hands_max', level * 2)
    return (
      <div className="flex flex-col gap-3">
        <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]">Paladin abilities</p>
        <p className="text-xs text-[var(--color-text-dim)]">Lay on Hands restores 2 hit points per level, once per day.</p>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Lay on Hands max" hint={`${level * 2} HP / day`} value={layMax} min={0} max={80} onChange={v => set('lay_on_hands_max', v)} />
          <Field label="Remaining" value={num('lay_on_hands_current', layMax)} min={0} max={layMax} onChange={v => set('lay_on_hands_current', v)} />
        </div>
        <CheckRow label="Detect Evil ready" checked={Boolean(value.detect_evil_ready ?? true)} onChange={v => set('detect_evil_ready', v)} />
        <CheckRow label="Protection from Evil aura" checked={Boolean(value.protection_from_evil ?? true)} onChange={v => set('protection_from_evil', v)} />
        {level >= 3 && <CheckRow label="Turn Undead ready" checked={Boolean(value.turn_undead_ready ?? true)} onChange={v => set('turn_undead_ready', v)} />}
      </div>
    )
  }

  if (cls === 'Ranger') {
    return (
      <div className="flex flex-col gap-3">
        <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]">Ranger abilities</p>
        <InputRow label="Species enemy" value={String(value.species_enemy ?? '')} onChange={v => set('species_enemy', v)} placeholder="e.g. Giants" />
        <Field label="Tracking score" value={num('tracking_score', 0)} min={0} max={100} onChange={v => set('tracking_score', v)} />
        <CheckRow label="Tracking ready today" checked={Boolean(value.tracking_ready ?? true)} onChange={v => set('tracking_ready', v)} />
        <div className="grid grid-cols-2 gap-3">
          <Field label="Hide in Shadows %" value={num('hide_in_shadows', 0)} min={0} max={100} onChange={v => set('hide_in_shadows', v)} />
          <Field label="Move Silently %" value={num('move_silently', 0)} min={0} max={100} onChange={v => set('move_silently', v)} />
        </div>
      </div>
    )
  }

  if (cls === 'Cleric') {
    return (
      <div className="flex flex-col gap-3">
        <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]">Priest abilities</p>
        <CheckRow label="Turn Undead ready" checked={Boolean(value.turn_undead_ready ?? true)} onChange={v => set('turn_undead_ready', v)} />
        <InputRow label="Deity / faith" value={String(value.deity ?? '')} onChange={v => set('deity', v)} />
      </div>
    )
  }

  if (cls === 'Druid') {
    return (
      <div className="flex flex-col gap-3">
        <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]">Druid abilities</p>
        <CheckRow label="Identify plants / animals" checked={Boolean(value.identify_plants ?? true)} onChange={v => set('identify_plants', v)} />
        {level >= 7 && <CheckRow label="Shapechange used today" checked={Boolean(value.shapechange_used)} onChange={v => set('shapechange_used', v)} />}
      </div>
    )
  }

  if (cls === 'Thief') {
    return (
      <div className="flex flex-col gap-3">
        <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]">Thief skill scores (%)</p>
        <div className="grid grid-cols-2 gap-3">
          {THIEF_SKILLS.map(([key, label]) => (
            <Field key={key} label={label} value={num(key, 0)} min={0} max={100} onChange={v => set(key, v)} />
          ))}
        </div>
        <CheckRow label="Backstab eligible this round" checked={Boolean(value.backstab_ready)} onChange={v => set('backstab_ready', v)} />
      </div>
    )
  }

  if (cls === 'Bard') {
    return (
      <div className="flex flex-col gap-3">
        <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]">Bard abilities</p>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Climb Walls %" value={num('climb_walls', 0)} min={0} max={100} onChange={v => set('climb_walls', v)} />
          <Field label="Detect Noise %" value={num('detect_noise', 0)} min={0} max={100} onChange={v => set('detect_noise', v)} />
          <Field label="Pick Pockets %" value={num('pick_pockets', 0)} min={0} max={100} onChange={v => set('pick_pockets', v)} />
          <Field label="Read Languages %" value={num('read_languages', 0)} min={0} max={100} onChange={v => set('read_languages', v)} />
        </div>
        <CheckRow label="Influence reactions ready" checked={Boolean(value.influence_ready ?? true)} onChange={v => set('influence_ready', v)} />
        <CheckRow label="Legend lore ready" checked={Boolean(value.legend_lore_ready ?? true)} onChange={v => set('legend_lore_ready', v)} />
      </div>
    )
  }

  if (cls === 'Mage') {
    return (
      <div className="flex flex-col gap-3">
        <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]">Wizard notes</p>
        <InputRow label="Opposition school (if specialist)" value={String(value.opposition_school ?? '')} onChange={v => set('opposition_school', v)} placeholder="Banned school" />
        <p className="text-xs text-[var(--color-text-dim)]">Specialists memorize one extra spell per level of their school. Record the school as specialist school, not as a kit.</p>
      </div>
    )
  }

  return null
}

function InputRow({ label, value, onChange, placeholder }: { label: string; value: string; onChange: (v: string) => void; placeholder?: string }) {
  return (
    <div className="flex flex-col gap-1">
      <label className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]">{label}</label>
      <input
        value={value}
        placeholder={placeholder}
        onChange={e => onChange(e.target.value)}
        className="w-full bg-[var(--color-deep)] border border-[var(--color-border)] rounded px-2 py-1.5 text-sm text-[var(--color-text-base)] focus:outline-none focus:border-[var(--color-rune)]"
      />
    </div>
  )
}
