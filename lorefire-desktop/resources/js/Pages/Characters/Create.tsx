import React from 'react'
import { Head, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import { Button } from '@/Components/Button'
import { Input, Select } from '@/Components/Input'
import { RuneDivider } from '@/Components/RuneDivider'
import { Campaign } from '@/types'
import { ClassPathFields } from '@/Components/ClassPathFields'
import { KitField } from '@/Components/KitField'
import { PsionicistSheetFields } from '@/Components/PsionicistSheetFields'
import { AbilityScoreBlock } from '@/Components/AbilityScoreBlock'
import {
  ABILITY_ORDER, ALIGNMENTS, RACES,
  ClassPath, combinedHitDie, combinedThac0, derivedExperiencePoints, hasPsionicist, movementRate,
} from '@/lib/adnd2e'

interface Props {
  campaign: Campaign | null
  campaigns?: Campaign[]
}

export default function Create({ campaign, campaigns }: Props) {
  const standalone = campaign === null

  const { data, setData, post, transform, processing, errors } = useForm({
    name: '', player_name: '', race: '', subrace: '', class: '', subclass: '',
    class_path: 'single' as ClassPath,
    class_levels: [{ class: '', level: 1 }],
    level: 1, experience_points: 0, background: '', alignment: '',
    strength: 10, exceptional_strength: '', dexterity: 10, constitution: 10,
    intelligence: 10, wisdom: 10, charisma: 10,
    max_hp: 8, current_hp: 8, armor_class: 10, speed: 12,
    campaign_id: campaign?.id?.toString() ?? '',
    psp_current: null as number | null,
    psp_max: null as number | null,
    psionic_powers: [] as string[],
  })

  transform((d) => {
    const class_levels = d.class_path === 'single' && d.class_levels[0]
      ? [{ ...d.class_levels[0], xp: Math.max(0, Number(d.experience_points) || 0) }]
      : d.class_levels
    return {
      ...d,
      class_levels,
      experience_points: derivedExperiencePoints(class_levels, d.experience_points),
    }
  })

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    if (standalone) {
      post('/characters')
    } else {
      post(`/campaigns/${campaign!.id}/characters`)
    }
  }

  const entries = data.class_levels.filter(e => e.class)

  const breadcrumbs = standalone
    ? [{ label: 'Characters', href: '/characters' }, { label: 'New Character' }]
    : [
        { label: 'Campaigns', href: '/campaigns' },
        { label: campaign!.name, href: `/campaigns/${campaign!.id}` },
        { label: 'New Character' },
      ]

  const cancelHref = standalone ? '/characters' : `/campaigns/${campaign!.id}`

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title="New Character" />

      <div className="max-w-2xl mx-auto">
        <h1 className="font-heading text-2xl text-[var(--color-text-white)] tracking-widest uppercase mb-2">New Character</h1>
        <p className="text-xs text-[var(--color-text-dim)] mb-8">AD&amp;D 2nd Edition sheet — THAC0, descending AC, Vancian memorization.</p>

        <form onSubmit={submit} className="flex flex-col gap-5">
          <div className="grid grid-cols-2 gap-4">
            <Input label="Character Name" value={data.name} onChange={e => setData('name', e.target.value)} error={errors.name} required autoFocus />
            <Input label="Player Name" value={data.player_name} onChange={e => setData('player_name', e.target.value)} placeholder="Optional" />
          </div>

          {standalone && campaigns && campaigns.length > 0 && (
            <Select label="Campaign (optional)" value={data.campaign_id} onChange={e => setData('campaign_id', e.target.value)}>
              <option value="">No campaign</option>
              {campaigns.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          )}

          <div className="grid grid-cols-3 gap-4">
            <Select
              label="Race"
              value={data.race}
              onChange={e => {
                const race = e.target.value
                setData('race', race)
                setData('speed', movementRate(race))
              }}
              error={errors.race}
            >
              <option value="">Select…</option>
              {RACES.map(r => <option key={r} value={r}>{r}</option>)}
            </Select>
            <Input label="Subrace" value={data.subrace} onChange={e => setData('subrace', e.target.value)} placeholder="Hill dwarf, high elf…" />
            <Select label="Alignment" value={data.alignment} onChange={e => setData('alignment', e.target.value)}>
              <option value="">Select…</option>
              {ALIGNMENTS.map(a => <option key={a} value={a}>{a}</option>)}
            </Select>
          </div>

          <ClassPathFields
            path={data.class_path}
            entries={data.class_levels}
            onPath={path => {
              setData('class_path', path)
              const first = data.class_levels[0] ?? { class: '', level: 1 }
              setData('class', first.class)
              setData('level', first.level)
            }}
            onEntries={next => {
              setData('class_levels', next)
              setData('class', next[0]?.class ?? '')
              setData('level', next[0]?.level ?? 1)
            }}
          />
          {errors.class && <p className="text-xs text-[var(--color-danger)]">{errors.class}</p>}

          {data.class_path === 'single' && (
            <Input
              label="Experience Points"
              type="number"
              min={0}
              value={data.experience_points}
              onChange={e => {
                const xp = Math.max(0, parseInt(e.target.value) || 0)
                setData('experience_points', xp)
                const first = data.class_levels[0] ?? { class: '', level: 1 }
                setData('class_levels', [{ ...first, xp }])
              }}
            />
          )}

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

          <Input label="Origin / notes" value={data.background} onChange={e => setData('background', e.target.value)} placeholder="Home region, patron, or kit notes…" />

          <RuneDivider label="Ability Scores" />

          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            {ABILITY_ORDER.map(({ label, key }) => (
              <AbilityScoreBlock
                key={key}
                ability={key}
                label={label}
                score={data[key] as number}
                exceptional={data.exceptional_strength || null}
                characterClass={data.class || 'Fighter'}
                variant="form"
                scoreControl={
                  <input
                    type="number" min={1} max={25}
                    value={data[key] as number}
                    onChange={e => setData(key, parseInt(e.target.value) || 10)}
                    className="w-full text-center bg-[var(--color-deep)] border border-[var(--color-border)] rounded py-2 text-[var(--color-text-white)] font-heading text-lg focus:outline-none focus:border-[var(--color-rune)]"
                  />
                }
              />
            ))}
          </div>

          {entries.some(e => e.class === 'Fighter' || e.class === 'Paladin' || e.class === 'Ranger') && data.strength === 18 && (
            <Input
              label="Exceptional Strength (18/xx)"
              value={data.exceptional_strength}
              onChange={e => setData('exceptional_strength', e.target.value)}
              placeholder="01–00"
            />
          )}

          <RuneDivider label="Combat" />

          <div className="grid grid-cols-4 gap-4">
            <Input label="Max HP" type="number" min={1} value={data.max_hp} onChange={e => { const v = parseInt(e.target.value); setData('max_hp', v); setData('current_hp', v) }} />
            <Input label="Armor Class" type="number" value={data.armor_class} onChange={e => setData('armor_class', parseInt(e.target.value))} hint="Descending — 10 unarmored" />
            <Input label="Movement" type="number" min={0} value={data.speed} onChange={e => setData('speed', parseInt(e.target.value))} />
            <div className="flex flex-col justify-end pb-0.5">
              <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)] mb-1">Computed</p>
              <p className="text-sm text-[var(--color-rune-bright)] font-mono">
                THAC0 {entries.length ? combinedThac0(entries) : '—'} · {entries.length ? combinedHitDie(entries) : 'HD'}
              </p>
            </div>
          </div>

          <div className="flex gap-3 mt-4">
            <Button type="submit" variant="rune" disabled={processing}>{processing ? 'Creating…' : 'Create Character'}</Button>
            <Button type="button" variant="ghost" as="a" href={cancelHref}>Cancel</Button>
          </div>
        </form>
      </div>
    </AppLayout>
  )
}
