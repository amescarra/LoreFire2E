import React, { useMemo, useState } from 'react'
import { Head, useForm, usePage, router } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import { Button } from '@/Components/Button'
import { Input, Select } from '@/Components/Input'
import { RuneDivider } from '@/Components/RuneDivider'
import { Badge } from '@/Components/Badge'
import { PageProps } from '@/types'

interface RuleSourceRow {
  code: string
  title: string
  year: number
  tsr_number: string
  era: string
  enabled: boolean
  citation_only: boolean
  notes: string | null
}

interface RuleCardRow {
  kind: string
  id: number
  name: string
  source_code: string | null
  effect_summary: string
  citation_work: string | null
  citation_year: number | null
  citation_pages: string | null
  citation_topic: string | null
  user_verified: boolean
}

interface CitationRow {
  id: number
  work: string
  year: number | null
  pages: string | null
  topic: string
  source_code: string | null
}

interface TableLawRow {
  death_mode: string
  dual_class_house_switch: {
    enabled: boolean
    human_only: boolean
    apply_phb_xp_penalties: boolean
    min_original_level: number
    resume_new_level: number
  }
}

interface Props {
  sources: RuleSourceRow[]
  table_law: TableLawRow
  cards: RuleCardRow[]
  citations: CitationRow[]
  fgg_opt_in: boolean
  fgg_allowed: boolean
  ogl_row: boolean
  ingest_allowed: string[]
  death_mode: string
}

const ERA_LABEL: Record<string, string> = {
  core_1989: '1989 core',
  kit: 'Kit books',
  race: 'Race books',
  monstrous: 'Monstrous (live FR MC)',
  extra_2e: 'Extra 2E packs',
  later_2e: 'Later 2E / Player\'s Option / 1995 revised',
}

export default function Rules({
  sources,
  table_law,
  cards,
  citations,
  fgg_opt_in,
  fgg_allowed,
  ogl_row,
  ingest_allowed,
  death_mode,
}: Props) {
  const { flash } = usePage<PageProps>().props
  const [eraFilter, setEraFilter] = useState<string>('all')

  const grouped = useMemo(() => {
    const map: Record<string, RuleSourceRow[]> = {}
    for (const row of sources) {
      if (eraFilter !== 'all' && row.era !== eraFilter) continue
      map[row.era] = map[row.era] ?? []
      map[row.era].push(row)
    }
    return map
  }, [sources, eraFilter])

  const cardForm = useForm({
    kind: 'kit',
    name: '',
    source_code: '',
    effect_summary: '',
    citation_work: '',
    citation_year: '',
    citation_pages: '',
    citation_topic: '',
    user_verified: false as boolean,
  })

  const citationForm = useForm({
    work: '',
    year: '',
    pages: '',
    topic: '',
    source_code: '',
  })

  const toggleSource = (code: string, enabled: boolean) => {
    router.post(`/settings/rules/sources/${encodeURIComponent(code)}`, { enabled: enabled ? 1 : 0 }, {
      preserveScroll: true,
    })
  }

  return (
    <AppLayout
      title="Table Law and Sources"
      breadcrumbs={[{ label: 'Settings', href: '/settings' }, { label: 'Table Law' }]}
    >
      <Head title="Table Law and Sources" />

      <div className="max-w-3xl mx-auto pb-16">
        <h1 className="font-heading text-2xl text-[var(--color-text-white)] tracking-widest uppercase mb-2">
          Table Law and Sources
        </h1>
        <p className="text-xs text-[var(--color-text-dim)] leading-relaxed mb-6">
          Bibliographic catalog only. Fill cards with the book open. This app does not ingest official prose, PDFs, OCR, or RAG.
        </p>
        {flash?.success && (
          <p className="text-xs text-[var(--color-success)] mb-4">{flash.success}</p>
        )}

        <RuneDivider label="Table law" />
        <div
          className="rounded border p-4 mb-8 flex flex-col gap-2"
          style={{ background: 'var(--color-deep)', borderColor: 'var(--color-border)' }}
        >
          <p className="text-sm text-[var(--color-text-base)]">
            Death mode is <code className="text-[var(--color-arcane)]">{death_mode}</code>. At 0 hit points the character is slain. There is no dying band below 0.
          </p>
          <p className="text-sm text-[var(--color-text-base)]">
            Dual-class house switch is TABLE LAW, not 1989 PHB core. Begin a new class at {table_law.dual_class_house_switch.min_original_level}th in the original. Switch back when the new class is {table_law.dual_class_house_switch.resume_new_level}th. Not human-only. PHB dual-class XP penalties are not applied.
          </p>
        </div>

        <RuneDivider label="Ingest allowlist" />
        <p className="text-xs text-[var(--color-text-dim)] leading-relaxed mb-3">
          Allowed now: {ingest_allowed.join(', ') || 'none'}. Official PDF, OCR, RAG, and handbook prose stay forbidden.
        </p>
        <form
          onSubmit={e => {
            e.preventDefault()
            router.post('/settings/rules/fgg', { opt_in: fgg_opt_in ? 0 : 1 }, { preserveScroll: true })
          }}
          className="mb-8 flex flex-col gap-2"
        >
          <p className="text-sm text-[var(--color-text-base)]">
            FG&G (fan-generated and OGL) is {fgg_allowed ? 'on' : 'off'}. OGL row on file: {ogl_row ? 'yes' : 'no'}.
          </p>
          <Button type="submit" variant="ghost" size="sm">
            {fgg_opt_in ? 'Turn FG&G off' : 'Opt in to FG&G'}
          </Button>
        </form>

        <RuneDivider label="Source catalog" />
        <p className="text-xs text-[var(--color-text-dim)] leading-relaxed mb-3">
          Live core starts enabled (1989 PHB/DMG, kit books, race books, MC1/MC2/MC3 FR, MC11 FR II). Extra 2E packs start off. Toggle a pack to name it in LOOKUP. Still no official prose ingest.
        </p>
        <div className="flex gap-2 mb-4 flex-wrap">
          {['all', 'core_1989', 'kit', 'race', 'monstrous', 'extra_2e', 'later_2e'].map(era => (
            <button
              key={era}
              type="button"
              onClick={() => setEraFilter(era)}
              className="px-2 py-1 rounded border text-[10px] uppercase tracking-widest"
              style={{
                borderColor: eraFilter === era ? 'var(--color-rune)' : 'var(--color-border)',
                color: eraFilter === era ? 'var(--color-rune-bright)' : 'var(--color-text-dim)',
              }}
            >
              {era === 'all' ? 'All' : (ERA_LABEL[era] ?? era)}
            </button>
          ))}
        </div>
        <div className="flex flex-col gap-5 mb-10">
          {Object.entries(grouped).map(([era, rows]) => (
            <div key={era}>
              <p className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)] mb-2">
                {ERA_LABEL[era] ?? era}
              </p>
              <div className="flex flex-col gap-2">
                {rows.map(row => (
                  <div
                    key={row.code}
                    className="rounded border p-3 flex items-start gap-3"
                    style={{ background: 'var(--color-deep)', borderColor: 'var(--color-border)' }}
                  >
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-heading text-sm text-[var(--color-text-white)]">{row.code}</span>
                        <Badge variant={row.enabled ? 'success' : 'muted'}>{row.enabled ? 'On' : 'Off'}</Badge>
                        {row.citation_only && <Badge variant="warning">Citation only</Badge>}
                      </div>
                      <p className="text-xs text-[var(--color-text-base)] mt-1">
                        {row.title} ({row.year}) TSR {row.tsr_number}
                      </p>
                      {row.notes && (
                        <p className="text-[10px] text-[var(--color-text-dim)] mt-1 leading-relaxed">{row.notes}</p>
                      )}
                    </div>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => toggleSource(row.code, !row.enabled)}
                    >
                      {row.enabled ? 'Disable' : 'Enable'}
                    </Button>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>

        <RuneDivider label="Cards" />
        <p className="text-xs text-[var(--color-text-dim)] leading-relaxed mb-3">
          Short original summary only (280 characters). There is no paste-from-book box. Open the physical book. Mark user verified when you have checked your own notes.
        </p>
        <form
          onSubmit={e => {
            e.preventDefault()
            cardForm.post('/settings/rules/cards', { preserveScroll: true, onSuccess: () => cardForm.reset() })
          }}
          className="flex flex-col gap-3 mb-6"
        >
          <Select label="Kind" value={cardForm.data.kind} onChange={e => cardForm.setData('kind', e.target.value)}>
            <option value="spell">Spell</option>
            <option value="monster">Monster</option>
            <option value="kit">Kit</option>
            <option value="racial_option">Racial option</option>
          </Select>
          <Input label="Name" value={cardForm.data.name} onChange={e => cardForm.setData('name', e.target.value)} maxLength={120} />
          <Select
            label="Source"
            value={cardForm.data.source_code}
            onChange={e => cardForm.setData('source_code', e.target.value)}
          >
            <option value="">None</option>
            {sources.map(s => (
              <option key={s.code} value={s.code}>{s.code}: {s.title}</option>
            ))}
          </Select>
          <Input
            label="Your short summary"
            value={cardForm.data.effect_summary}
            onChange={e => cardForm.setData('effect_summary', e.target.value)}
            maxLength={280}
            hint="Your own wording. Do not paste book text."
          />
          <Input label="Citation work" value={cardForm.data.citation_work} onChange={e => cardForm.setData('citation_work', e.target.value)} />
          <Input label="Citation year" value={cardForm.data.citation_year} onChange={e => cardForm.setData('citation_year', e.target.value)} />
          <Input label="Citation pages" value={cardForm.data.citation_pages} onChange={e => cardForm.setData('citation_pages', e.target.value)} hint="Leave blank if unknown." />
          <Input label="Citation topic" value={cardForm.data.citation_topic} onChange={e => cardForm.setData('citation_topic', e.target.value)} />
          <label className="flex items-center gap-2 text-xs text-[var(--color-text-dim)]">
            <input
              type="checkbox"
              checked={cardForm.data.user_verified}
              onChange={e => cardForm.setData('user_verified', e.target.checked)}
            />
            I verified this from the book (my notes)
          </label>
          <Button type="submit" variant="rune" size="sm" disabled={cardForm.processing}>Save card</Button>
        </form>
        <div className="flex flex-col gap-2 mb-10">
          {cards.length === 0 && (
            <p className="text-xs text-[var(--color-text-dim)]">No cards yet. Stubs appear after migrate.</p>
          )}
          {cards.map(card => (
            <div key={`${card.kind}-${card.id}`} className="rounded border p-3" style={{ borderColor: 'var(--color-border)' }}>
              <p className="text-xs font-heading text-[var(--color-text-white)]">
                {card.kind}: {card.name} {card.source_code ? `(${card.source_code})` : ''}
              </p>
              <p className="text-xs text-[var(--color-text-dim)] mt-1">{card.effect_summary}</p>
            </div>
          ))}
        </div>

        <RuneDivider label="Citations" />
        <p className="text-xs text-[var(--color-text-dim)] leading-relaxed mb-3">
          Work, year, pages, topic. Bibliographic index only. Pages may stay unknown.
        </p>
        <form
          onSubmit={e => {
            e.preventDefault()
            citationForm.post('/settings/rules/citations', { preserveScroll: true, onSuccess: () => citationForm.reset() })
          }}
          className="flex flex-col gap-3 mb-6"
        >
          <Input label="Work" value={citationForm.data.work} onChange={e => citationForm.setData('work', e.target.value)} />
          <Input label="Year" value={citationForm.data.year} onChange={e => citationForm.setData('year', e.target.value)} />
          <Input label="Pages" value={citationForm.data.pages} onChange={e => citationForm.setData('pages', e.target.value)} hint="Unknown is fine." />
          <Input label="Topic" value={citationForm.data.topic} onChange={e => citationForm.setData('topic', e.target.value)} />
          <Select
            label="Source code"
            value={citationForm.data.source_code}
            onChange={e => citationForm.setData('source_code', e.target.value)}
          >
            <option value="">None</option>
            {sources.map(s => (
              <option key={s.code} value={s.code}>{s.code}</option>
            ))}
          </Select>
          <Button type="submit" variant="rune" size="sm" disabled={citationForm.processing}>Save citation</Button>
        </form>
        <div className="flex flex-col gap-2">
          {citations.map(row => (
            <p key={row.id} className="text-xs text-[var(--color-text-dim)]">
              {row.work}{row.year ? ` (${row.year})` : ''} {row.pages ? `p. ${row.pages}` : 'pages unknown'}: {row.topic}
            </p>
          ))}
        </div>
      </div>
    </AppLayout>
  )
}
