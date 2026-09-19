import React from 'react'
import { Input, Select } from '@/Components/Input'
import { CLASSES, ClassEntry, ClassPath, canBeginNewClass, canResumeOriginalClass } from '@/lib/adnd2e'

interface Props {
  path: ClassPath
  entries: ClassEntry[]
  onPath: (path: ClassPath) => void
  onEntries: (entries: ClassEntry[]) => void
}

export function ClassPathFields({ path, entries, onPath, onEntries }: Props) {
  const setEntry = (index: number, patch: Partial<ClassEntry>) => {
    const next = entries.map((e, i) => {
      if (i !== index) return e
      const merged: ClassEntry = { ...e, ...patch }
      if ('xp' in patch && (patch.xp === undefined || patch.xp === null)) {
        delete merged.xp
      }
      return merged
    })
    onEntries(next)
  }

  const needed = path === 'single' ? 1 : path === 'dual' ? 2 : Math.max(2, entries.length)
  const rows = Array.from({ length: needed }, (_, i) => entries[i] ?? { class: '', level: 1 })

  return (
    <div className="flex flex-col gap-3">
      <Select label="Advancement" value={path} onChange={e => {
        const next = e.target.value as ClassPath
        onPath(next)
        if (next === 'single') onEntries([entries[0] ?? { class: '', level: 1 }])
        if (next === 'dual') onEntries([
          entries[0] ?? { class: 'Fighter', level: 6 },
          entries[1] ?? { class: 'Mage', level: 1 },
        ])
        if (next === 'multi') onEntries([
          entries[0] ?? { class: 'Fighter', level: 1 },
          entries[1] ?? { class: 'Mage', level: 1 },
        ])
      }}>
        <option value="single">Single-class</option>
        <option value="multi">Multi-class</option>
        <option value="dual">Dual-class</option>
      </Select>

      {path === 'multi' && (
        <p className="text-[10px] text-[var(--color-text-dim)]">
          Multi-class characters advance each class together. THAC0 and saves use the best figure among the classes.
        </p>
      )}
      {path === 'dual' && (
        <p className="text-[10px] text-[var(--color-text-dim)]">
          House dual-class: begin a new class at 6th in the original; switch back when the new class is 5th. Not human-only; not PHB “must exceed original.” Original class first, new class second.
        </p>
      )}
      {path === 'dual' && rows[0] && !canBeginNewClass(Number(rows[0].level) || 0) && (
        <p className="text-[10px] text-[var(--color-danger)]">
          Original class must be at least 6th to begin a new class.
        </p>
      )}
      {path === 'dual' && rows[0] && rows[1] && canBeginNewClass(Number(rows[0].level) || 0) && (
        <p className="text-[10px] text-[var(--color-text-dim)]">
          {canResumeOriginalClass(Number(rows[1].level) || 0)
            ? 'New class is 5th or higher — resuming the original class is allowed.'
            : 'Until the new class is 5th, it is the active class (original abilities stay on the sheet).'}
        </p>
      )}

      <div className={`grid gap-3 ${path === 'single' ? 'grid-cols-2' : 'grid-cols-3'}`}>
        {rows.map((entry, i) => (
          <React.Fragment key={i}>
            <Select
              label={path === 'dual' ? (i === 0 ? 'Original class' : 'Current class') : path === 'multi' ? `Class ${i + 1}` : 'Class'}
              value={entry.class}
              onChange={e => setEntry(i, { class: e.target.value })}
            >
              <option value="">Select…</option>
              {CLASSES.map(c => <option key={c} value={c}>{c}</option>)}
            </Select>
            <Input
              label={path === 'dual' ? (i === 0 ? 'Original level' : 'Current level') : 'Level'}
              type="number"
              min={1}
              max={20}
              value={entry.level}
              onChange={e => setEntry(i, { level: parseInt(e.target.value) || 1 })}
            />
            {path !== 'single' && (
              <Input
                label={path === 'dual' ? (i === 0 ? 'Original XP' : 'Current XP') : 'XP'}
                type="number"
                min={0}
                value={entry.xp ?? ''}
                onChange={e => {
                  const raw = e.target.value
                  if (raw === '') {
                    setEntry(i, { xp: undefined })
                    return
                  }
                  setEntry(i, { xp: Math.max(0, parseInt(raw) || 0) })
                }}
                placeholder="—"
              />
            )}
          </React.Fragment>
        ))}
      </div>

      {path === 'multi' && rows.length < 3 && (
        <button
          type="button"
          className="text-[10px] uppercase tracking-widest text-[var(--color-rune)] self-start"
          onClick={() => onEntries([...rows, { class: 'Thief', level: rows[0]?.level ?? 1 }])}
        >
          + Third class
        </button>
      )}
    </div>
  )
}
