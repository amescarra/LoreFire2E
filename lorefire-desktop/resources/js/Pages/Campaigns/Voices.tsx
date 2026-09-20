import React, { useRef, useState } from 'react'
import { Head, router, useForm } from '@inertiajs/react'
import AppLayout from '@/Layouts/AppLayout'
import { Card, CardHeader } from '@/Components/Card'
import { Badge } from '@/Components/Badge'
import { Button } from '@/Components/Button'
import { Campaign, CampaignVoiceprint, Character } from '@/types'

interface Props {
  campaign: Campaign
  characters: Character[]
  voiceprints: CampaignVoiceprint[]
}

export default function Voices({ campaign, characters, voiceprints }: Props) {
  const { data, setData, post, processing, reset, errors } = useForm({
    display_name: '',
    character_id: '' as string | number | '',
    is_dm: false as boolean,
    audio: null as File | null,
  })

  const audioRef = useRef<HTMLInputElement>(null)

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    post(`/campaigns/${campaign.id}/voiceprints`, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => {
        reset()
        if (audioRef.current) audioRef.current.value = ''
      },
    })
  }

  return (
    <AppLayout breadcrumbs={[
      { label: 'Campaigns', href: '/campaigns' },
      { label: campaign.name, href: `/campaigns/${campaign.id}` },
      { label: 'Voices' },
    ]}>
      <Head title={`${campaign.name} — Voices`} />

      <div className="max-w-3xl mx-auto flex flex-col gap-6">
        <div>
          <h1 className="font-heading text-2xl text-[var(--color-text-white)] tracking-widest uppercase">
            Enrolled Voices
          </h1>
          <p className="text-sm text-[var(--color-text-dim)] mt-2 max-w-xl leading-relaxed">
            Voiceprints persist across sessions. pyannote still emits fresh SPEAKER_00 labels
            each recording — embeddings match those labels to the names below when confidence is high.
          </p>
          <p className="text-xs text-[var(--color-text-dim)] mt-2 max-w-xl leading-relaxed">
            The same physical person can have two profiles. For Suor Noir, Shaun is
            {' '}<span className="text-[var(--color-arcane)]">Elayas</span> (PC) and
            {' '}<span className="text-[var(--color-rune-bright)]">Dungeon Master</span> (DM lines).
            Do not collapse those into one voice.
          </p>
        </div>

        <Card>
          <CardHeader title="Add or enroll a voice" subtitle="Dedicated enrollment recording, or metadata now and audio later" />
          <form onSubmit={submit} className="flex flex-col gap-3">
            <div className="flex flex-wrap items-end gap-3">
              <label className="flex flex-col gap-0.5">
                <span className="text-[10px] text-[var(--color-text-dim)] uppercase tracking-wide">Display name</span>
                <input
                  type="text"
                  value={data.display_name}
                  onChange={e => setData('display_name', e.target.value)}
                  placeholder="Elayas"
                  className="h-8 px-2 text-xs bg-[var(--color-bg)] border border-[var(--color-border)] rounded text-[var(--color-text-base)] w-40"
                />
              </label>
              <label className="flex flex-col gap-0.5">
                <span className="text-[10px] text-[var(--color-text-dim)] uppercase tracking-wide">Character</span>
                <select
                  value={data.character_id}
                  onChange={e => setData('character_id', e.target.value)}
                  className="h-8 px-2 text-xs bg-[var(--color-bg)] border border-[var(--color-border)] rounded text-[var(--color-text-base)] w-40"
                >
                  <option value="">— none —</option>
                  {characters.map(c => (
                    <option key={c.id} value={c.id}>{c.name}</option>
                  ))}
                </select>
              </label>
              <label className="flex items-center gap-1.5 pb-1 cursor-pointer">
                <input
                  type="checkbox"
                  checked={data.is_dm}
                  onChange={e => setData('is_dm', e.target.checked)}
                  className="w-3 h-3 accent-[var(--color-rune)]"
                />
                <span className="text-[10px] text-[var(--color-text-dim)] uppercase tracking-wide">Dungeon Master</span>
              </label>
              <label className="flex flex-col gap-0.5">
                <span className="text-[10px] text-[var(--color-text-dim)] uppercase tracking-wide">Enrollment audio</span>
                <input
                  ref={audioRef}
                  type="file"
                  accept="audio/*,.webm,.wav,.ogg,.mp3"
                  onChange={e => setData('audio', e.target.files?.[0] ?? null)}
                  className="text-[10px] text-[var(--color-text-dim)] w-48"
                />
              </label>
              <Button variant="rune" size="sm" type="submit" disabled={processing || !data.display_name.trim()}>
                {processing ? 'Saving…' : 'Save voice'}
              </Button>
            </div>
            {errors.display_name && <p className="text-[10px] text-[var(--color-danger)]">{errors.display_name}</p>}
            {errors.audio && <p className="text-[10px] text-[var(--color-danger)]">{errors.audio}</p>}
          </form>
        </Card>

        {voiceprints.length === 0 ? (
          <Card>
            <p className="text-sm text-[var(--color-text-dim)]">
              No campaign voiceprints yet. Identify speakers on a session, then use
              “Save voices for this campaign”.
            </p>
          </Card>
        ) : (
          <div className="flex flex-col gap-3">
            {voiceprints.map(vp => (
              <VoiceprintRow key={vp.id} voiceprint={vp} campaignId={campaign.id} characters={characters} />
            ))}
          </div>
        )}
      </div>
    </AppLayout>
  )
}

function VoiceprintRow({
  voiceprint,
  campaignId,
  characters,
}: {
  voiceprint: CampaignVoiceprint
  campaignId: number
  characters: Character[]
}) {
  const [editing, setEditing] = useState(false)
  const [name, setName] = useState(voiceprint.display_name)
  const [characterId, setCharacterId] = useState(voiceprint.character_id ? String(voiceprint.character_id) : '')
  const [isDm, setIsDm] = useState(voiceprint.is_dm)
  const enrollRef = useRef<HTMLInputElement>(null)

  const save = () => {
    router.patch(`/campaigns/${campaignId}/voiceprints/${voiceprint.id}`, {
      display_name: name.trim(),
      character_id: characterId ? Number(characterId) : '',
      is_dm: isDm ? 1 : 0,
    }, {
      preserveScroll: true,
      onSuccess: () => setEditing(false),
    })
  }

  const enroll = (file: File) => {
    router.post(`/campaigns/${campaignId}/voiceprints/${voiceprint.id}/enroll`, {
      audio: file,
    }, {
      forceFormData: true,
      preserveScroll: true,
    })
  }

  return (
    <Card>
      <div className="flex flex-col gap-3">
        <div className="flex items-start justify-between gap-3">
          <div>
            <div className="flex items-center gap-2 flex-wrap">
              <span className="font-heading text-sm text-[var(--color-text-white)] tracking-wide">
                {voiceprint.display_name}
              </span>
              {voiceprint.is_dm && <Badge variant="arcane">Dungeon Master</Badge>}
              {voiceprint.has_embedding
                ? <Badge variant="success">Voiceprint ready</Badge>
                : <Badge variant="warning">Needs enrollment audio</Badge>}
            </div>
            <p className="text-[10px] text-[var(--color-text-dim)] mt-1">
              {voiceprint.character?.name ? `Linked to ${voiceprint.character.name}` : 'No character link'}
              {voiceprint.enrolled_at ? ` · enrolled ${new Date(voiceprint.enrolled_at).toLocaleDateString()}` : ''}
            </p>
          </div>
          <div className="flex gap-2 shrink-0">
            <Button variant="ghost" size="sm" onClick={() => setEditing(e => !e)}>
              {editing ? 'Cancel' : 'Edit'}
            </Button>
            <Button variant="ghost" size="sm" onClick={() => enrollRef.current?.click()}>
              Re-enroll
            </Button>
            <Button
              variant="danger"
              size="sm"
              onClick={() => {
                if (!confirm(`Delete voice “${voiceprint.display_name}”?`)) return
                router.delete(`/campaigns/${campaignId}/voiceprints/${voiceprint.id}`, { preserveScroll: true })
              }}
            >
              Delete
            </Button>
          </div>
        </div>

        <input
          ref={enrollRef}
          type="file"
          accept="audio/*,.webm,.wav,.ogg,.mp3"
          className="hidden"
          onChange={e => {
            const file = e.target.files?.[0]
            if (file) enroll(file)
            e.target.value = ''
          }}
        />

        {editing && (
          <div className="flex flex-wrap items-end gap-2 border-t border-[var(--color-border)] pt-3">
            <label className="flex flex-col gap-0.5">
              <span className="text-[10px] text-[var(--color-text-dim)] uppercase tracking-wide">Name</span>
              <input
                type="text"
                value={name}
                onChange={e => setName(e.target.value)}
                className="h-7 px-2 text-xs bg-[var(--color-bg)] border border-[var(--color-border)] rounded text-[var(--color-text-base)] w-36"
              />
            </label>
            <label className="flex flex-col gap-0.5">
              <span className="text-[10px] text-[var(--color-text-dim)] uppercase tracking-wide">Character</span>
              <select
                value={characterId}
                onChange={e => setCharacterId(e.target.value)}
                className="h-7 px-2 text-xs bg-[var(--color-bg)] border border-[var(--color-border)] rounded text-[var(--color-text-base)] w-36"
              >
                <option value="">— none —</option>
                {characters.map(c => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </label>
            <label className="flex items-center gap-1.5 pb-1 cursor-pointer">
              <input type="checkbox" checked={isDm} onChange={e => setIsDm(e.target.checked)} className="w-3 h-3 accent-[var(--color-rune)]" />
              <span className="text-[10px] text-[var(--color-text-dim)] uppercase tracking-wide">DM</span>
            </label>
            <Button variant="rune" size="sm" onClick={save} disabled={!name.trim()}>Save</Button>
          </div>
        )}
      </div>
    </Card>
  )
}
