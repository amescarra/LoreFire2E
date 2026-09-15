import React, { useRef, useState, useEffect } from 'react'
import { Head, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import { Button } from '@/Components/Button'
import { Input, Textarea, Select } from '@/Components/Input'
import { RuneDivider } from '@/Components/RuneDivider'
import { ClassFeatures } from '@/Components/ClassFeatures'
import { ClassPathFields } from '@/Components/ClassPathFields'
import { KitField } from '@/Components/KitField'
import { PsionicistSheetFields } from '@/Components/PsionicistSheetFields'
import { SpellsTab } from '@/Components/SpellsTab'
import { Campaign, Character } from '@/types'
import {
  ALIGNMENTS, NONWEAPON_PROFICIENCY_SUGGESTIONS, PRIEST_SPHERES, RACES,
  SAVE_CATEGORIES, WEAPON_PROFICIENCY_SUGGESTIONS,
  ClassPath, anyCaster, backfillClassLevelsXp, combinedHitDie, combinedSavingThrows, combinedThac0,
  derivedExperiencePoints, formatSigned, hasPsionicist, normalizeClassLevels, primaryAdjustment,
} from '@/lib/adnd2e'

interface Props {
  campaign: Campaign | null
  character: Character
  campaigns?: Campaign[]
  imageGenProvider: string | null
}

export default function Edit({ campaign, character, campaigns, imageGenProvider }: Props) {
  const standalone = campaign === null

  const { data, setData, post, transform, processing, errors } = useForm({
    _method: 'PUT',
    name: character.name,
    player_name: character.player_name ?? '',
    race: character.race,
    subrace: character.subrace ?? '',
    class: character.class,
    subclass: character.subclass ?? '',
    class_path: (character.class_path ?? 'single') as ClassPath,
    class_levels: backfillClassLevelsXp(
      normalizeClassLevels(character.class_levels, character.class, character.level, character.class_path ?? 'single'),
      character.class_path ?? 'single',
      character.experience_points ?? 0,
    ),
    level: character.level,
    background: character.background ?? '',
    alignment: character.alignment ?? '',
    experience_points: character.experience_points,
    strength: character.strength,
    dexterity: character.dexterity,
    constitution: character.constitution,
    intelligence: character.intelligence,
    wisdom: character.wisdom,
    charisma: character.charisma,
    max_hp: character.max_hp,
    current_hp: character.current_hp,
    armor_class: character.armor_class,
    thac0: character.thac0 ?? combinedThac0(normalizeClassLevels(character.class_levels, character.class, character.level, character.class_path ?? 'single')),
    speed: character.speed,
    hit_die: character.hit_die ?? combinedHitDie(normalizeClassLevels(character.class_levels, character.class, character.level, character.class_path ?? 'single')),
    exceptional_strength: character.exceptional_strength ?? '',
    saving_throws: (character.saving_throws ?? combinedSavingThrows(normalizeClassLevels(character.class_levels, character.class, character.level, character.class_path ?? 'single'))) as Record<string, number>,
    weapon_proficiencies: character.weapon_proficiencies ?? [],
    nonweapon_proficiencies: character.nonweapon_proficiencies ?? [],
    priest_spheres: (character.priest_spheres ?? { major: [], minor: [] }) as { major: string[]; minor: string[] },
    copper: character.copper,
    silver: character.silver,
    electrum: character.electrum,
    gold: character.gold,
    platinum: character.platinum,
    spellcasting_ability: character.spellcasting_ability ?? '',
    memorization: (character.memorization ?? {}) as Record<string, number>,
    mannerisms: character.mannerisms ?? '',
    motivations: character.motivations ?? '',
    ties: character.ties ?? '',
    weaknesses: character.weaknesses ?? '',
    backstory: character.backstory ?? '',
    appearance_description: character.appearance_description ?? '',
    campaign_id: character.campaign_id?.toString() ?? '',
    class_features: (character.class_features ?? {}) as Record<string, unknown>,
    psp_current: character.psp_current ?? null,
    psp_max: character.psp_max ?? null,
    psionic_powers: character.psionic_powers ?? [],
    portrait: null as File | null,
    portrait_style: (character.portrait_style ?? 'lifelike') as 'lifelike' | 'renaissance' | 'comic',
  })

  // Coerce empty string campaign_id to null so the nullable|exists validation passes
  transform((d: any) => {
    const class_levels = d.class_path === 'single' && d.class_levels[0]
      ? [{ ...d.class_levels[0], xp: Math.max(0, Number(d.experience_points) || 0) }]
      : d.class_levels
    return {
      ...d,
      campaign_id: d.campaign_id === '' ? null : d.campaign_id,
      class_levels,
      experience_points: derivedExperiencePoints(class_levels, d.experience_points),
    }
  })

  useEffect(() => {
    const entries = data.class_levels.filter(e => e.class)
    if (entries.length === 0) return
    setData('thac0', combinedThac0(entries))
    setData('hit_die', combinedHitDie(entries))
    setData('saving_throws', combinedSavingThrows(entries))
    setData('class', entries[0].class)
    setData('level', entries[0].level)
  }, [data.class_levels, data.class_path])

  const handleSlotChange = (slotLevel: number, value: number) => {
    setData('memorization', { ...data.memorization, [slotLevel]: value })
  }

  const toggleList = (list: 'weapon_proficiencies' | 'nonweapon_proficiencies', value: string) => {
    const current = data[list] as string[]
    const next = current.includes(value) ? current.filter(v => v !== value) : [...current, value]
    setData(list, next)
  }

  const toggleSphere = (band: 'major' | 'minor', sphere: string) => {
    const spheres = data.priest_spheres
    const current = spheres[band] ?? []
    const next = current.includes(sphere) ? current.filter(s => s !== sphere) : [...current, sphere]
    setData('priest_spheres', { ...spheres, [band]: next })
  }

  const portraitInputRef = useRef<HTMLInputElement>(null)
  const [portraitPreview, setPortraitPreview] = useState<string | null>(
    character.portrait_path ? `/storage-file/${character.portrait_path}?t=${new Date(character.updated_at).getTime()}` : null
  )

  // Portrait generation state
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
        setPortraitPreview(`/storage-file/${json.portrait_path}?t=${Date.now()}`)
        clearInterval(genPollRef.current!)
      } else if (json.status === 'failed') {
        clearInterval(genPollRef.current!)
      }
    }, 3000)
    return () => { if (genPollRef.current) clearInterval(genPollRef.current) }
  }, [genStatus])

  const handleGeneratePortrait = async () => {
    setGenStatus('generating')
    await fetch(`/characters/${character.id}/generate-portrait`, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ portrait_style: data.portrait_style }),
    })
  }

  const handleCancelGeneration = async () => {
    await fetch(`/characters/${character.id}/cancel-portrait`, { method: 'POST', headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '' } })
    setGenStatus('failed')
    if (genPollRef.current) clearInterval(genPollRef.current)
  }

  const handlePortraitChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0] ?? null
    setData('portrait', file)
    if (file) setPortraitPreview(URL.createObjectURL(file))
  }

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    if (standalone) {
      post(`/characters/${character.id}`, { forceFormData: true })
    } else {
      post(`/campaigns/${campaign!.id}/characters/${character.id}`, { forceFormData: true })
    }
  }

  const cancelHref = standalone
    ? `/characters/${character.id}`
    : `/campaigns/${campaign!.id}/characters/${character.id}`

  const breadcrumbs = standalone
    ? [
        { label: 'Characters', href: '/characters' },
        { label: character.name, href: `/characters/${character.id}` },
        { label: 'Edit' },
      ]
    : [
        { label: 'Campaigns', href: '/campaigns' },
        { label: campaign!.name, href: `/campaigns/${campaign!.id}` },
        { label: character.name, href: `/campaigns/${campaign!.id}/characters/${character.id}` },
        { label: 'Edit' },
      ]

  const adj = (ability: string, score: number) =>
    formatSigned(primaryAdjustment(ability, score, data.exceptional_strength || null, data.class))

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title={`Edit ${character.name}`} />

      <div className="max-w-2xl mx-auto">
        <h1 className="font-heading text-2xl text-[var(--color-text-white)] tracking-widest uppercase mb-8">
          Edit Character
        </h1>

        <form onSubmit={submit} className="flex flex-col gap-5">

          {/* Identity */}
          <RuneDivider label="Identity" />

          {/* Portrait upload */}
          <div className="flex items-start gap-4">
            <div className="shrink-0">
              <div
                className="w-20 h-20 rounded border border-[var(--color-border)] overflow-hidden flex items-center justify-center cursor-pointer hover:border-[var(--color-rune)] transition-colors relative"
                style={{ background: 'var(--color-deep)' }}
                onClick={() => portraitInputRef.current?.click()}
              >
                {genStatus === 'generating' && (
                  <div className="absolute inset-0 flex items-center justify-center bg-[var(--color-bg)]/70 z-10">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--color-rune)" strokeWidth="2" className="animate-spin">
                      <path d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" opacity="0.2" />
                      <path d="M21 12a9 9 0 00-9-9" />
                    </svg>
                  </div>
                )}
                {portraitPreview ? (
                  <img src={portraitPreview} alt="Portrait" className="w-full h-full object-cover" />
                ) : (
                  <span className="font-heading text-3xl text-[var(--color-rune)]">{character.name[0]}</span>
                )}
              </div>
            </div>
            <div className="flex flex-col gap-1.5 justify-center">
              <p className="text-xs text-[var(--color-text-dim)] uppercase tracking-widest font-heading">Portrait</p>
              <div className="flex gap-2 flex-wrap">
                <button
                  type="button"
                  onClick={() => portraitInputRef.current?.click()}
                  className="px-3 py-1.5 text-xs font-heading tracking-widest uppercase border border-[var(--color-border)] rounded text-[var(--color-text-dim)] hover:border-[var(--color-rune)] hover:text-[var(--color-rune)] transition-colors"
                >
                  {portraitPreview ? 'Change' : 'Upload'}
                </button>
                {imageGenProvider && imageGenProvider !== 'none' && (
                  <button
                    type="button"
                    onClick={handleGeneratePortrait}
                    disabled={genStatus === 'generating'}
                    className="px-3 py-1.5 text-xs font-heading tracking-widest uppercase border border-[var(--color-rune)] rounded text-[var(--color-rune)] hover:bg-[var(--color-rune)]/10 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    {genStatus === 'generating' ? 'Generating…' : 'AI Portrait'}
                  </button>
                )}
                {genStatus === 'generating' && (
                  <button
                    type="button"
                    onClick={handleCancelGeneration}
                    className="px-3 py-1.5 text-xs font-heading tracking-widest uppercase border border-[var(--color-danger)] rounded text-[var(--color-danger)] hover:bg-[var(--color-danger)]/10 transition-colors"
                  >
                    Cancel
                  </button>
                )}
              </div>
              {/* Portrait style toggle — only shown when AI provider is configured */}
              {imageGenProvider && imageGenProvider !== 'none' && (
                <div className="flex gap-1 mt-0.5">
                  {(['lifelike', 'renaissance', 'comic'] as const).map(style => (
                    <button
                      key={style}
                      type="button"
                      onClick={() => setData('portrait_style', style)}
                      className={[
                        'px-2 py-0.5 text-[10px] font-heading tracking-widest uppercase rounded border transition-colors',
                        data.portrait_style === style
                          ? 'border-[var(--color-rune)] text-[var(--color-rune)] bg-[var(--color-rune)]/10'
                          : 'border-[var(--color-border)] text-[var(--color-text-dim)] hover:border-[var(--color-rune)] hover:text-[var(--color-rune)]',
                      ].join(' ')}
                    >
                      {style === 'lifelike' ? 'Lifelike' : style === 'renaissance' ? 'Renaissance' : 'Comic'}
                    </button>
                  ))}
                </div>
              )}
              {genStatus === 'failed' && (
                <p className="text-xs text-[var(--color-danger)]">Generation failed. Try again.</p>
              )}
              {data.portrait && (
                <span className="text-xs text-[var(--color-rune)]">{data.portrait.name}</span>
              )}
              {errors.portrait && (
                <p className="text-xs text-[var(--color-danger)]">{errors.portrait}</p>
              )}
            </div>
            <input
              ref={portraitInputRef}
              type="file"
              accept="image/*"
              className="hidden"
              onChange={handlePortraitChange}
            />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <Input label="Character Name" value={data.name} onChange={e => setData('name', e.target.value)} error={errors.name} required autoFocus />
            <Input label="Player Name" value={data.player_name} onChange={e => setData('player_name', e.target.value)} placeholder="Optional" />
          </div>

          {/* Campaign assignment — only shown in standalone mode */}
          {standalone && campaigns && campaigns.length > 0 && (
            <Select label="Campaign (optional)" value={data.campaign_id} onChange={e => setData('campaign_id', e.target.value)}>
              <option value="">No campaign</option>
              {campaigns.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          )}

          <div className="grid grid-cols-3 gap-4">
            <Select label="Race" value={data.race} onChange={e => setData('race', e.target.value)} error={errors.race}>
              <option value="">Select…</option>
              {RACES.map(r => <option key={r} value={r}>{r}</option>)}
            </Select>
            <Input label="Subrace" value={data.subrace} onChange={e => setData('subrace', e.target.value)} placeholder="Optional" />
            <Select label="Alignment" value={data.alignment} onChange={e => setData('alignment', e.target.value)}>
              <option value="">Select…</option>
              {ALIGNMENTS.map(a => <option key={a} value={a}>{a}</option>)}
            </Select>
          </div>

          <ClassPathFields
            path={data.class_path}
            entries={data.class_levels}
            onPath={path => setData('class_path', path)}
            onEntries={next => {
              setData('class_levels', next)
              setData('experience_points', derivedExperiencePoints(next, data.experience_points))
            }}
          />

          <KitField
            race={data.race}
            entries={data.class_levels}
            value={data.subclass}
            onChange={value => setData('subclass', value)}
          />

          {hasPsionicist(data.class_levels) && (
            <PsionicistSheetFields
              pspCurrent={data.psp_current}
              pspMax={data.psp_max}
              powers={data.psionic_powers}
              onPspCurrent={value => setData('psp_current', value)}
              onPspMax={value => setData('psp_max', value)}
              onPowers={value => setData('psionic_powers', value)}
            />
          )}

          <div className={`grid gap-4 ${data.class_path === 'single' ? 'grid-cols-2' : 'grid-cols-1'}`}>
            <Input label="Origin / notes" value={data.background} onChange={e => setData('background', e.target.value)} placeholder="Home region, patron…" />
            {data.class_path === 'single' && (
              <Input
                label="Experience Points"
                type="number"
                min={0}
                value={data.experience_points}
                onChange={e => {
                  const xp = Math.max(0, parseInt(e.target.value) || 0)
                  setData('experience_points', xp)
                  const first = data.class_levels[0]
                  if (first) setData('class_levels', [{ ...first, xp }])
                }}
              />
            )}
          </div>

          {/* Ability Scores */}
          <RuneDivider label="Ability Scores" />

          <div className="grid grid-cols-6 gap-3">
            {([
              ['STR', 'strength'], ['DEX', 'dexterity'], ['CON', 'constitution'],
              ['INT', 'intelligence'], ['WIS', 'wisdom'], ['CHA', 'charisma']
            ] as [string, keyof typeof data][]).map(([label, key]) => (
              <div key={key} className="flex flex-col items-center gap-1">
                <label className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]">{label}</label>
                <input
                  type="number" min={1} max={30}
                  value={data[key] as number}
                  onChange={e => setData(key, parseInt(e.target.value) || 10)}
                  className="w-full text-center bg-[var(--color-deep)] border border-[var(--color-border)] rounded py-2 text-[var(--color-text-white)] font-heading text-lg focus:outline-none focus:border-[var(--color-rune)]"
                />
                <span className="text-xs text-[var(--color-rune)] font-mono">{adj(String(key), data[key] as number)}</span>
              </div>
            ))}
          </div>

          {data.class_levels.some(e => e.class === 'Fighter' || e.class === 'Paladin' || e.class === 'Ranger') && data.strength === 18 && (
            <Input
              label="Exceptional Strength (18/xx)"
              value={data.exceptional_strength}
              onChange={e => setData('exceptional_strength', e.target.value)}
              placeholder="01–00"
            />
          )}

          <div>
            <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)] mb-2">Saving Throws (roll ≥ target)</p>
            <div className="grid grid-cols-5 gap-2">
              {SAVE_CATEGORIES.map(cat => (
                <Input
                  key={cat.key}
                  label={cat.label.split(' ')[0]}
                  type="number"
                  min={1}
                  max={20}
                  value={data.saving_throws[cat.key] ?? 20}
                  onChange={e => setData('saving_throws', { ...data.saving_throws, [cat.key]: parseInt(e.target.value) || 20 })}
                />
              ))}
            </div>
          </div>

          <div>
            <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)] mb-2">Weapon Proficiencies</p>
            <div className="flex flex-wrap gap-2">
              {WEAPON_PROFICIENCY_SUGGESTIONS.map(w => {
                const active = (data.weapon_proficiencies as string[]).includes(w)
                return (
                  <button
                    type="button"
                    key={w}
                    onClick={() => toggleList('weapon_proficiencies', w)}
                    className={`px-2 py-1 rounded text-[10px] uppercase tracking-wider border transition-colors ${
                      active
                        ? 'border-[var(--color-rune)] text-[var(--color-rune-bright)] bg-[var(--color-rune)]/10'
                        : 'border-[var(--color-border)] text-[var(--color-text-dim)]'
                    }`}
                  >
                    {w}
                  </button>
                )
              })}
            </div>
          </div>

          <div>
            <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)] mb-2">Non-weapon Proficiencies</p>
            <div className="flex flex-wrap gap-2">
              {NONWEAPON_PROFICIENCY_SUGGESTIONS.map(w => {
                const active = (data.nonweapon_proficiencies as string[]).includes(w)
                return (
                  <button
                    type="button"
                    key={w}
                    onClick={() => toggleList('nonweapon_proficiencies', w)}
                    className={`px-2 py-1 rounded text-[10px] uppercase tracking-wider border transition-colors ${
                      active
                        ? 'border-[var(--color-rune)] text-[var(--color-rune-bright)] bg-[var(--color-rune)]/10'
                        : 'border-[var(--color-border)] text-[var(--color-text-dim)]'
                    }`}
                  >
                    {w}
                  </button>
                )
              })}
            </div>
          </div>

          {data.class_levels.some(e => e.class === 'Cleric' || e.class === 'Druid' || e.class === 'Paladin') && (
            <div>
              <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)] mb-2">Priest Spheres</p>
              <p className="text-[10px] text-[var(--color-text-dim)] mb-1">Major</p>
              <div className="flex flex-wrap gap-2 mb-2">
                {PRIEST_SPHERES.map(s => {
                  const active = (data.priest_spheres.major ?? []).includes(s)
                  return (
                    <button type="button" key={`maj-${s}`} onClick={() => toggleSphere('major', s)}
                      className={`px-2 py-1 rounded text-[10px] uppercase tracking-wider border ${active ? 'border-[var(--color-rune)] text-[var(--color-rune-bright)] bg-[var(--color-rune)]/10' : 'border-[var(--color-border)] text-[var(--color-text-dim)]'}`}>
                      {s}
                    </button>
                  )
                })}
              </div>
              <p className="text-[10px] text-[var(--color-text-dim)] mb-1">Minor</p>
              <div className="flex flex-wrap gap-2">
                {PRIEST_SPHERES.map(s => {
                  const active = (data.priest_spheres.minor ?? []).includes(s)
                  return (
                    <button type="button" key={`min-${s}`} onClick={() => toggleSphere('minor', s)}
                      className={`px-2 py-1 rounded text-[10px] uppercase tracking-wider border ${active ? 'border-[var(--color-rune)] text-[var(--color-rune-bright)] bg-[var(--color-rune)]/10' : 'border-[var(--color-border)] text-[var(--color-text-dim)]'}`}>
                      {s}
                    </button>
                  )
                })}
              </div>
            </div>
          )}

          <RuneDivider label="Combat & HP" />

          <div className="grid grid-cols-3 gap-4">
            <Input label="Max HP" type="number" min={1} value={data.max_hp} onChange={e => setData('max_hp', parseInt(e.target.value) || 1)} />
            <Input label="Current HP" type="number" value={data.current_hp} onChange={e => setData('current_hp', parseInt(e.target.value) || 0)} hint="0 unconscious · −1 to −9 dying · −10 dead" />
            <Input label="Hit Die" value={data.hit_die} onChange={e => setData('hit_die', e.target.value)} />
          </div>

          <div className="grid grid-cols-3 gap-4">
            <Input label="Armor Class" type="number" value={data.armor_class} onChange={e => setData('armor_class', parseInt(e.target.value) || 0)} hint="Descending" />
            <Input label="THAC0" type="number" value={data.thac0} onChange={e => setData('thac0', parseInt(e.target.value) || 20)} />
            <Input label="Movement" type="number" min={0} value={data.speed} onChange={e => setData('speed', parseInt(e.target.value) || 12)} />
          </div>

          {/* Currency */}
          <RuneDivider label="Currency" />

          <div className="grid grid-cols-5 gap-3">
            <Input label="Copper" type="number" min={0} value={data.copper} onChange={e => setData('copper', parseInt(e.target.value) || 0)} />
            <Input label="Silver" type="number" min={0} value={data.silver} onChange={e => setData('silver', parseInt(e.target.value) || 0)} />
            <Input label="Electrum" type="number" min={0} value={data.electrum} onChange={e => setData('electrum', parseInt(e.target.value) || 0)} />
            <Input label="Gold" type="number" min={0} value={data.gold} onChange={e => setData('gold', parseInt(e.target.value) || 0)} />
            <Input label="Platinum" type="number" min={0} value={data.platinum} onChange={e => setData('platinum', parseInt(e.target.value) || 0)} />
          </div>

          {/* Spellcasting */}
          <RuneDivider label="Spellcasting" />

          <Select label="Spellcasting Ability" value={data.spellcasting_ability} onChange={e => setData('spellcasting_ability', e.target.value)}>
            <option value="">None / Not a caster</option>
            <option value="intelligence">Intelligence</option>
            <option value="wisdom">Wisdom</option>
          </Select>

          {anyCaster(data.class_levels) && (
            <div>
              <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)] mb-2">Memorization capacity (spells per level)</p>
              <div className="grid grid-cols-9 gap-2">
                {Array.from({ length: 9 }, (_, i) => i + 1).map(lvl => (
                  <div key={lvl} className="flex flex-col items-center gap-1">
                    <label className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)]">
                      {lvl === 1 ? '1st' : lvl === 2 ? '2nd' : lvl === 3 ? '3rd' : `${lvl}th`}
                    </label>
                    <input
                      type="number"
                      min={0}
                      max={12}
                      value={(data.memorization as Record<string, number>)[lvl] ?? 0}
                      onChange={e => handleSlotChange(lvl, parseInt(e.target.value) || 0)}
                      className="w-full text-center bg-[var(--color-deep)] border border-[var(--color-border)] rounded py-1.5 text-[var(--color-text-white)] font-heading text-sm focus:outline-none focus:border-[var(--color-rune)]"
                    />
                  </div>
                ))}
              </div>
              <p className="text-[10px] text-[var(--color-text-dim)] mt-1">Each entry is one specific memorized spell. Overnight rest rememorizes what was cast.</p>
            </div>
          )}

          <ClassFeatures
            characterClass={data.class}
            level={data.level}
            charisma={data.charisma}
            wisdom={data.wisdom}
            value={data.class_features as Record<string, unknown>}
            onChange={cf => setData('class_features', cf)}
          />

          <RuneDivider label="Spell Repertoire" />

          <SpellsTab
            characterId={character.id}
            characterClass={data.class}
            spells={character.spells ?? []}
            memorization={data.memorization as Record<string, number>}
            inventoryItems={character.inventory_items ?? []}
          />

          {/* Notes */}
          <RuneDivider label="Character Notes" />

          <div className="grid grid-cols-2 gap-4">
            <Textarea label="Mannerisms" value={data.mannerisms} onChange={e => setData('mannerisms', e.target.value)} rows={3} placeholder="How they speak, move, and present themselves…" />
            <Textarea label="Motivations" value={data.motivations} onChange={e => setData('motivations', e.target.value)} rows={3} placeholder="What they pursue or refuse…" />
            <Textarea label="Ties" value={data.ties} onChange={e => setData('ties', e.target.value)} rows={3} placeholder="Patrons, rivals, homelands, oaths…" />
            <Textarea label="Weaknesses" value={data.weaknesses} onChange={e => setData('weaknesses', e.target.value)} rows={3} placeholder="Fears, vices, or complications…" />
          </div>

          <Textarea label="Backstory" value={data.backstory} onChange={e => setData('backstory', e.target.value)} rows={5} placeholder="The origin tale of your character…" />

          <Textarea
            label="Appearance Description"
            value={data.appearance_description}
            onChange={e => setData('appearance_description', e.target.value)}
            rows={3}
            placeholder="Tall with silver hair and amber eyes, wearing leather armor etched with runic symbols…"
            hint={imageGenProvider && imageGenProvider !== 'none' ? 'Used for AI portrait generation.' : undefined}
          />

          <div className="flex gap-3 mt-4">
            <Button type="submit" variant="rune" disabled={processing}>
              {processing ? 'Saving…' : 'Save Changes'}
            </Button>
            <Button type="button" variant="ghost" as="a" href={cancelHref}>
              Cancel
            </Button>
          </div>
        </form>
      </div>
    </AppLayout>
  )
}
