import React, { useState } from 'react'
import { router } from '@inertiajs/react'
import { CharacterSpell, InventoryItem } from '@/types'
import { Button } from '@/Components/Button'
import { Input, Select, Textarea } from '@/Components/Input'
import { MemorizedControl } from '@/Components/MemorizedControl'
import { SpellMaterialHint } from '@/Components/SpellMaterialHint'
import { memorizedCopyTotal, remainingCopyTotal, remainingMemorizedOf, slotCapacityAtLevel, timesMemorizedOf } from '@/lib/adnd2e'

// ── Spell school colours ──────────────────────────────────────────────
const SCHOOL_COLORS: Record<string, string> = {
  abjuration:    '#4a90d9',
  conjuration:   '#a259e6',
  divination:    '#e6c459',
  enchantment:   '#e659a2',
  evocation:     '#e67359',
  illusion:      '#59b8e6',
  necromancy:    '#59e6a2',
  transmutation: '#9be659',
}

const SCHOOLS = Object.keys(SCHOOL_COLORS)

const SPELL_LEVEL_LABELS: Record<number, string> = {
  1: '1st Level',
  2: '2nd Level',
  3: '3rd Level',
  4: '4th Level',
  5: '5th Level',
  6: '6th Level',
  7: '7th Level',
  8: '8th Level',
  9: '9th Level',
}

// ── Suggested spells by class ─────────────────────────────────────────
// Names and school tags only — no copyrighted rulebook text.
const CLASS_SPELL_SUGGESTIONS: Record<string, Array<{ name: string; level: number; school: string; casting_time: string; range: string; components: string; duration: string }>> = {
  Mage: [
    { name: 'Armor',              level: 1, school: 'abjuration',    casting_time: '1 round', range: 'Touch', components: 'V, S, M', duration: 'Special' },
    { name: 'Burning Hands',      level: 1, school: 'invocation',    casting_time: '1', range: '0', components: 'V, S', duration: 'Instantaneous' },
    { name: 'Charm Person',       level: 1, school: 'enchantment',   casting_time: '1', range: '120 yds', components: 'V, S', duration: 'Special' },
    { name: 'Detect Magic',       level: 1, school: 'divination',    casting_time: '1', range: '0', components: 'V, S', duration: '2 rds/level' },
    { name: 'Magic Missile',      level: 1, school: 'invocation',    casting_time: '1', range: '60 yds + 10 yds/level', components: 'V, S', duration: 'Instantaneous' },
    { name: 'Sleep',              level: 1, school: 'enchantment',   casting_time: '1', range: '30 yds', components: 'V, S, M', duration: '5 rds/level' },
    { name: 'Invisibility',       level: 2, school: 'illusion',      casting_time: '2', range: 'Touch', components: 'V, S, M', duration: 'Special' },
    { name: 'Web',                level: 2, school: 'invocation',    casting_time: '2', range: '5 yds/level', components: 'V, S, M', duration: '2 turns/level' },
    { name: 'Fireball',           level: 3, school: 'invocation',    casting_time: '3', range: '10 yds + 10 yds/level', components: 'V, S, M', duration: 'Instantaneous' },
    { name: 'Lightning Bolt',     level: 3, school: 'invocation',    casting_time: '3', range: '40 yds + 10 yds/level', components: 'V, S, M', duration: 'Instantaneous' },
  ],
  Bard: [
    { name: 'Charm Person',       level: 1, school: 'enchantment',   casting_time: '1', range: '120 yds', components: 'V, S', duration: 'Special' },
    { name: 'Friends',            level: 1, school: 'enchantment',   casting_time: '1', range: '0', components: 'V, S, M', duration: '1d4 rds + 1 rd/level' },
    { name: 'Identify',           level: 1, school: 'divination',    casting_time: 'Special', range: '0', components: 'V, S, M', duration: '1 rd/level' },
  ],
  Cleric: [
    { name: 'Bless',              level: 1, school: 'conjuration',   casting_time: '1 rd', range: '60 yds', components: 'V, S, M', duration: '6 rds' },
    { name: 'Cure Light Wounds',  level: 1, school: 'necromancy',    casting_time: '5', range: 'Touch', components: 'V, S', duration: 'Permanent' },
    { name: 'Detect Evil',        level: 1, school: 'divination',    casting_time: '1 rd', range: '0', components: 'V, S, M', duration: '1 turn + 5 rds/level' },
    { name: 'Hold Person',        level: 2, school: 'enchantment',   casting_time: '5', range: '120 yds', components: 'V, S, F', duration: '2 rds/level' },
  ],
  Druid: [
    { name: 'Entangle',           level: 1, school: 'alteration',    casting_time: '4', range: '80 yds', components: 'V, S, G', duration: '1 turn' },
    { name: 'Faerie Fire',        level: 1, school: 'alteration',    casting_time: '4', range: '80 yds', components: 'V', duration: '4 rds/level' },
    { name: 'Call Lightning',     level: 3, school: 'alteration',    casting_time: '1 turn', range: '360 yds', components: 'V, S', duration: '1 turn/level' },
  ],
  Fighter: [],
  Paladin: [
    { name: 'Cure Light Wounds',  level: 1, school: 'necromancy',    casting_time: '5', range: 'Touch', components: 'V, S', duration: 'Permanent' },
  ],
  Ranger: [
    { name: 'Animal Friendship',  level: 1, school: 'enchantment',   casting_time: '1 hr', range: 'Touch', components: 'V, S, M', duration: 'Permanent' },
  ],
  Thief: [],
  Other: [],
}

// ── Blank spell form ──────────────────────────────────────────────────
const BLANK_SPELL = {
  name: '',
  level: 1,
  school: '',
  casting_time: '1',
  range: '',
  components: '',
  duration: '',
  description: '',
  times_memorized: 0,
}

type SpellForm = typeof BLANK_SPELL

// ── Props ─────────────────────────────────────────────────────────────
interface Props {
  characterId: number
  characterClass: string
  spells: CharacterSpell[]
  memorization?: Record<string, number> | null
  inventoryItems?: InventoryItem[]
}

// ── Main component ────────────────────────────────────────────────────
export function SpellsTab({ characterId, characterClass, spells, memorization = null, inventoryItems = [] }: Props) {
  const [modalOpen, setModalOpen] = useState(false)
  const [editingSpell, setEditingSpell] = useState<CharacterSpell | null>(null)
  const [form, setForm] = useState<SpellForm>(BLANK_SPELL)
  const [submitting, setSubmitting] = useState(false)
  const [expandedSpell, setExpandedSpell] = useState<number | null>(null)
  const [showSuggestions, setShowSuggestions] = useState(false)
  const [filter, setFilter] = useState<'all' | 'memorized'>('all')

  const suggestions = CLASS_SPELL_SUGGESTIONS[characterClass] ?? CLASS_SPELL_SUGGESTIONS[characterClass === 'Wizard' ? 'Mage' : 'Other'] ?? CLASS_SPELL_SUGGESTIONS['Other']

  const openAdd = () => {
    setEditingSpell(null)
    setForm(BLANK_SPELL)
    setShowSuggestions(suggestions.length > 0)
    setModalOpen(true)
  }

  const openEdit = (spell: CharacterSpell) => {
    setEditingSpell(spell)
    setForm({
      name: spell.name,
      level: spell.level,
      school: spell.school ?? '',
      casting_time: spell.casting_time ?? '1',
      range: spell.range ?? '',
      components: spell.components ?? '',
      duration: spell.duration ?? '',
      description: spell.description ?? '',
      times_memorized: timesMemorizedOf(spell),
    })
    setShowSuggestions(false)
    setModalOpen(true)
  }

  const applySuggestion = (s: typeof suggestions[0]) => {
    setForm({
      name: s.name,
      level: s.level,
      school: s.school,
      casting_time: s.casting_time,
      range: s.range,
      components: s.components,
      duration: s.duration,
      description: '',
      times_memorized: 0,
    })
    setShowSuggestions(false)
  }

  const closeModal = () => {
    setModalOpen(false)
    setEditingSpell(null)
    setShowSuggestions(false)
  }

  const submitSpell = () => {
    setSubmitting(true)
    const payload = {
      ...form,
      times_memorized: form.times_memorized,
    }
    if (editingSpell) {
      router.patch(`/characters/${characterId}/spells/${editingSpell.id}`, payload, {
        preserveScroll: true,
        onFinish: () => { setSubmitting(false); closeModal() },
      })
    } else {
      router.post(`/characters/${characterId}/spells`, payload, {
        preserveScroll: true,
        onFinish: () => { setSubmitting(false); closeModal() },
      })
    }
  }

  const deleteSpell = (spell: CharacterSpell) => {
    if (!confirm(`Remove ${spell.name} from this character's spell list?`)) return
    router.delete(`/characters/${characterId}/spells/${spell.id}`, { preserveScroll: true })
  }

  const setMemorized = (spell: CharacterSpell, times: number) => {
    router.patch(`/characters/${characterId}/spells/${spell.id}/prepare`, {
      times_memorized: times,
    }, { preserveScroll: true })
  }

  // Group by level
  const visible = filter === 'memorized'
    ? spells.filter(s => timesMemorizedOf(s) > 0)
    : spells
  const byLevel = visible.reduce<Record<number, CharacterSpell[]>>((acc, s) => {
    ;(acc[s.level] ??= []).push(s)
    return acc
  }, {})
  const levels = Object.keys(byLevel).map(Number).sort((a, b) => a - b)
  const memorizedCopies = memorizedCopyTotal(spells)
  const remainingCopies = remainingCopyTotal(spells)

  return (
    <div className="flex flex-col gap-4">
      {/* Header */}
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]" data-testid="spell-copy-summary">
          {spells.length} known · {memorizedCopies} memorized copies
          {memorizedCopies > 0 && remainingCopies < memorizedCopies ? ` · ${remainingCopies} left` : ''}
        </p>
        <div className="flex items-center gap-2">
          <div className="flex rounded border overflow-hidden" style={{ borderColor: 'var(--color-border)' }} data-testid="spell-filter">
            {(['all', 'memorized'] as const).map(key => (
              <button
                key={key}
                type="button"
                data-testid={`spell-filter-${key}`}
                onClick={() => setFilter(key)}
                className="px-2 py-1 text-[10px] uppercase tracking-widest"
                style={{
                  background: filter === key ? 'var(--color-rune)' : 'transparent',
                  color: filter === key ? 'var(--color-text-white)' : 'var(--color-text-dim)',
                }}
              >
                {key === 'all' ? 'All known' : 'Memorized'}
              </button>
            ))}
          </div>
          <Button type="button" variant="ghost" size="sm" onClick={openAdd}>
            + Add Spell
          </Button>
        </div>
      </div>

      {/* Empty state */}
      {spells.length === 0 && (
        <div
          className="flex flex-col items-center gap-2 py-10 rounded border border-dashed text-center"
          style={{ borderColor: 'var(--color-border)' }}
        >
          <p className="text-sm text-[var(--color-text-dim)]">No spells added yet.</p>
          {suggestions.length > 0 && (
            <p className="text-xs text-[var(--color-text-dim)]">
              Click <span className="text-[var(--color-rune)]">+ Add Spell</span> to pick from {characterClass} suggestions or enter one manually.
            </p>
          )}
        </div>
      )}

      {spells.length > 0 && visible.length === 0 && (
        <p className="text-sm text-[var(--color-text-dim)] text-center py-6">
          No memorized spells. Known spells stay on the list under All known.
        </p>
      )}

      {/* Spell list grouped by level */}
      {levels.map(level => {
        const copiesAtLevel = spells
          .filter(s => s.level === level)
          .reduce((sum, s) => sum + timesMemorizedOf(s), 0)
        const slots = slotCapacityAtLevel(memorization, level)
        const overSlots = slots > 0 && copiesAtLevel > slots
        return (
        <div key={level} className="flex flex-col gap-1">
          <p
            className="text-[10px] uppercase tracking-widest font-heading mb-1"
            style={{ color: overSlots ? 'var(--color-danger)' : 'var(--color-text-dim)' }}
            data-testid={`spell-level-copies-${level}`}
          >
            {SPELL_LEVEL_LABELS[level] ?? `Level ${level}`}
            {slots > 0
              ? ` · ${copiesAtLevel} / ${slots} slots`
              : copiesAtLevel > 0
                ? ` · ${copiesAtLevel} ${copiesAtLevel === 1 ? 'copy' : 'copies'}`
                : ''}
          </p>
          {byLevel[level].sort((a, b) => a.name.localeCompare(b.name)).map(spell => (
            <SpellRow
              key={spell.id}
              spell={spell}
              inventoryItems={inventoryItems}
              expanded={expandedSpell === spell.id}
              onToggleExpand={() => setExpandedSpell(expandedSpell === spell.id ? null : spell.id)}
              onEdit={() => openEdit(spell)}
              onDelete={() => deleteSpell(spell)}
              onSetMemorized={times => setMemorized(spell, times)}
            />
          ))}
        </div>
        )
      })}

      {/* Add / Edit modal */}
      {modalOpen && (
        <SpellModal
          editing={editingSpell}
          form={form}
          setForm={setForm}
          submitting={submitting}
          suggestions={showSuggestions ? suggestions : []}
          onSuggestion={applySuggestion}
          onDismissSuggestions={() => setShowSuggestions(false)}
          onSubmit={submitSpell}
          onClose={closeModal}
        />
      )}
    </div>
  )
}

// ── Spell row ─────────────────────────────────────────────────────────
function SpellRow({
  spell, inventoryItems, expanded, onToggleExpand, onEdit, onDelete, onSetMemorized,
}: {
  spell: CharacterSpell
  inventoryItems: InventoryItem[]
  expanded: boolean
  onToggleExpand: () => void
  onEdit: () => void
  onDelete: () => void
  onSetMemorized: (times: number) => void
}) {
  const schoolColor = SCHOOL_COLORS[spell.school?.toLowerCase() ?? ''] ?? 'var(--color-text-dim)'
  const times = timesMemorizedOf(spell)
  const remaining = remainingMemorizedOf(spell)
  return (
    <div
      className="rounded border transition-colors"
      data-testid="spell-row"
      data-memorized={times > 0 ? 'true' : 'false'}
      style={{
        borderColor: expanded || times > 0 ? 'var(--color-rune-dim)' : 'var(--color-border)',
        background: times > 0 ? 'color-mix(in srgb, var(--color-rune) 8%, var(--color-deep))' : 'var(--color-deep)',
        opacity: times > 0 ? 1 : 0.78,
      }}
    >
      {/* Row header */}
      <div className="flex items-center gap-2 px-3 py-2 flex-wrap">
        <MemorizedControl
          timesMemorized={times}
          remaining={remaining}
          onChange={onSetMemorized}
        />

        {/* Name + badges */}
        <button
          type="button"
          className="flex-1 text-left text-sm text-[var(--color-text-bright)] hover:text-[var(--color-text-white)] truncate"
          onClick={onToggleExpand}
        >
          {spell.name}
        </button>

        {/* Tags */}
        <div className="flex items-center gap-1 shrink-0">
          {times > 0 && remaining === 0 && (
            <span className="text-[9px] uppercase tracking-widest" style={{ color: 'var(--color-text-dim)' }}>Cast</span>
          )}
          <SpellMaterialHint spell={spell} items={inventoryItems} compact />
          {spell.school && (
            <span
              className="hidden sm:inline px-1.5 py-0 text-[9px] rounded uppercase tracking-widest leading-4"
              style={{ color: schoolColor, borderColor: schoolColor + '50', border: '1px solid' }}
            >
              {spell.school.slice(0, 3)}
            </span>
          )}
        </div>

        {/* Actions */}
        <div className="flex items-center gap-1 shrink-0">
          <button
            type="button"
            onClick={onEdit}
            className="p-1 text-[var(--color-text-dim)] hover:text-[var(--color-rune)] transition-colors"
            title="Edit spell"
          >
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7" />
              <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z" />
            </svg>
          </button>
          <button
            type="button"
            onClick={onDelete}
            className="p-1 text-[var(--color-text-dim)] hover:text-[var(--color-danger)] transition-colors"
            title="Remove spell"
          >
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <polyline points="3 6 5 6 21 6" />
              <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6" />
              <path d="M10 11v6M14 11v6" />
            </svg>
          </button>
        </div>
      </div>

      {/* Expanded details */}
      {expanded && (
        <div
          className="px-3 pb-3 pt-0 flex flex-col gap-1.5 border-t text-xs"
          style={{ borderColor: 'var(--color-border)', color: 'var(--color-text-dim)' }}
        >
          <div className="grid grid-cols-2 gap-x-4 gap-y-1 mt-2">
            {spell.casting_time && <Detail label="Casting Time" value={spell.casting_time} />}
            {spell.range && <Detail label="Range" value={spell.range} />}
            {spell.components && <Detail label="Components" value={spell.components} />}
            {spell.duration && <Detail label="Duration" value={spell.duration} />}
          </div>
          {(spell.material_requirements?.length ?? 0) > 0 && (
            <p className="mt-1 text-[10px]" style={{ color: 'var(--color-text-dim)' }}>
              Linked: {spell.material_requirements!.map(req => (
                `${req.focus ? 'focus' : 'spend'} ${req.name}`
              )).join(' · ')}
            </p>
          )}
          {spell.description && (
            <p className="mt-1 leading-relaxed" style={{ color: 'var(--color-text-base)' }}>
              {spell.description}
            </p>
          )}
        </div>
      )}
    </div>
  )
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <span className="uppercase tracking-widest text-[9px]">{label}: </span>
      <span style={{ color: 'var(--color-text-base)' }}>{value}</span>
    </div>
  )
}

// ── Spell modal ───────────────────────────────────────────────────────
function SpellModal({
  editing, form, setForm, submitting,
  suggestions, onSuggestion, onDismissSuggestions,
  onSubmit, onClose,
}: {
  editing: CharacterSpell | null
  form: SpellForm
  setForm: React.Dispatch<React.SetStateAction<SpellForm>>
  submitting: boolean
  suggestions: typeof CLASS_SPELL_SUGGESTIONS[string]
  onSuggestion: (s: typeof CLASS_SPELL_SUGGESTIONS[string][0]) => void
  onDismissSuggestions: () => void
  onSubmit: () => void
  onClose: () => void
}) {
  const set = <K extends keyof SpellForm>(k: K, v: SpellForm[K]) => setForm(f => ({ ...f, [k]: v }))

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      style={{ background: 'rgba(0,0,0,0.7)' }}
      onClick={e => { if (e.target === e.currentTarget) onClose() }}
    >
      <div
        className="w-full max-w-lg rounded-lg flex flex-col max-h-[90vh] overflow-hidden"
        style={{ background: 'var(--color-bg)', border: '1px solid var(--color-border)' }}
      >
        {/* Modal header */}
        <div
          className="flex items-center justify-between px-5 py-4 border-b shrink-0"
          style={{ borderColor: 'var(--color-border)' }}
        >
          <h3 className="font-heading text-sm uppercase tracking-widest text-[var(--color-text-white)]">
            {editing ? 'Edit Spell' : 'Add Spell'}
          </h3>
          <button
            type="button"
            onClick={onClose}
            className="text-[var(--color-text-dim)] hover:text-[var(--color-text-white)] transition-colors"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M18 6L6 18M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div className="overflow-y-auto flex-1 px-5 py-4 flex flex-col gap-4">

          {/* Suggestions picker */}
          {suggestions.length > 0 && (
            <div className="flex flex-col gap-2">
              <div className="flex items-center justify-between">
                <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]">
                  Quick Add — {Object.entries(CLASS_SPELL_SUGGESTIONS).find(([, v]) => v === suggestions)?.[0] ?? ''} Spells
                </p>
                <button
                  type="button"
                  onClick={onDismissSuggestions}
                  className="text-[10px] text-[var(--color-text-dim)] hover:text-[var(--color-rune)] uppercase tracking-widest"
                >
                  Enter manually →
                </button>
              </div>
              <div className="flex flex-col gap-1 max-h-44 overflow-y-auto">
                {suggestions.map(s => (
                  <button
                    key={s.name}
                    type="button"
                    onClick={() => onSuggestion(s)}
                    className="flex items-center gap-3 px-3 py-2 rounded text-left hover:bg-[var(--color-rune)]/10 transition-colors"
                    style={{ border: '1px solid var(--color-border)' }}
                  >
                    <span
                      className="text-[9px] uppercase tracking-widest w-12 shrink-0"
                      style={{ color: SCHOOL_COLORS[s.school] ?? 'var(--color-text-dim)' }}
                    >
                      {`Lvl ${s.level}`}
                    </span>
                    <span className="text-sm text-[var(--color-text-bright)] flex-1">{s.name}</span>
                  </button>
                ))}
              </div>
              <div
                className="border-t pt-2"
                style={{ borderColor: 'var(--color-border)' }}
              >
                <button
                  type="button"
                  onClick={onDismissSuggestions}
                  className="text-xs text-[var(--color-text-dim)] hover:text-[var(--color-rune)] transition-colors"
                >
                  + Enter a custom spell instead
                </button>
              </div>
            </div>
          )}

          {/* Manual form — shown when no suggestions or after dismissal */}
          {suggestions.length === 0 && (
            <div className="flex flex-col gap-4">
              <ManualSpellForm form={form} set={set} />
            </div>
          )}

          {/* Pre-filled form after suggestion applied */}
          {suggestions.length === 0 && form.name !== '' && null /* already shown above */}

          {/* After a suggestion is picked (suggestions dismissed, form populated) */}
          {!suggestions.length && (
            <div className="flex flex-col gap-3">
              {/* intentionally empty — ManualSpellForm above handles it */}
            </div>
          )}
        </div>

        {/* Footer */}
        <div
          className="flex items-center justify-between px-5 py-4 border-t shrink-0"
          style={{ borderColor: 'var(--color-border)' }}
        >
          <Button type="button" variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button
            type="button"
            variant="rune"
            disabled={submitting || !form.name.trim() || suggestions.length > 0}
            onClick={onSubmit}
          >
            {submitting ? 'Saving…' : editing ? 'Save Changes' : 'Add Spell'}
          </Button>
        </div>
      </div>
    </div>
  )
}

function ManualSpellForm({
  form, set,
}: {
  form: SpellForm
  set: <K extends keyof SpellForm>(k: K, v: SpellForm[K]) => void
}) {
  return (
    <>
      <div className="grid grid-cols-3 gap-3">
        <div className="col-span-2">
          <Input
            label="Spell Name"
            value={form.name}
            onChange={e => set('name', e.target.value)}
            placeholder="Magic Missile, Cure Light Wounds…"
            autoFocus
          />
        </div>
        <Select
          label="Level"
          value={String(form.level)}
          onChange={e => set('level', parseInt(e.target.value))}
        >
          {[1,2,3,4,5,6,7,8,9].map(l => (
            <option key={l} value={l}>{l}{l===1?'st':l===2?'nd':l===3?'rd':'th'} Level</option>
          ))}
        </Select>
      </div>

      <div className="grid grid-cols-2 gap-3">
        <Select
          label="School"
          value={form.school}
          onChange={e => set('school', e.target.value)}
        >
          <option value="">Unknown</option>
          {SCHOOLS.map(s => (
            <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>
          ))}
        </Select>
        <Input
          label="Casting Time"
          value={form.casting_time}
          onChange={e => set('casting_time', e.target.value)}
          placeholder="1 (segments) or 1 rd"
        />
      </div>

      <div className="grid grid-cols-2 gap-3">
        <Input
          label="Range"
          value={form.range}
          onChange={e => set('range', e.target.value)}
          placeholder="60 ft, Touch, Self…"
        />
        <Input
          label="Duration"
          value={form.duration}
          onChange={e => set('duration', e.target.value)}
          placeholder="Instantaneous, 1 minute…"
        />
      </div>

      <Input
        label="Components"
        value={form.components}
        onChange={e => set('components', e.target.value)}
        placeholder="V, S, M (a pinch of sulfur)"
      />
      <p className="text-[10px] -mt-2" style={{ color: 'var(--color-text-dim)' }}>
        Named items in parentheses or a Material: note are spent from inventory on cast. Label foci so they are not consumed. Bare V, S, M does not invent materials.
      </p>

      <Textarea
        label="Description (optional)"
        value={form.description}
        onChange={e => set('description', e.target.value)}
        rows={3}
        placeholder="Material: a pinch of sulfur. Optional notes — not PHB text."
      />

      <div className="flex flex-wrap gap-3">
        <MemorizedControl
          timesMemorized={form.times_memorized}
          onChange={times => set('times_memorized', times)}
        />
      </div>
    </>
  )
}
