import React, { useState, useRef, useEffect, useCallback } from 'react'
import { router } from '@inertiajs/react'
import { Button } from '@/Components/Button'
import { CampaignVoiceprint, Character, SpeakerProfile } from '@/types'

export interface TranscriptSegment {
  start: number
  end: number
  text: string
  speaker?: string        // resolved display name
  speaker_label?: string  // current SPEAKER_XX label
  speaker_is_dm?: boolean
  speaker_diarized?: string
  segment_index?: number
}

interface SpeakerClipWindow {
  start: number
  end: number
}

// Keep in sync with App\Support\SpeakerClipWindows
const CLIP_MAX_SEGMENTS = 3
const CLIP_MAX_SECONDS = 25
const CLIP_MAX_SEGMENT_SECONDS = 12
const CLIP_MIN_SEGMENT_SECONDS = 0.35

export function clipWindowsForLabel(segments: TranscriptSegment[], label: string): SpeakerClipWindow[] {
  const windows: SpeakerClipWindow[] = []
  let total = 0
  for (const seg of segments) {
    const speaker = seg.speaker_label || seg.speaker
    if (speaker !== label) continue
    const start = Number(seg.start) || 0
    const end = Number(seg.end) || start
    const duration = end - start
    if (duration < CLIP_MIN_SEGMENT_SECONDS) continue
    if (windows.length >= CLIP_MAX_SEGMENTS) break
    const remaining = CLIP_MAX_SECONDS - total
    if (remaining < CLIP_MIN_SEGMENT_SECONDS) break
    const take = Math.min(duration, remaining, CLIP_MAX_SEGMENT_SECONDS)
    windows.push({ start, end: start + take })
    total += take
  }
  return windows
}

function clipDuration(windows: SpeakerClipWindow[]): number {
  return windows.reduce((sum, w) => sum + Math.max(0, w.end - w.start), 0)
}

function fmtClipTime(seconds: number): string {
  const secs = Math.max(0, Math.floor(seconds))
  return `${Math.floor(secs / 60)}:${String(secs % 60).padStart(2, '0')}`
}

let stopActiveSpeakerClip: (() => void) | null = null

export function SpeakerClipPlayer({
  sessionId,
  label,
  hasAudio,
  windows,
}: {
  sessionId: number
  label: string
  hasAudio: boolean
  windows: SpeakerClipWindow[]
}) {
  const audioRef = useRef<HTMLAudioElement | null>(null)
  const stopRef = useRef<() => void>(() => {})
  const [playing, setPlaying] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [current, setCurrent] = useState(0)
  const estimated = clipDuration(windows)
  const [duration, setDuration] = useState(estimated)

  const src = `/sessions/${sessionId}/speakers/${encodeURIComponent(label)}/clip`

  const stop = useCallback(() => {
    const audio = audioRef.current
    if (audio) {
      audio.pause()
      audio.currentTime = 0
    }
    setPlaying(false)
    setCurrent(0)
    setLoading(false)
    if (stopActiveSpeakerClip === stopRef.current) {
      stopActiveSpeakerClip = null
    }
  }, [])

  stopRef.current = stop

  useEffect(() => {
    return () => {
      stopRef.current()
      const audio = audioRef.current
      if (audio) {
        audio.removeAttribute('src')
        audio.load()
      }
      audioRef.current = null
    }
  }, [])

  const ensureAudio = () => {
    if (audioRef.current) return audioRef.current
    const audio = new Audio()
    audio.preload = 'auto'
    audio.src = src
    audio.addEventListener('timeupdate', () => setCurrent(audio.currentTime))
    audio.addEventListener('durationchange', () => {
      if (Number.isFinite(audio.duration) && audio.duration > 0) {
        setDuration(audio.duration)
      }
    })
    audio.addEventListener('ended', () => {
      setPlaying(false)
      setCurrent(0)
    })
    audio.addEventListener('error', () => {
      setError('Could not play this speaker clip.')
      setPlaying(false)
      setLoading(false)
    })
    audio.addEventListener('canplay', () => setLoading(false))
    audioRef.current = audio
    return audio
  }

  const playFrom = async (offset = 0) => {
    if (!hasAudio || windows.length === 0) return
    setError(null)
    if (stopActiveSpeakerClip && stopActiveSpeakerClip !== stopRef.current) {
      stopActiveSpeakerClip()
    }
    const audio = ensureAudio()
    setLoading(true)
    try {
      if (offset > 0) audio.currentTime = offset
      await audio.play()
      setPlaying(true)
      setLoading(false)
      stopActiveSpeakerClip = stopRef.current
    } catch {
      setError('Could not play this speaker clip.')
      setPlaying(false)
      setLoading(false)
    }
  }

  const toggle = () => {
    if (playing) stop()
    else void playFrom(current > 0.15 ? current : 0)
  }

  const seek = (time: number) => {
    const audio = ensureAudio()
    audio.currentTime = time
    setCurrent(time)
  }

  const disabled = !hasAudio || windows.length === 0
  const shownDuration = duration || estimated

  return (
    <div className="flex flex-col gap-1.5" data-testid="speaker-clip-player">
      <div className="flex items-center gap-2">
        <Button
          variant={playing ? 'danger' : 'ghost'}
          size="sm"
          onClick={toggle}
          disabled={disabled || loading}
          data-testid="speaker-clip-play"
          title={!hasAudio ? 'No session audio to play' : windows.length === 0 ? 'No timed speech for this label' : undefined}
        >
          {loading ? 'Loading…' : playing ? 'Stop' : 'Play'}
        </Button>
        <span className="text-[10px] font-mono text-[var(--color-text-dim)]" data-testid="speaker-clip-time">
          {fmtClipTime(current)} / {fmtClipTime(shownDuration)}
        </span>
      </div>
      <input
        type="range"
        min={0}
        max={Math.max(shownDuration, 0.1)}
        step={0.1}
        value={Math.min(current, shownDuration)}
        disabled={disabled}
        onChange={e => seek(Number(e.target.value))}
        className="w-full accent-[var(--color-rune)] h-1.5 cursor-pointer"
        data-testid="speaker-clip-scrub"
        aria-label={`Scrub ${label} clip`}
      />
      {error && <p className="text-[10px] text-[var(--color-danger)]">{error}</p>}
      {!hasAudio && (
        <p className="text-[10px] text-[var(--color-text-dim)] italic">Session audio is not available for playback.</p>
      )}
    </div>
  )
}

function wordHitsName(text: string, name: string): boolean {
  const n = name.trim().toLowerCase()
  if (n.length < 3) return false
  const padded = ` ${text.toLowerCase()} `
  return (
    padded.includes(` ${n} `) ||
    padded.includes(` ${n}.`) ||
    padded.includes(` ${n},`) ||
    padded.includes(` ${n}'`) ||
    padded.includes(` ${n}!`) ||
    padded.includes(` ${n}?`)
  )
}

export function labelLooksHeterogeneous(
  segments: TranscriptSegment[],
  label: string,
  names: string[],
): boolean {
  const samples = segments.filter(s => s.speaker_label === label).slice(0, 8)
  const found = new Set<string>()
  for (const seg of samples) {
    for (const name of names) {
      if (wordHitsName(seg.text, name)) found.add(name.trim().toLowerCase())
    }
  }
  return found.size >= 2
}

function uniqueNames(characters: Character[], voiceprints: CampaignVoiceprint[], profiles: SpeakerProfile[]): string[] {
  const names = new Set<string>()
  characters.forEach(c => {
    if (c.name) names.add(c.name)
    if (c.player_name) names.add(c.player_name)
  })
  voiceprints.forEach(v => {
    if (v.display_name) names.add(v.display_name)
  })
  profiles.forEach(p => {
    if (p.display_name) names.add(p.display_name)
  })
  return Array.from(names)
}

function segmentIndex(seg: TranscriptSegment, fallback: number): number {
  return typeof seg.segment_index === 'number' ? seg.segment_index : fallback
}

interface RemapPayload {
  segment_indexes: number[]
  speaker_label?: string
  create_new_label?: number
  speaker_profile_id?: number
  campaign_voiceprint_id?: number
  display_name?: string
  character_id?: number | ''
  is_dm?: number
  save_to_campaign?: number
}

function submitRemap(sessionId: number, payload: RemapPayload, onDone: (ok: boolean) => void) {
  router.post(`/sessions/${sessionId}/speakers/remap`, payload, {
    preserveScroll: true,
    onSuccess: () => onDone(true),
    onError: () => onDone(false),
  })
}

export function SpeakerRemapDialog({
  sessionId,
  segmentIndexes,
  currentLabel,
  speakerProfiles,
  campaignVoiceprints,
  characters,
  transcriptSegments,
  onClose,
}: {
  sessionId: number
  segmentIndexes: number[]
  currentLabel?: string
  speakerProfiles: SpeakerProfile[]
  campaignVoiceprints: CampaignVoiceprint[]
  characters: Character[]
  transcriptSegments: TranscriptSegment[]
  onClose: () => void
}) {
  const [destination, setDestination] = useState('new')
  const [displayName, setDisplayName] = useState('')
  const [characterId, setCharacterId] = useState('')
  const [isDm, setIsDm] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const labelsInTranscript = Array.from(
    new Set(
      transcriptSegments
        .map(s => s.speaker_label)
        .filter((l): l is string => !!l && /^SPEAKER_\d+$/.test(l))
    )
  ).sort()

  const profileByLabel = new Map(speakerProfiles.map(p => [p.speaker_label, p]))

  const selectedPreview = segmentIndexes
    .map(i => transcriptSegments.find(s => segmentIndex(s, -1) === i) ?? transcriptSegments[i])
    .filter((s): s is TranscriptSegment => !!s)
    .slice(0, 4)

  const apply = () => {
    if (segmentIndexes.length === 0) {
      setError('Select at least one line.')
      return
    }
    const payload: RemapPayload = { segment_indexes: segmentIndexes }
    if (destination === 'new') {
      payload.create_new_label = 1
    } else if (destination === 'named') {
      if (!displayName.trim() && !characterId && !isDm) {
        setError('Name, character, or DM is required for a named split.')
        return
      }
      payload.create_new_label = 1
      if (displayName.trim()) payload.display_name = displayName.trim()
      if (characterId) payload.character_id = Number(characterId)
      payload.is_dm = isDm ? 1 : 0
    } else if (destination === 'dm') {
      payload.create_new_label = 1
      payload.is_dm = 1
      payload.display_name = 'Dungeon Master'
    } else if (destination.startsWith('label:')) {
      payload.speaker_label = destination.slice(6)
    } else if (destination.startsWith('profile:')) {
      payload.speaker_profile_id = Number(destination.slice(8))
    } else if (destination.startsWith('voiceprint:')) {
      payload.campaign_voiceprint_id = Number(destination.slice(11))
    } else if (destination.startsWith('character:')) {
      const id = Number(destination.slice(10))
      const character = characters.find(c => c.id === id)
      payload.create_new_label = 1
      payload.character_id = id
      payload.display_name = character?.name ?? displayName.trim()
    } else {
      setError('Choose a destination.')
      return
    }

    if (payload.speaker_label && payload.speaker_label === currentLabel && segmentIndexes.length > 0) {
      setError('Those lines are already on that label.')
      return
    }

    setSaving(true)
    setError(null)
    submitRemap(sessionId, payload, ok => {
      setSaving(false)
      if (ok) onClose()
      else setError('Could not move those lines. Try again.')
    })
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60" data-testid="speaker-remap-dialog">
      <div
        className="w-full max-w-md mx-4 rounded border border-[var(--color-border)] p-5 flex flex-col gap-3"
        style={{ background: 'var(--color-bg)' }}
      >
        <h2 className="font-heading text-sm text-[var(--color-text-white)] tracking-widest uppercase">
          Change speaker
        </h2>
        <p className="text-[11px] text-[var(--color-text-dim)]">
          Move {segmentIndexes.length} selected line{segmentIndexes.length !== 1 ? 's' : ''}
          {currentLabel ? ` off ${currentLabel}` : ''} without renaming every line on that label.
        </p>

        {selectedPreview.length > 0 && (
          <div className="flex flex-col gap-1 pl-2 border-l-2 border-[var(--color-border)]">
            {selectedPreview.map((seg, i) => (
              <p key={i} className="text-[10px] text-[var(--color-text-dim)] italic leading-relaxed">
                “{seg.text.trim()}”
              </p>
            ))}
          </div>
        )}

        <label className="flex flex-col gap-0.5">
          <span className="text-[10px] text-[var(--color-text-dim)] uppercase tracking-wide">Move to</span>
          <select
            value={destination}
            onChange={e => setDestination(e.target.value)}
            className="h-8 px-2 text-xs bg-[var(--color-bg)] border border-[var(--color-border)] rounded text-[var(--color-text-base)] focus:outline-none focus:border-[var(--color-rune)]"
            data-testid="speaker-remap-destination"
          >
            <option value="new">New unlabeled speaker (split)</option>
            <option value="named">New named speaker…</option>
            <option value="dm">Dungeon Master (new label)</option>
            {labelsInTranscript.map(label => {
              const profile = profileByLabel.get(label)
              const suffix = profile ? ` — ${profile.display_name}` : ' (unidentified)'
              return (
                <option key={label} value={`label:${label}`}>
                  {label}{suffix}
                </option>
              )
            })}
            {campaignVoiceprints.map(vp => (
              <option key={`vp-${vp.id}`} value={`voiceprint:${vp.id}`}>
                Campaign voice: {vp.display_name}{vp.is_dm ? ' (DM)' : ''}
              </option>
            ))}
            {characters.map(c => (
              <option key={`c-${c.id}`} value={`character:${c.id}`}>
                Character: {c.name}
              </option>
            ))}
          </select>
        </label>

        {destination === 'named' && (
          <div className="flex flex-wrap items-end gap-2">
            <div className="flex flex-col gap-0.5">
              <label className="text-[10px] text-[var(--color-text-dim)] uppercase tracking-wide">Name</label>
              <input
                type="text"
                value={displayName}
                onChange={e => setDisplayName(e.target.value)}
                placeholder="e.g. Justin"
                className="h-7 px-2 text-xs bg-[var(--color-bg)] border border-[var(--color-border)] rounded text-[var(--color-text-base)] w-32"
              />
            </div>
            {characters.length > 0 && (
              <div className="flex flex-col gap-0.5">
                <label className="text-[10px] text-[var(--color-text-dim)] uppercase tracking-wide">Character</label>
                <select
                  value={characterId}
                  onChange={e => setCharacterId(e.target.value)}
                  className="h-7 px-2 text-xs bg-[var(--color-bg)] border border-[var(--color-border)] rounded text-[var(--color-text-base)] w-32"
                >
                  <option value="">— none —</option>
                  {characters.map(c => (
                    <option key={c.id} value={c.id}>{c.name}</option>
                  ))}
                </select>
              </div>
            )}
            <label className="flex items-center gap-1.5 cursor-pointer pb-0.5">
              <input type="checkbox" checked={isDm} onChange={e => setIsDm(e.target.checked)} className="w-3 h-3 accent-[var(--color-rune)]" />
              <span className="text-[10px] text-[var(--color-text-dim)] uppercase">DM</span>
            </label>
          </div>
        )}

        {error && <p className="text-[10px] text-[var(--color-danger)]">{error}</p>}

        <div className="flex gap-2 justify-end">
          <Button variant="ghost" size="sm" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant="rune" size="sm" onClick={apply} disabled={saving} data-testid="speaker-remap-apply">
            {saving ? 'Moving…' : 'Move lines'}
          </Button>
        </div>
      </div>
    </div>
  )
}

interface SpeakerRowState {
  displayName: string
  characterId: string
  isDm: boolean
  saveToCampaign: boolean
  voiceprintId: string
  saving: boolean
  saved: boolean
  error: string | null
}

export function SpeakerIdentificationPanel({
  unresolvedLabels,
  transcriptSegments,
  characters,
  speakerProfiles,
  campaignVoiceprints,
  sessionId,
  hasAudio,
}: {
  unresolvedLabels: string[]
  transcriptSegments: TranscriptSegment[]
  characters: Character[]
  speakerProfiles: SpeakerProfile[]
  campaignVoiceprints: CampaignVoiceprint[]
  sessionId: number
  hasAudio: boolean
}) {
  const initial: Record<string, SpeakerRowState> = {}
  unresolvedLabels.forEach(label => {
    initial[label] = { displayName: '', characterId: '', isDm: false, saveToCampaign: true, voiceprintId: '', saving: false, saved: false, error: null }
  })
  const [rows, setRows] = useState<Record<string, SpeakerRowState>>(initial)
  const [selected, setSelected] = useState<Record<string, number[]>>({})
  const [expanded, setExpanded] = useState<Record<string, boolean>>({})
  const [remap, setRemap] = useState<{ indexes: number[]; label: string } | null>(null)

  const setRow = (label: string, patch: Partial<SpeakerRowState>) => {
    setRows(prev => ({ ...prev, [label]: { ...prev[label], ...patch } }))
  }

  const save = (label: string) => {
    const row = rows[label]
    const voiceprint = campaignVoiceprints.find(v => String(v.id) === row.voiceprintId)
    const displayName = row.displayName.trim() || voiceprint?.display_name || ''
    if (!displayName) {
      setRow(label, { error: 'Name is required' })
      return
    }
    setRow(label, { saving: true, error: null })

    router.post(`/sessions/${sessionId}/speakers`, {
      speaker_label: label,
      display_name: displayName,
      is_dm: (row.isDm || voiceprint?.is_dm) ? 1 : 0,
      character_id: row.characterId ? Number(row.characterId) : (voiceprint?.character_id ?? ''),
      campaign_voiceprint_id: row.voiceprintId ? Number(row.voiceprintId) : '',
      save_to_campaign: row.saveToCampaign ? 1 : 0,
    }, {
      preserveScroll: true,
      onSuccess: () => {
        setRow(label, { saving: false, saved: true })
      },
      onError: () => {
        setRow(label, { saving: false, error: 'Failed to save. Try again.' })
      },
    })
  }

  const linesFor = (label: string) =>
    transcriptSegments
      .map((s, i) => ({ seg: s, index: segmentIndex(s, i) }))
      .filter(({ seg }) => seg.speaker_label === label)

  const toggleLine = (label: string, index: number) => {
    setSelected(prev => {
      const current = prev[label] ?? []
      return {
        ...prev,
        [label]: current.includes(index) ? current.filter(n => n !== index) : [...current, index],
      }
    })
  }

  const pendingLabels = unresolvedLabels.filter(l => !rows[l]?.saved)
  const savedLabels   = unresolvedLabels.filter(l =>  rows[l]?.saved)
  const hintNames = uniqueNames(characters, campaignVoiceprints, speakerProfiles)

  if (pendingLabels.length === 0 && savedLabels.length === 0) return null

  return (
    <div className="mb-4 rounded border border-[var(--color-rune)] bg-[var(--color-surface)] p-4 flex flex-col gap-4">
      <div className="flex items-center gap-2">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--color-rune-bright)" strokeWidth="1.5">
          <circle cx="12" cy="8" r="4" />
          <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" />
          <path d="M16 3l2 2-2 2" />
        </svg>
        <span className="font-heading text-xs uppercase tracking-widest text-[var(--color-rune-bright)]">
          Identify Speakers
        </span>
        <span className="text-[10px] text-[var(--color-text-dim)]">
          — {pendingLabels.length} unresolved label{pendingLabels.length !== 1 ? 's' : ''}
        </span>
      </div>

      <p className="text-[10px] text-[var(--color-text-dim)] leading-relaxed">
        Assign names this whole label. If Play includes more than one person, select those lines and Split — assigning a name here would stamp one person onto everyone else’s lines.
      </p>

      {pendingLabels.map(label => {
        const row = rows[label]
        const allLines = linesFor(label)
        const showAll = !!expanded[label]
        const visible = showAll ? allLines : allLines.slice(0, 8)
        const windows = clipWindowsForLabel(transcriptSegments, label)
        const selectedIndexes = selected[label] ?? []
        const mixed = labelLooksHeterogeneous(transcriptSegments, label, hintNames)

        return (
          <div
            key={label}
            className="runic-card p-3 flex flex-col gap-2"
            data-testid="unresolved-speaker-card"
            data-speaker-label={label}
          >
            <div className="flex items-start justify-between gap-3">
              <div className="flex items-center gap-2 mb-0.5">
                <span className="font-mono text-[10px] text-[var(--color-text-dim)] bg-[var(--color-bg)] px-1.5 py-0.5 rounded">
                  {label}
                </span>
                <span className="text-[10px] text-[var(--color-text-dim)]">
                  {allLines.length} line{allLines.length !== 1 ? 's' : ''}
                </span>
                {row.saved && (
                  <span className="text-[10px] text-[var(--color-success)]">Saved</span>
                )}
              </div>
              <div className="flex-1 min-w-[10rem] max-w-xs">
                <SpeakerClipPlayer
                  sessionId={sessionId}
                  label={label}
                  hasAudio={hasAudio}
                  windows={windows}
                />
              </div>
            </div>

            {mixed && (
              <p className="text-[10px] text-amber-400" data-testid="mixed-label-warning">
                These sample lines mention more than one known name — this label may be mixed. Split the odd lines before assigning.
              </p>
            )}

            {visible.length > 0 && (
              <div className="flex flex-col gap-1 pl-1 border-l-2 border-[var(--color-border)] mb-1">
                {visible.map(({ seg, index }) => (
                  <label key={index} className="flex items-start gap-2 cursor-pointer">
                    <input
                      type="checkbox"
                      className="mt-0.5 w-3 h-3 accent-[var(--color-rune)]"
                      checked={selectedIndexes.includes(index)}
                      onChange={() => toggleLine(label, index)}
                      data-testid="speaker-line-select"
                    />
                    <span className="text-[10px] text-[var(--color-text-dim)] italic leading-relaxed">
                      “{seg.text.trim()}”
                    </span>
                  </label>
                ))}
                {allLines.length > 8 && (
                  <button
                    type="button"
                    className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)] hover:text-[var(--color-rune)] text-left"
                    onClick={() => setExpanded(prev => ({ ...prev, [label]: !showAll }))}
                  >
                    {showAll ? 'Show fewer lines' : `Show all ${allLines.length} lines`}
                  </button>
                )}
              </div>
            )}

            <div className="flex flex-wrap items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                disabled={selectedIndexes.length === 0}
                data-testid="split-selected"
                onClick={() => setRemap({ indexes: selectedIndexes, label })}
              >
                Split selected{selectedIndexes.length > 0 ? ` (${selectedIndexes.length})` : ''}
              </Button>
              <span className="text-[10px] text-[var(--color-text-dim)]">
                Leaves the rest of {label} untouched.
              </span>
            </div>

            <div className="flex flex-wrap items-end gap-2">
              {campaignVoiceprints.length > 0 && (
                <div className="flex flex-col gap-0.5">
                  <label className="text-[10px] text-[var(--color-text-dim)] uppercase tracking-wide">Campaign voice</label>
                  <select
                    value={row.voiceprintId}
                    onChange={e => {
                      const vp = campaignVoiceprints.find(v => String(v.id) === e.target.value)
                      setRow(label, {
                        voiceprintId: e.target.value,
                        displayName: vp?.display_name || row.displayName,
                        characterId: vp?.character_id ? String(vp.character_id) : row.characterId,
                        isDm: vp?.is_dm ?? row.isDm,
                        error: null,
                      })
                    }}
                    className="h-7 px-2 text-xs bg-[var(--color-bg)] border border-[var(--color-border)] rounded text-[var(--color-text-base)] focus:outline-none focus:border-[var(--color-rune)] w-36"
                  >
                    <option value="">— none —</option>
                    {campaignVoiceprints.map(v => (
                      <option key={v.id} value={v.id}>{v.display_name}{v.is_dm ? ' (DM)' : ''}</option>
                    ))}
                  </select>
                </div>
              )}

              <div className="flex flex-col gap-0.5">
                <label className="text-[10px] text-[var(--color-text-dim)] uppercase tracking-wide">Name</label>
                <input
                  type="text"
                  value={row.displayName}
                  onChange={e => setRow(label, { displayName: e.target.value, error: null })}
                  placeholder="e.g. Justin"
                  className="h-7 px-2 text-xs bg-[var(--color-bg)] border border-[var(--color-border)] rounded text-[var(--color-text-base)] placeholder:text-[var(--color-text-dim)] focus:outline-none focus:border-[var(--color-rune)] w-28"
                />
              </div>

              {characters.length > 0 && (
                <div className="flex flex-col gap-0.5">
                  <label className="text-[10px] text-[var(--color-text-dim)] uppercase tracking-wide">Character</label>
                  <select
                    value={row.characterId}
                    onChange={e => setRow(label, { characterId: e.target.value })}
                    className="h-7 px-2 text-xs bg-[var(--color-bg)] border border-[var(--color-border)] rounded text-[var(--color-text-base)] focus:outline-none focus:border-[var(--color-rune)] w-32"
                  >
                    <option value="">— none —</option>
                    {characters.map(c => (
                      <option key={c.id} value={c.id}>{c.name}</option>
                    ))}
                  </select>
                </div>
              )}

              <label className="flex items-center gap-1.5 cursor-pointer pb-0.5">
                <input
                  type="checkbox"
                  checked={row.isDm}
                  onChange={e => setRow(label, { isDm: e.target.checked })}
                  className="w-3 h-3 accent-[var(--color-rune)]"
                />
                <span className="text-[10px] text-[var(--color-text-dim)] uppercase tracking-wide">DM</span>
              </label>

              <label className="flex items-center gap-1.5 cursor-pointer pb-0.5">
                <input
                  type="checkbox"
                  checked={row.saveToCampaign}
                  onChange={e => setRow(label, { saveToCampaign: e.target.checked })}
                  className="w-3 h-3 accent-[var(--color-rune)]"
                />
                <span className="text-[10px] text-[var(--color-text-dim)] uppercase tracking-wide">Save to campaign</span>
              </label>

              <Button
                variant="rune"
                size="sm"
                onClick={() => save(label)}
                disabled={row.saving || row.saved}
              >
                {row.saving ? 'Saving…' : row.saved ? 'Saved' : 'Assign'}
              </Button>
            </div>

            {row.error && (
              <p className="text-[10px] text-[var(--color-danger)]">{row.error}</p>
            )}
          </div>
        )
      })}

      {savedLabels.length > 0 && (
        <p className="text-[10px] text-[var(--color-text-dim)] italic">
          {savedLabels.length} label{savedLabels.length !== 1 ? 's' : ''} assigned — transcript will update on next reload.
        </p>
      )}

      {remap && (
        <SpeakerRemapDialog
          sessionId={sessionId}
          segmentIndexes={remap.indexes}
          currentLabel={remap.label}
          speakerProfiles={speakerProfiles}
          campaignVoiceprints={campaignVoiceprints}
          characters={characters}
          transcriptSegments={transcriptSegments}
          onClose={() => setRemap(null)}
        />
      )}
    </div>
  )
}

export function AssignedSpeakersPanel({
  speakerProfiles,
  characters,
  campaignVoiceprints,
  transcriptSegments,
  sessionId,
  campaignId,
  hasAudio,
}: {
  speakerProfiles: SpeakerProfile[]
  characters: Character[]
  campaignVoiceprints: CampaignVoiceprint[]
  transcriptSegments: TranscriptSegment[]
  sessionId: number
  campaignId: number
  hasAudio: boolean
}) {
  const [editingId, setEditingId] = useState<number | null>(null)
  const [name, setName] = useState('')
  const [characterId, setCharacterId] = useState('')
  const [isDm, setIsDm] = useState(false)
  const [updateVoiceprint, setUpdateVoiceprint] = useState(true)
  const [promoting, setPromoting] = useState(false)
  const [selected, setSelected] = useState<Record<string, number[]>>({})
  const [remap, setRemap] = useState<{ indexes: number[]; label: string } | null>(null)
  const hintNames = uniqueNames(characters, campaignVoiceprints, speakerProfiles)

  if (speakerProfiles.length === 0) return null

  const startEdit = (profile: SpeakerProfile) => {
    setEditingId(profile.id)
    setName(profile.display_name)
    setCharacterId(profile.character_id ? String(profile.character_id) : '')
    setIsDm(profile.is_dm)
    setUpdateVoiceprint(true)
  }

  const saveEdit = (profile: SpeakerProfile) => {
    if (!name.trim()) return
    router.patch(`/sessions/${sessionId}/speakers/${profile.id}`, {
      display_name: name.trim(),
      character_id: characterId ? Number(characterId) : '',
      is_dm: isDm ? 1 : 0,
      update_voiceprint: updateVoiceprint ? 1 : 0,
    }, {
      preserveScroll: true,
      onSuccess: () => setEditingId(null),
    })
  }

  const promote = () => {
    if (!confirm('Save these session voices as campaign voiceprints? Later sessions will auto-label when the match is confident.')) return
    setPromoting(true)
    router.post(`/sessions/${sessionId}/speakers/promote`, {}, {
      preserveScroll: true,
      onFinish: () => setPromoting(false),
    })
  }

  return (
    <div className="mb-4 rounded border border-[var(--color-border)] bg-[var(--color-surface)] p-4 flex flex-col gap-3">
      <div className="flex items-center justify-between gap-2 flex-wrap">
        <div>
          <span className="font-heading text-xs uppercase tracking-widest text-[var(--color-text-white)]">
            Session voices
          </span>
          <p className="text-[10px] text-[var(--color-text-dim)] mt-0.5">
            Corrections stay on this session. If a label mixed two people, split those lines instead of renaming everyone.
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="ghost" size="sm" as="a" href={`/campaigns/${campaignId}/voices`}>
            Manage campaign voices
          </Button>
          <Button variant="rune" size="sm" onClick={promote} disabled={promoting}>
            {promoting ? 'Saving…' : 'Save voices for this campaign'}
          </Button>
        </div>
      </div>

      {speakerProfiles.map(profile => {
        const lines = transcriptSegments
          .map((s, i) => ({ seg: s, index: segmentIndex(s, i) }))
          .filter(({ seg }) => seg.speaker_label === profile.speaker_label)
        const selectedIndexes = selected[profile.speaker_label] ?? []
        const mixed = labelLooksHeterogeneous(transcriptSegments, profile.speaker_label, hintNames)
        const windows = clipWindowsForLabel(transcriptSegments, profile.speaker_label)

        return (
          <div key={profile.id} className="runic-card p-3 flex flex-col gap-2">
            <div className="flex items-center justify-between gap-2">
              <div className="flex items-center gap-2 min-w-0">
                <span className="font-mono text-[10px] text-[var(--color-text-dim)] bg-[var(--color-bg)] px-1.5 py-0.5 rounded">
                  {profile.speaker_label}
                </span>
                <span className={`text-xs ${profile.is_dm ? 'text-[var(--color-rune-bright)]' : 'text-[var(--color-arcane)]'}`}>
                  {profile.display_name}
                </span>
                {profile.match_source === 'auto' && (
                  <span className="text-[10px] text-[var(--color-text-dim)]">
                    auto{profile.match_confidence != null ? ` ${Math.round(profile.match_confidence * 100)}%` : ''}
                  </span>
                )}
              </div>
              <button
                type="button"
                className="text-[10px] uppercase tracking-widest text-[var(--color-text-dim)] hover:text-[var(--color-rune)]"
                onClick={() => editingId === profile.id ? setEditingId(null) : startEdit(profile)}
              >
                {editingId === profile.id ? 'Close' : 'Correct'}
              </button>
            </div>

            {mixed && (
              <p className="text-[10px] text-amber-400" data-testid="mixed-label-warning">
                Sample lines mention more than one known name. Split the lines that are not {profile.display_name}.
              </p>
            )}

            <div className="flex items-start justify-between gap-3">
              <div className="flex flex-col gap-1 flex-1 min-w-0">
                {lines.slice(0, 4).map(({ seg, index }) => (
                  <label key={index} className="flex items-start gap-2 cursor-pointer">
                    <input
                      type="checkbox"
                      className="mt-0.5 w-3 h-3 accent-[var(--color-rune)]"
                      checked={selectedIndexes.includes(index)}
                      onChange={() => {
                        setSelected(prev => {
                          const current = prev[profile.speaker_label] ?? []
                          return {
                            ...prev,
                            [profile.speaker_label]: current.includes(index)
                              ? current.filter(n => n !== index)
                              : [...current, index],
                          }
                        })
                      }}
                    />
                    <span className="text-[10px] text-[var(--color-text-dim)] italic leading-relaxed">
                      “{seg.text.trim()}”
                    </span>
                  </label>
                ))}
              </div>
              <div className="w-40 shrink-0">
                <SpeakerClipPlayer
                  sessionId={sessionId}
                  label={profile.speaker_label}
                  hasAudio={hasAudio}
                  windows={windows}
                />
              </div>
            </div>

            <div>
              <Button
                variant="ghost"
                size="sm"
                disabled={selectedIndexes.length === 0}
                data-testid="split-selected"
                onClick={() => setRemap({ indexes: selectedIndexes, label: profile.speaker_label })}
              >
                Split selected{selectedIndexes.length > 0 ? ` (${selectedIndexes.length})` : ''}
              </Button>
            </div>

            {editingId === profile.id && (
              <div className="flex flex-wrap items-end gap-2">
                <input
                  type="text"
                  value={name}
                  onChange={e => setName(e.target.value)}
                  className="h-7 px-2 text-xs bg-[var(--color-bg)] border border-[var(--color-border)] rounded text-[var(--color-text-base)] w-28"
                />
                <select
                  value={characterId}
                  onChange={e => setCharacterId(e.target.value)}
                  className="h-7 px-2 text-xs bg-[var(--color-bg)] border border-[var(--color-border)] rounded text-[var(--color-text-base)] w-32"
                >
                  <option value="">— none —</option>
                  {characters.map(c => (
                    <option key={c.id} value={c.id}>{c.name}</option>
                  ))}
                </select>
                <label className="flex items-center gap-1.5 pb-0.5 cursor-pointer">
                  <input type="checkbox" checked={isDm} onChange={e => setIsDm(e.target.checked)} className="w-3 h-3 accent-[var(--color-rune)]" />
                  <span className="text-[10px] text-[var(--color-text-dim)] uppercase">DM</span>
                </label>
                <label className="flex items-center gap-1.5 pb-0.5 cursor-pointer">
                  <input type="checkbox" checked={updateVoiceprint} onChange={e => setUpdateVoiceprint(e.target.checked)} className="w-3 h-3 accent-[var(--color-rune)]" />
                  <span className="text-[10px] text-[var(--color-text-dim)] uppercase">Update campaign voiceprint</span>
                </label>
                <Button variant="rune" size="sm" onClick={() => saveEdit(profile)}>Save correction</Button>
              </div>
            )}
          </div>
        )
      })}

      {remap && (
        <SpeakerRemapDialog
          sessionId={sessionId}
          segmentIndexes={remap.indexes}
          currentLabel={remap.label}
          speakerProfiles={speakerProfiles}
          campaignVoiceprints={campaignVoiceprints}
          characters={characters}
          transcriptSegments={transcriptSegments}
          onClose={() => setRemap(null)}
        />
      )}
    </div>
  )
}

export function ChangeSpeakerButton({
  onClick,
}: {
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="shrink-0 text-[10px] uppercase tracking-widest text-[var(--color-text-dim)] hover:text-[var(--color-rune)]"
      data-testid="change-speaker"
    >
      Change speaker
    </button>
  )
}
