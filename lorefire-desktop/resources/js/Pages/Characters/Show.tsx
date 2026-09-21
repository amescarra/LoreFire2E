import React, { useState, useRef, useEffect } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import { Card, CardHeader, StatBlock } from '@/Components/Card'
import { Badge } from '@/Components/Badge'
import { Button } from '@/Components/Button'
import { HpBar } from '@/Components/HpBar'
import { RuneDivider } from '@/Components/RuneDivider'
import { Input } from '@/Components/Input'
import { Campaign, Character, InventoryItem, InventorySnapshot } from '@/types'
import { ConditionManager } from '@/Components/ConditionManager'
import { SpellsTab } from '@/Components/SpellsTab'
import { AbilityScoreBlock } from '@/Components/AbilityScoreBlock'
import {
  ABILITY_ORDER, SAVE_CATEGORIES, anyCaster, backfillClassLevelsXp, classAbbreviation, formatClassLevelsLine, formatXpAmount, hasPsionicist, inventoryQuantityLabel, kitFieldKind, normalizeClassLevels, vitalityState,
} from '@/lib/adnd2e'

interface Props {
  campaign: Campaign | null
  character: Character
  imageGenProvider?: string | null
}

type Tab = 'stats' | 'spells' | 'inventory' | 'features' | 'notes'

export default function Show({ campaign, character, imageGenProvider }: Props) {
  const standalone = campaign === null
  const [tab, setTab] = useState<Tab>('stats')
  const [restConfirm, setRestConfirm] = useState(false)

  // Portrait generation polling
  const [livePortraitPath, setLivePortraitPath] = useState<string | null>(
    character.portrait_path ? `/storage-file/${character.portrait_path}?t=${new Date(character.updated_at).getTime()}` : null
  )
  const [genStatus, setGenStatus] = useState<'idle' | 'generating' | 'done' | 'failed'>(
    character.portrait_generation_status ?? 'idle'
  )
  const genPollRef = useRef<ReturnType<typeof setInterval> | null>(null)

  useEffect(() => {
    if (genStatus !== 'generating') return
    genPollRef.current = setInterval(async () => {
      const res = await fetch(`/characters/${character.id}/portrait-status`)
      if (!res.ok) return
      const json = await res.json()
      setGenStatus(json.status)
      if (json.status === 'done' && json.portrait_path) {
        setLivePortraitPath(`/storage-file/${json.portrait_path}?t=${Date.now()}`)
        clearInterval(genPollRef.current!)
      } else if (json.status === 'failed') {
        clearInterval(genPollRef.current!)
      }
    }, 3000)
    return () => { if (genPollRef.current) clearInterval(genPollRef.current) }
  }, [genStatus])

  const handleCancelGeneration = async () => {
    await fetch(`/characters/${character.id}/cancel-portrait`, { method: 'POST', headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '' } })
    setGenStatus('failed')
    if (genPollRef.current) clearInterval(genPollRef.current)
  }

  const deleteHref = standalone
    ? `/characters/${character.id}`
    : `/campaigns/${campaign!.id}/characters/${character.id}`

  const editHref = standalone
    ? `/characters/${character.id}/edit`
    : `/campaigns/${campaign!.id}/characters/${character.id}/edit`

  const restBaseUrl = standalone
    ? `/characters/${character.id}/rest`
    : `/campaigns/${campaign!.id}/characters/${character.id}/rest`

  const memorizationUrl = standalone
    ? `/characters/${character.id}/memorization`
    : `/campaigns/${campaign!.id}/characters/${character.id}/memorization`

  const classFeaturesUrl = `/characters/${character.id}/class-features`

  const doRest = () => {
    router.post(`${restBaseUrl}/overnight`, {}, { preserveScroll: true })
    setRestConfirm(false)
  }

  const toggleSlot = (level: number, action: 'use' | 'recover') => {
    router.patch(memorizationUrl, { level, action }, { preserveScroll: true })
  }

  const kitKind = kitFieldKind(character.subclass)
  const vitality = vitalityState(character.current_hp)
  const classPath = character.class_path ?? 'single'
  const classEntries = backfillClassLevelsXp(
    normalizeClassLevels(character.class_levels, character.class, character.level, classPath),
    classPath,
    character.experience_points ?? 0,
  )
  const classLevelsLine = formatClassLevelsLine(classEntries, classPath)
  const hasMemorization = anyCaster(classEntries)
    || (character.memorization && Object.keys(character.memorization).length > 0)

  return (
    <AppLayout breadcrumbs={
      standalone
        ? [{ label: 'Characters', href: '/characters' }, { label: character.name }]
        : [
            { label: 'Campaigns', href: '/campaigns' },
            { label: campaign!.name, href: `/campaigns/${campaign!.id}` },
            { label: character.name },
          ]
    }>
      <Head title={standalone ? character.name : `${character.name} — ${campaign!.name}`} />

      <div className="max-w-5xl mx-auto flex flex-col gap-5">

        {/* ── Character header ─────────────────────────────────────── */}
        <div className="runic-card p-5 flex items-start gap-5">
          {/* Portrait */}
          <div className="shrink-0 flex flex-col items-center gap-1.5">
            <div
              className="w-24 h-32 rounded overflow-hidden flex items-center justify-center border border-[var(--color-border)] relative"
              style={{ background: 'var(--color-deep)' }}
            >
              {genStatus === 'generating' && (
                <div className="absolute inset-0 flex items-center justify-center bg-[var(--color-bg)]/70 z-10">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--color-rune)" strokeWidth="2" className="animate-spin">
                    <path d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" opacity="0.2" />
                    <path d="M21 12a9 9 0 00-9-9" />
                  </svg>
                </div>
              )}
              {livePortraitPath ? (
                <img
                  src={livePortraitPath}
                  alt={character.name}
                  className="w-full h-full object-cover"
                />
              ) : (
                <span className="font-heading text-2xl text-[var(--color-rune)]">{character.name[0]}</span>
              )}
            </div>
            {genStatus === 'generating' && (
              <button
                type="button"
                onClick={handleCancelGeneration}
                className="px-2 py-0.5 text-[10px] font-heading tracking-widest uppercase border border-[var(--color-danger)] rounded text-[var(--color-danger)] hover:bg-[var(--color-danger)]/10 transition-colors"
              >
                Cancel
              </button>
            )}
          </div>

          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-3 flex-wrap">
              <h1 className="font-heading text-2xl text-[var(--color-text-white)] tracking-widest uppercase">{character.name}</h1>
              <Badge variant="rune" className="normal-case tracking-wider">{classLevelsLine || `Level ${character.level}`}</Badge>
              {character.class_path && character.class_path !== 'single' && (
                <Badge variant="muted">{character.class_path === 'dual' ? 'Dual-class' : 'Multi-class'}</Badge>
              )}
            </div>
            <p className="text-sm text-[var(--color-text-dim)] mt-0.5">
              {character.race}{character.subrace ? ` (${character.subrace})` : ''} · {character.class}{kitKind && character.subclass ? ` · ${kitKind}: ${character.subclass}` : ''}
              {character.background ? ` · ${character.background}` : ''}
            </p>
            <ul className="mt-1.5 text-xs text-[var(--color-text-dim)] flex flex-col gap-0.5">
              {classEntries.map((entry, i) => (
                <li key={`${entry.class}-${i}`} className="font-mono">
                  {classAbbreviation(entry.class)} {entry.level}
                  <span className="opacity-70">
                    {' · '}
                    {entry.xp !== undefined && entry.xp !== null
                      ? `${formatXpAmount(entry.xp)} XP`
                      : '— XP'}
                  </span>
                </li>
              ))}
            </ul>
            <div className="mt-2">
              <ConditionManager characterId={character.id} conditions={character.conditions ?? []} />
            </div>
            {character.player_name && (
              <p className="text-xs text-[var(--color-text-dim)] mt-0.5 opacity-60">Played by {character.player_name}</p>
            )}

            <HpBar current={character.current_hp} max={character.max_hp} className="mt-3 max-w-xs" />
            {vitality !== 'ok' && (
              <p className="text-xs uppercase tracking-widest mt-1 text-[var(--color-danger)]">
                Slain
              </p>
            )}
          </div>

          {/* Combat stats */}
          <div className="flex gap-3 shrink-0">
            {(() => {
              const shieldEquipped = character.inventory_items?.some(
                i => i.category === 'Shield' && i.equipped
              )
              return shieldEquipped
                ? <StatBlock label="AC" value={character.armor_class} sub="shield" />
                : <StatBlock label="AC" value={character.armor_class} />
            })()}
            <StatBlock label="THAC0" value={character.thac0} highlight />
            <StatBlock label="MV" value={character.speed} />
            <StatBlock label="HD" value={character.hit_die ?? '—'} />
            {hasPsionicist(classEntries) && (
              <StatBlock
                label="PSP"
                value={`${character.psp_current ?? '—'}/${character.psp_max ?? '—'}`}
              />
            )}
          </div>

          <div className="shrink-0 flex gap-2 flex-wrap justify-end">
            <Button variant="ghost" size="sm" as="a" href={editHref}>
              Edit
            </Button>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                if (confirm(`Delete "${character.name}"? This cannot be undone.`)) {
                  router.delete(deleteHref)
                }
              }}
              className="text-[var(--color-danger)] hover:border-[var(--color-danger)]"
            >
              Delete
            </Button>
          </div>
        </div>

        {/* ── Rest buttons ─────────────────────────────────────────── */}
        <div className="flex items-center gap-3">
          {!restConfirm ? (
            <Button variant="ghost" size="sm" onClick={() => setRestConfirm(true)}>
              Overnight Rest
            </Button>
          ) : (
            <div className="flex items-center gap-3">
              <span className="text-xs text-[var(--color-text-dim)]">
                Recover 1 HP and rememorize spells?
              </span>
              <Button variant="rune" size="sm" onClick={doRest}>Confirm</Button>
              <Button variant="ghost" size="sm" onClick={() => setRestConfirm(false)}>Cancel</Button>
            </div>
          )}
          <span className="text-[10px] text-[var(--color-text-dim)] opacity-50 ml-auto">
            HP {character.current_hp}/{character.max_hp}
          </span>
        </div>

        {/* ── Ability scores ────────────────────────────────────────── */}
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2">
          {ABILITY_ORDER.map(({ label, key }) => (
            <AbilityScoreBlock
              key={key}
              ability={key}
              label={label}
              score={character[key] as number}
              exceptional={character.exceptional_strength}
              characterClass={character.class}
              variant="sheet"
            />
          ))}
        </div>

        {/* ── Tabs ─────────────────────────────────────────────────── */}
        <div className="flex gap-1 border-b border-[var(--color-border)]">
          {(['stats', 'spells', 'inventory', 'features', 'notes'] as Tab[]).map(t => (
            <button
              key={t}
              onClick={() => setTab(t)}
              className={`
                px-4 py-2 text-xs uppercase tracking-widest font-medium transition-colors border-b-2 -mb-px
                ${tab === t
                  ? 'border-[var(--color-rune)] text-[var(--color-rune-bright)]'
                  : 'border-transparent text-[var(--color-text-dim)] hover:text-[var(--color-text-base)]'
                }
              `}
            >
              {t}
            </button>
          ))}
        </div>

        {/* ── Tab content ──────────────────────────────────────────── */}
        {tab === 'stats' && (
          <div className="grid grid-cols-2 gap-4">
            <Card>
              <CardHeader title="Saving Throws" subtitle="Roll this number or higher" />
              <div className="flex flex-col gap-1">
                {SAVE_CATEGORIES.map(cat => {
                  const target = character.saving_throws?.[cat.key] ?? 20
                  return (
                    <SkillRow key={cat.key} label={cat.label} value={String(target)} proficient />
                  )
                })}
              </div>
            </Card>

            <Card>
              <CardHeader title="Proficiencies" />
              <div className="flex flex-col gap-3">
                <div>
                  <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)] mb-1">Weapons</p>
                  <p className="text-xs text-[var(--color-text-base)]">
                    {(character.weapon_proficiencies ?? []).join(', ') || 'None recorded'}
                  </p>
                </div>
                <div>
                  <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)] mb-1">Non-weapon</p>
                  <p className="text-xs text-[var(--color-text-base)]">
                    {(character.nonweapon_proficiencies ?? []).join(', ') || 'None recorded'}
                  </p>
                </div>
                {hasPsionicist(classEntries) && (
                  <div data-testid="psionicist-sheet">
                    <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)] mb-1">Known powers</p>
                    <p className="text-xs text-[var(--color-text-base)]">
                      {(character.psionic_powers ?? []).filter(Boolean).join(', ') || 'None recorded'}
                    </p>
                  </div>
                )}
                {character.priest_spheres && (
                  <div>
                    <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)] mb-1">Spheres</p>
                    <p className="text-xs text-[var(--color-text-base)]">
                      Major: {(character.priest_spheres.major ?? []).join(', ') || '—'}
                      <br />
                      Minor: {(character.priest_spheres.minor ?? []).join(', ') || '—'}
                    </p>
                  </div>
                )}
              </div>
            </Card>

            {/* Currency */}
            <Card>
              <CardHeader title="Currency" />
              <div className="grid grid-cols-5 gap-2">
                {[
                  { label: 'CP', value: character.copper, color: '#b87333' },
                  { label: 'SP', value: character.silver, color: '#a8a9ad' },
                  { label: 'EP', value: character.electrum, color: '#b0e0e6' },
                  { label: 'GP', value: character.gold, color: '#ffd700' },
                  { label: 'PP', value: character.platinum, color: '#e5e4e2' },
                ].map(c => (
                  <div key={c.label} className="flex flex-col items-center p-2 rounded border border-[var(--color-border)] bg-[var(--color-deep)]">
                    <span className="text-base font-heading leading-none" style={{ color: c.color }}>{c.value}</span>
                    <span className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)] mt-1">{c.label}</span>
                  </div>
                ))}
              </div>
            </Card>

            {vitality !== 'ok' && (
              <Card>
                <CardHeader title="Vitality" />
                <p className="text-sm text-[var(--color-danger)]">
                  At 0 hit points this character is slain.
                </p>
              </Card>
            )}
          </div>
        )}

        {/* ── Class Features display ─────────────────────────────────── */}
        {tab === 'stats' && (character.class_features && Object.keys(character.class_features).length > 0 || character.class === 'Paladin') && (
          <Card>
            <CardHeader title={`${character.class} Features`} />
            <ClassFeaturesDisplay
              cf={character.class_features ?? {}}
              updateUrl={classFeaturesUrl}
              characterClass={character.class}
              level={character.level}
            />
          </Card>
        )}

        {tab === 'spells' && (
          <div className="flex flex-col gap-3">
            {/* ── Memorization capacity tracker ───────────────────── */}
            {hasMemorization && character.memorization && Object.keys(character.memorization).length > 0 && (
              <Card>
                <CardHeader
                  title="Memorized (capacity)"
                  subtitle="Click a pip to cast · right-click to restore"
                />
                <div className="flex flex-col gap-3">
                  {Object.entries(character.memorization)
                    .filter(([, max]) => (max as number) > 0)
                    .sort(([a], [b]) => Number(a) - Number(b))
                    .map(([level, maxRaw]) => {
                      const max = maxRaw as number
                      const used = (character.memorization_used?.[level] ?? 0) as number
                      const remaining = max - used
                      return (
                        <div key={level} className="flex items-center gap-3">
                          <span className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)] w-16 shrink-0">
                            {`Level ${level}`}
                          </span>
                          <div className="flex gap-1.5 flex-wrap flex-1">
                            {Array.from({ length: max }).map((_, i) => {
                              const isUsed = i >= remaining
                              return (
                                <button
                                  key={i}
                                  title={isUsed ? 'Right-click to recover' : 'Click to use slot'}
                                  onClick={() => !isUsed && toggleSlot(Number(level), 'use')}
                                  onContextMenu={e => { e.preventDefault(); isUsed && toggleSlot(Number(level), 'recover') }}
                                  className={`
                                    w-6 h-6 rounded-full border transition-all duration-150
                                    ${isUsed
                                      ? 'border-[var(--color-border)] bg-transparent cursor-context-menu opacity-40'
                                      : 'border-[var(--color-rune)] bg-[var(--color-rune)] cursor-pointer hover:brightness-125'
                                    }
                                  `}
                                />
                              )
                            })}
                          </div>
                          <span className="text-xs font-mono text-[var(--color-text-dim)] shrink-0">
                            {remaining}/{max}
                          </span>
                        </div>
                      )
                    })}
                </div>
                <p className="text-[10px] text-[var(--color-text-dim)] opacity-50 mt-2">
                  Left-click to cast · Right-click to restore · Overnight rest rememorizes everything
                </p>
              </Card>
            )}

            <SpellsTab
              characterId={character.id}
              characterClass={character.class}
              spells={character.spells ?? []}
              memorization={character.memorization}
              inventoryItems={character.inventory_items ?? []}
            />
          </div>
        )}

        {tab === 'inventory' && (
          <InventoryTab character={character} />
        )}

        {tab === 'features' && (
          <div className="flex flex-col gap-2">
            {!character.features || character.features.length === 0 ? (
              <p className="text-sm text-[var(--color-text-dim)] text-center py-8">No features recorded.</p>
            ) : (
              character.features.map(feat => (
                <div key={feat.id} className="runic-card p-3">
                  <div className="flex items-center gap-2 mb-1">
                    <span className="text-sm font-heading text-[var(--color-text-white)]">{feat.name}</span>
                    {feat.source && <Badge variant="muted">{feat.source}</Badge>}
                    {feat.has_uses && feat.max_uses && (
                      <Badge variant={feat.uses_remaining === 0 ? 'danger' : 'rune'}>
                        {feat.uses_remaining}/{feat.max_uses}
                      </Badge>
                    )}
                  </div>
                  {feat.description && (
                    <p className="text-xs text-[var(--color-text-dim)] leading-relaxed">{feat.description}</p>
                  )}
                </div>
              ))
            )}
          </div>
        )}

        {tab === 'notes' && (
          <div className="grid grid-cols-2 gap-4">
            {[
              { label: 'Mannerisms', value: character.mannerisms },
              { label: 'Motivations', value: character.motivations },
              { label: 'Ties', value: character.ties },
              { label: 'Weaknesses', value: character.weaknesses },
            ].map(({ label, value }) => (
              <Card key={label}>
                <CardHeader title={label} />
                <p className="text-sm text-[var(--color-text-base)] leading-relaxed">
                  {value ?? <span className="text-[var(--color-text-dim)] italic">Not set</span>}
                </p>
              </Card>
            ))}
            {character.backstory && (
              <Card className="col-span-2">
                <CardHeader title="Backstory" />
                <p className="text-sm text-[var(--color-text-base)] leading-relaxed whitespace-pre-wrap">{character.backstory}</p>
              </Card>
            )}
          </div>
        )}
      </div>
    </AppLayout>
  )
}

function SkillRow({ label, value, proficient, expert }: { label: string; value: string; proficient: boolean; expert?: boolean }) {
  return (
    <div className="flex items-center gap-2 py-0.5">
      <div className={`w-1.5 h-1.5 rounded-full shrink-0 ${expert ? 'bg-[var(--color-rune-bright)]' : proficient ? 'bg-[var(--color-rune-dim)]' : 'border border-[var(--color-border)]'}`} />
      <span className="text-xs text-[var(--color-text-base)] flex-1">{label}</span>
      <span className={`text-xs font-mono ${proficient ? 'text-[var(--color-rune-bright)]' : 'text-[var(--color-text-dim)]'}`}>{value}</span>
    </div>
  )
}

type CF = Record<string, unknown>

function csrfToken(): string {
  return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''
}

async function patchClassFeatures(url: string, updates: Record<string, unknown>) {
  await fetch(url, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
    body: JSON.stringify({ updates }),
  })
}

function ClassFeaturesDisplay({ cf, updateUrl, characterClass, level }: { cf: CF; updateUrl: string; characterClass?: string; level?: number }) {
  const [local, setLocal] = useState<CF>({ ...cf })

  // Lay on Hands — fall back to level*5 for Paladins who haven't saved keys yet
  const defaultLayMax = characterClass === 'Paladin' && level ? level * 2 : null
  const layMax = typeof local.lay_on_hands_max === 'number'
    ? local.lay_on_hands_max
    : defaultLayMax
  const layCurrent = typeof local.lay_on_hands_current === 'number'
    ? local.lay_on_hands_current
    : layMax  // default to full pool on first render
  const hasLayOnHands = layMax !== null && layCurrent !== null

  const adjustLay = async (delta: number) => {
    if (!hasLayOnHands) return
    const next = Math.max(0, Math.min(layMax!, layCurrent! + delta))
    setLocal(prev => ({ ...prev, lay_on_hands_current: next }))
    // If max was never persisted, write it now alongside current
    const updates: Record<string, number> = { lay_on_hands_current: next }
    if (typeof local.lay_on_hands_max !== 'number' && layMax !== null) {
      updates.lay_on_hands_max = layMax
      setLocal(prev => ({ ...prev, lay_on_hands_max: layMax }))
    }
    await patchClassFeatures(updateUrl, updates)
  }

  // Generic display for all other keys
  const skipKeys = new Set(['lay_on_hands_max', 'lay_on_hands_current'])
  const genericEntries = Object.entries(local).filter(
    ([k, v]) => !skipKeys.has(k) && v !== null && v !== '' && v !== false && v !== 0
  )

  const formatKey = (k: string) =>
    k.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())

  const formatVal = (v: unknown): string => {
    if (typeof v === 'boolean') return v ? 'Yes' : 'No'
    if (Array.isArray(v)) return v.join(', ')
    return String(v)
  }

  if (!hasLayOnHands && genericEntries.length === 0) return null

  return (
    <div className="flex flex-col gap-4">
      {/* Lay on Hands interactive tracker */}
      {hasLayOnHands && (
        <div className="flex flex-col gap-2">
          <div className="flex items-center justify-between">
            <span className="text-[10px] uppercase tracking-widest" style={{ color: 'var(--color-text-dim)' }}>
              Lay on Hands
            </span>
            <span className="text-xs font-mono" style={{ color: 'var(--color-rune-bright)' }}>
              {layCurrent} / {layMax} HP
            </span>
          </div>
          {/* Pool bar */}
          <div className="hp-bar-track w-full">
            <div
              className="hp-bar-fill"
              style={{
                width: `${layMax! > 0 ? Math.max(0, Math.min(100, (layCurrent! / layMax!) * 100)) : 0}%`,
                backgroundColor: 'var(--color-rune)',
                boxShadow: '0 0 6px var(--color-rune)',
              }}
            />
          </div>
          <div className="flex gap-2">
            {[1, 5, 10].map(amt => (
              <button
                key={`use-${amt}`}
                onClick={() => adjustLay(-amt)}
                disabled={layCurrent === 0}
                className="flex-1 text-[10px] px-2 py-1 rounded transition-colors disabled:opacity-30"
                style={{ background: 'rgba(220,38,38,0.15)', color: '#f87171', border: '1px solid rgba(220,38,38,0.3)' }}
              >
                −{amt}
              </button>
            ))}
            {[1, 5, 10].map(amt => (
              <button
                key={`heal-${amt}`}
                onClick={() => adjustLay(amt)}
                disabled={layCurrent === layMax}
                className="flex-1 text-[10px] px-2 py-1 rounded transition-colors disabled:opacity-30"
                style={{ background: 'rgba(34,197,94,0.12)', color: '#4ade80', border: '1px solid rgba(34,197,94,0.25)' }}
              >
                +{amt}
              </button>
            ))}
          </div>
        </div>
      )}

      {/* Generic key-value pairs for everything else */}
      {genericEntries.length > 0 && (
        <div className="flex flex-wrap gap-x-6 gap-y-2">
          {genericEntries.map(([key, val]) => (
            <div key={key} className="flex items-center gap-2">
              <span className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]">{formatKey(key)}:</span>
              <span className="text-xs font-mono text-[var(--color-rune-bright)]">{formatVal(val)}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// ─── Inventory Tab ────────────────────────────────────────────────────────────

const ITEM_CATEGORIES = [
  'Weapon', 'Armor', 'Shield', 'Ammunition', 'Potion', 'Scroll',
  'Wondrous Item', 'Ring', 'Rod', 'Staff', 'Wand', 'Gear', 'Component', 'Tool',
  'Mount', 'Vehicle', 'Trade Good', 'Treasure', 'Other',
]

const WEAPON_PROPERTIES = [
  'Size S', 'Size M', 'Size L', 'Type P', 'Type S', 'Type B',
  'Thrown', 'Two-handed', 'Speed 2', 'Speed 4', 'Speed 6', 'Speed 8', 'Speed 10',
]

function cpToDisplay(cp: number): string {
  if (cp === 0) return '—'
  const pp = Math.floor(cp / 1000)
  const gp = Math.floor((cp % 1000) / 100)
  const sp = Math.floor((cp % 100) / 10)
  const rem = cp % 10
  const parts: string[] = []
  if (pp) parts.push(`${pp}pp`)
  if (gp) parts.push(`${gp}gp`)
  if (sp) parts.push(`${sp}sp`)
  if (rem) parts.push(`${rem}cp`)
  return parts.join(' ')
}

type ItemFormData = {
  name: string
  category: string
  quantity: string
  weight: string
  value_cp: string
  equipped: boolean
  is_magical: boolean
  description: string
  properties: string[]
}

const emptyItemForm = (): ItemFormData => ({
  name: '',
  category: '',
  quantity: '1',
  weight: '0',
  value_cp: '0',
  equipped: false,
  is_magical: false,
  description: '',
  properties: [],
})

function itemToFormData(item: InventoryItem): ItemFormData {
  return {
    name: item.name,
    category: item.category ?? '',
    quantity: String(item.quantity),
    weight: String(item.weight),
    value_cp: String(item.value_cp),
    equipped: item.equipped,
    is_magical: item.is_magical,
    description: item.description ?? '',
    properties: item.properties ?? [],
  }
}

function InventoryTab({ character }: { character: Character }) {
  const items = character.inventory_items ?? []
  const snapshots = character.inventory_snapshots ?? []

  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [showSnapshots, setShowSnapshots] = useState(false)
  const [snapshotLabel, setSnapshotLabel] = useState('')
  const [viewingSnapshot, setViewingSnapshot] = useState<InventorySnapshot | null>(null)

  const inventoryBase = `/characters/${character.id}/inventory`

  // Totals
  const totalWeight = items.reduce((s, i) => s + Number(i.weight) * i.quantity, 0)
  const totalValue = items.reduce((s, i) => s + i.value_cp * i.quantity, 0)
  const toggleEquip = (item: InventoryItem) => {
    router.patch(`${inventoryBase}/${item.id}/equip`, {}, { preserveScroll: true })
  }

  const deleteItem = (item: InventoryItem) => {
    if (!confirm(`Remove "${item.name}" from inventory?`)) return
    router.delete(`${inventoryBase}/${item.id}`, { preserveScroll: true })
  }

  const startEdit = (item: InventoryItem) => {
    setEditingId(item.id)
    setShowForm(false)
  }

  const cancelEdit = () => setEditingId(null)
  const cancelAdd = () => setShowForm(false)

  const saveSnapshot = () => {
    if (!snapshotLabel.trim()) return
    router.post(
      `${inventoryBase}/snapshots`,
      { label: snapshotLabel.trim(), snapshot_type: 'manual' },
      {
        preserveScroll: true,
        onSuccess: () => setSnapshotLabel(''),
      }
    )
  }

  const deleteSnapshot = (snap: InventorySnapshot) => {
    if (!confirm(`Delete snapshot "${snap.label}"?`)) return
    router.delete(`${inventoryBase}/snapshots/${snap.id}`, { preserveScroll: true })
  }

  // Group items by category
  const grouped = items.reduce((acc, item) => {
    const cat = item.category ?? 'Other'
    if (!acc[cat]) acc[cat] = []
    acc[cat].push(item)
    return acc
  }, {} as Record<string, InventoryItem[]>)

  return (
    <div className="flex flex-col gap-4">

      {/* ── Summary bar ──────────────────────────────────────────── */}
      <div className="flex items-center gap-6 text-xs text-[var(--color-text-dim)]">
        <span><span className="font-mono text-[var(--color-text-base)]">{items.length}</span> items</span>
        <span><span className="font-mono text-[var(--color-text-base)]">{totalWeight.toFixed(1)}</span> lb total</span>
        <span><span className="font-mono text-[var(--color-text-base)]">{cpToDisplay(totalValue)}</span> total value</span>
        <div className="ml-auto flex gap-2">
          <Button variant="ghost" size="sm" onClick={() => setShowSnapshots(v => !v)}>
            {showSnapshots ? 'Hide History' : 'History'}
          </Button>
          <Button variant="rune" size="sm" onClick={() => { setShowForm(v => !v); setEditingId(null) }}>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M12 5v14M5 12h14" />
            </svg>
            Add Item
          </Button>
        </div>
      </div>

      {/* ── Add item form ─────────────────────────────────────────── */}
      {showForm && (
        <ItemForm
          characterId={character.id}
          onCancel={cancelAdd}
          onSuccess={cancelAdd}
        />
      )}

      {/* ── Snapshot panel ────────────────────────────────────────── */}
      {showSnapshots && (
        <div className="runic-card p-4 flex flex-col gap-3 border border-[var(--color-border)]">
          <p className="text-xs uppercase tracking-widest text-[var(--color-rune)] font-heading">Inventory History</p>

          {/* Save new snapshot */}
          <div className="flex gap-2 items-end">
            <div className="flex-1">
              <Input
                label="Snapshot label"
                value={snapshotLabel}
                onChange={e => setSnapshotLabel(e.target.value)}
                placeholder='e.g. "Campaign Start" or "After Session 4"'
              />
            </div>
            <Button variant="rune" size="sm" onClick={saveSnapshot} disabled={!snapshotLabel.trim()}>
              Save Snapshot
            </Button>
          </div>

          {/* Snapshot list */}
          {snapshots.length === 0 ? (
            <p className="text-xs text-[var(--color-text-dim)] text-center py-2">No snapshots yet.</p>
          ) : (
            <div className="flex flex-col gap-1 max-h-48 overflow-y-auto">
              {[...snapshots].reverse().map(snap => (
                <div key={snap.id} className="flex items-center gap-2 py-1.5 border-b border-[var(--color-border)] last:border-0">
                  <div className="flex-1 min-w-0">
                    <span className="text-xs text-[var(--color-text-bright)]">{snap.label}</span>
                    {snap.game_session && (
                      <span className="text-[10px] text-[var(--color-text-dim)] ml-2">
                        — {snap.game_session.title ?? `Session ${snap.game_session.session_number}`}
                      </span>
                    )}
                    <span className="text-[10px] text-[var(--color-text-dim)] ml-2">
                      {new Date(snap.created_at).toLocaleDateString()}
                    </span>
                    <span className="text-[10px] text-[var(--color-text-dim)] ml-2">
                      ({snap.items.length} items)
                    </span>
                  </div>
                  <Button variant="ghost" size="sm" onClick={() => setViewingSnapshot(snap === viewingSnapshot ? null : snap)}>
                    {viewingSnapshot?.id === snap.id ? 'Close' : 'View'}
                  </Button>
                  <button
                    onClick={() => deleteSnapshot(snap)}
                    className="text-[var(--color-danger)] opacity-60 hover:opacity-100 text-xs px-1"
                    title="Delete snapshot"
                  >
                    ✕
                  </button>
                </div>
              ))}
            </div>
          )}

          {/* Snapshot viewer */}
          {viewingSnapshot && (
            <div className="border border-[var(--color-rune-dim)] rounded p-3 mt-1">
              <p className="text-[10px] uppercase tracking-widest text-[var(--color-rune)] mb-2">
                {viewingSnapshot.label}
              </p>
              {viewingSnapshot.items.length === 0 ? (
                <p className="text-xs text-[var(--color-text-dim)]">Empty inventory at this point.</p>
              ) : (
                <div className="flex flex-col gap-1">
                  {viewingSnapshot.items.map((item, i) => {
                    const qtyLabel = inventoryQuantityLabel(item)
                    return (
                    <div key={i} className="flex items-center gap-2 text-xs">
                      {item.equipped && <div className="w-1 h-1 rounded-full bg-[var(--color-rune)] shrink-0" />}
                      <span className="text-[var(--color-text-bright)] flex-1">{item.name}</span>
                      {qtyLabel && <span className="text-[var(--color-text-dim)]">{qtyLabel}</span>}
                      {item.is_magical && <Badge variant="arcane">M</Badge>}
                      {item.category && <span className="text-[var(--color-text-dim)]">{item.category}</span>}
                    </div>
                    )
                  })}
                </div>
              )}
            </div>
          )}
        </div>
      )}

      {/* ── Item list ─────────────────────────────────────────────── */}
      {items.length === 0 && !showForm ? (
        <p className="text-sm text-[var(--color-text-dim)] text-center py-8">No items recorded.</p>
      ) : (
        Object.entries(grouped).sort(([a], [b]) => a.localeCompare(b)).map(([category, catItems]) => (
          <div key={category}>
            <p className="text-[10px] uppercase tracking-widest text-[var(--color-rune)] mb-1">{category}</p>
            <div className="flex flex-col gap-1">
              {catItems.map(item => (
                editingId === item.id ? (
                  <ItemForm
                    key={item.id}
                    characterId={character.id}
                    item={item}
                    onCancel={cancelEdit}
                    onSuccess={cancelEdit}
                  />
                ) : (
                  <ItemRow
                    key={item.id}
                    item={item}
                    onEquip={() => toggleEquip(item)}
                    onEdit={() => startEdit(item)}
                    onDelete={() => deleteItem(item)}
                  />
                )
              ))}
            </div>
          </div>
        ))
      )}
    </div>
  )
}

function ItemRow({
  item,
  onEquip,
  onEdit,
  onDelete,
}: {
  item: InventoryItem
  onEquip: () => void
  onEdit: () => void
  onDelete: () => void
}) {
  const [expanded, setExpanded] = useState(false)
  const qtyLabel = inventoryQuantityLabel(item)

  return (
    <div className="runic-card">
      <div className="px-3 py-2 flex items-center gap-2">
        {/* Equip toggle */}
        <button
          onClick={onEquip}
          title={item.equipped ? 'Unequip' : 'Equip'}
          className={`w-3 h-3 rounded-full border shrink-0 transition-colors ${
            item.equipped
              ? 'bg-[var(--color-rune)] border-[var(--color-rune)]'
              : 'border-[var(--color-border)] hover:border-[var(--color-muted)]'
          }`}
        />

        {/* Name + expand */}
        <button
          className="flex-1 text-left text-sm text-[var(--color-text-bright)] hover:text-[var(--color-text-white)] transition-colors"
          onClick={() => setExpanded(v => !v)}
        >
          {item.name}
        </button>

        {/* Quantity: always for components (including ×1); otherwise when not 1 */}
        {qtyLabel && (
          <span className="text-xs font-mono text-[var(--color-text-dim)] shrink-0">{qtyLabel}</span>
        )}

        {/* Weight */}
        {Number(item.weight) > 0 && (
          <span className="text-[10px] text-[var(--color-text-dim)] shrink-0">{Number(item.weight) * item.quantity}lb</span>
        )}

        {/* Value */}
        {item.value_cp > 0 && (
          <span className="text-[10px] font-mono text-[var(--color-text-dim)] shrink-0">{cpToDisplay(item.value_cp)}</span>
        )}

        {/* Badges */}
        {item.is_magical && <Badge variant="arcane">Magical</Badge>}

        {/* Edit / Delete */}
        <button
          onClick={onEdit}
          className="text-[var(--color-text-dim)] hover:text-[var(--color-rune)] transition-colors shrink-0 text-xs px-1"
          title="Edit"
        >
          ✎
        </button>
        <button
          onClick={onDelete}
          className="text-[var(--color-text-dim)] hover:text-[var(--color-danger)] transition-colors shrink-0 text-xs px-1"
          title="Delete"
        >
          ✕
        </button>
      </div>

      {/* Expanded details */}
      {expanded && (
        <div className="px-3 pb-2 pt-0 border-t border-[var(--color-border)] mt-0">
          <div className="flex flex-wrap gap-x-4 gap-y-1 mt-2 text-xs text-[var(--color-text-dim)]">
            {item.description && (
              <p className="w-full leading-relaxed text-[var(--color-text-base)]">{item.description}</p>
            )}
            {item.properties && item.properties.length > 0 && (
              <span>Properties: <span className="text-[var(--color-text-base)]">{item.properties.join(', ')}</span></span>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

function ItemForm({
  characterId,
  item,
  onCancel,
  onSuccess,
}: {
  characterId: number
  item?: InventoryItem
  onCancel: () => void
  onSuccess: () => void
}) {
  const isEdit = !!item
  const [form, setForm] = useState<ItemFormData>(item ? itemToFormData(item) : emptyItemForm())

  const set = <K extends keyof ItemFormData>(key: K, value: ItemFormData[K]) =>
    setForm(prev => ({ ...prev, [key]: value }))

  const toggleProp = (prop: string) => {
    setForm(prev => ({
      ...prev,
      properties: prev.properties.includes(prop)
        ? prev.properties.filter(p => p !== prop)
        : [...prev.properties, prop],
    }))
  }

  const [processing, setProcessing] = useState(false)

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    setProcessing(true)
    const payload = {
      ...form,
      quantity: parseInt(form.quantity) || 1,
      weight: parseFloat(form.weight) || 0,
      value_cp: parseInt(form.value_cp) || 0,
    }
    const url = isEdit
      ? `/characters/${characterId}/inventory/${item!.id}`
      : `/characters/${characterId}/inventory`
    if (isEdit) {
      router.patch(url, payload, {
        preserveScroll: true,
        onSuccess: () => { setProcessing(false); onSuccess() },
        onError: () => setProcessing(false),
      })
    } else {
      router.post(url, payload, {
        preserveScroll: true,
        onSuccess: () => { setProcessing(false); onSuccess() },
        onError: () => setProcessing(false),
      })
    }
  }

  return (
    <form onSubmit={submit} className="runic-card p-4 flex flex-col gap-3 border border-[var(--color-rune-dim)]">
      <p className="text-xs uppercase tracking-widest text-[var(--color-rune)] font-heading">
        {isEdit ? `Edit: ${item!.name}` : 'Add Item'}
      </p>

      {/* Row 1: name + category */}
      <div className="grid grid-cols-2 gap-3">
        <Input
          label="Name *"
          value={form.name}
          onChange={e => set('name', e.target.value)}
          required
        />
        <div className="flex flex-col gap-1">
          <label className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]">Category</label>
          <select
            value={form.category}
            onChange={e => set('category', e.target.value)}
            className="bg-[var(--color-deep)] border border-[var(--color-border)] rounded px-2 py-1.5 text-sm text-[var(--color-text-base)] focus:outline-none focus:border-[var(--color-rune)]"
          >
            <option value="">— None —</option>
            {ITEM_CATEGORIES.map(c => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>
      </div>

      {/* Row 2: qty + weight + value */}
      <div className="grid grid-cols-3 gap-3">
        <Input
          label="Quantity"
          type="number"
          min="0"
          value={form.quantity}
          onChange={e => set('quantity', e.target.value)}
        />
        <Input
          label="Weight (lb)"
          type="number"
          min="0"
          step="0.1"
          value={form.weight}
          onChange={e => set('weight', e.target.value)}
        />
        <Input
          label="Value (cp)"
          type="number"
          min="0"
          value={form.value_cp}
          onChange={e => set('value_cp', e.target.value)}
        />
      </div>

      {/* Row 3: checkboxes */}
      <div className="flex flex-wrap gap-4">
        {([
          ['equipped', 'Equipped'],
          ['is_magical', 'Magical'],
        ] as [keyof ItemFormData, string][]).map(([key, label]) => (
          <label key={key} className="flex items-center gap-1.5 cursor-pointer select-none">
            <input
              type="checkbox"
              checked={form[key] as boolean}
              onChange={e => set(key, e.target.checked)}
              className="accent-[var(--color-rune)]"
            />
            <span className="text-xs text-[var(--color-text-base)]">{label}</span>
          </label>
        ))}
      </div>

      {/* Row 4: weapon properties */}
      <div>
        <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)] mb-1">Weapon Properties</p>
        <div className="flex flex-wrap gap-1.5">
          {WEAPON_PROPERTIES.map(prop => (
            <button
              key={prop}
              type="button"
              onClick={() => toggleProp(prop)}
              className={`px-2 py-0.5 text-[10px] rounded border transition-colors ${
                form.properties.includes(prop)
                  ? 'border-[var(--color-rune)] bg-[var(--color-rune)] text-[var(--color-deep)]'
                  : 'border-[var(--color-border)] text-[var(--color-text-dim)] hover:border-[var(--color-muted)]'
              }`}
            >
              {prop}
            </button>
          ))}
        </div>
      </div>

      {/* Row 5: description */}
      <div className="flex flex-col gap-1">
        <label className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]">Description</label>
        <textarea
          value={form.description}
          onChange={e => set('description', e.target.value)}
          rows={2}
          className="bg-[var(--color-deep)] border border-[var(--color-border)] rounded px-2 py-1.5 text-sm text-[var(--color-text-base)] focus:outline-none focus:border-[var(--color-rune)] resize-none"
        />
      </div>

      {/* Actions */}
      <div className="flex gap-2 justify-end">
        <Button type="button" variant="ghost" size="sm" onClick={onCancel}>Cancel</Button>
        <Button type="submit" variant="rune" size="sm" disabled={processing}>
          {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Item'}
        </Button>
      </div>
    </form>
  )
}
