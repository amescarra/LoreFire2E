/**
 * Live session capture device selection.
 *
 * Electron getUserMedia is the only live-mic path (PHP/ffmpeg/Python sounddevice
 * are not used). On linux-5090, prefer the Anker PowerConf S500 / bluez_input
 * when present. Windows ARM keeps { audio: true } unless the user saved a mic.
 */

export interface MediaDeviceLite {
  deviceId?: string
  label?: string
  kind?: string
}

export interface AudioCaptureConfig {
  linux: boolean
  input_device: string
  input_label: string
  input_pulse_name?: string
  output_device: string
  output_label: string
  output_pulse_name?: string
  auto_prefer: boolean
  preferred_label: string
  preferred_mac: string
  preferred_needles: string[]
  capture: {
    channelCount: number
    sampleRate: number
    echoCancellation: boolean
    noiseSuppression: boolean
    autoGainControl: boolean
  } | null
}

export const FALLBACK_PREFERRED_NEEDLES = [
  'anker powerconf s500',
  'powerconf s500',
  'bluez_input.84_d3_52_ee_10_e3',
  'bluez_output.84_d3_52_ee_10_e3',
  'bluez_card.84_d3_52_ee_10_e3',
]

export function matchesPreferredLabel(haystack: string, needles?: string[]): boolean {
  const hay = haystack.toLowerCase()
  if (!hay) return false
  for (const needle of needles ?? FALLBACK_PREFERRED_NEEDLES) {
    if (hay.includes(needle.toLowerCase())) return true
  }
  return hay.includes('bluez_input') && hay.includes('s500')
}

export function pickAudioInput(
  devices: MediaDeviceLite[],
  config: Partial<AudioCaptureConfig> | null | undefined,
): { deviceId: string; label: string } | null {
  const inputs = devices
    .filter(d => !d.kind || d.kind === 'audioinput')
    .map(d => ({ deviceId: d.deviceId ?? '', label: d.label ?? '' }))
    .filter(d => d.deviceId && d.deviceId !== 'default' && d.deviceId !== 'communications')

  const savedId = (config?.input_device ?? '').trim()
  const savedLabel = (config?.input_label ?? '').trim()

  if (savedId) {
    const byId = inputs.find(d => d.deviceId === savedId)
    if (byId) return byId
  }
  if (savedLabel) {
    const exact = inputs.find(d => d.label.toLowerCase() === savedLabel.toLowerCase())
    if (exact) return exact
    const partial = inputs.find(d => d.label.toLowerCase().includes(savedLabel.toLowerCase()))
    if (partial) return partial
  }

  const auto = config?.auto_prefer !== false
  if (config?.linux && auto) {
    const preferred = inputs.find(d => matchesPreferredLabel(`${d.label} ${d.deviceId}`, config.preferred_needles))
    if (preferred) return preferred
  }

  return null
}

export function pickAudioOutput(
  devices: MediaDeviceLite[],
  config: Partial<AudioCaptureConfig> | null | undefined,
): { deviceId: string; label: string } | null {
  const outputs = devices
    .filter(d => d.kind === 'audiooutput')
    .map(d => ({ deviceId: d.deviceId ?? '', label: d.label ?? '' }))
    .filter(d => d.deviceId && d.deviceId !== 'default' && d.deviceId !== 'communications')

  const savedId = (config?.output_device ?? '').trim()
  const savedLabel = (config?.output_label ?? '').trim()
  if (savedId) {
    const byId = outputs.find(d => d.deviceId === savedId)
    if (byId) return byId
  }
  if (savedLabel) {
    const exact = outputs.find(d => d.label.toLowerCase() === savedLabel.toLowerCase())
    if (exact) return exact
    const partial = outputs.find(d => d.label.toLowerCase().includes(savedLabel.toLowerCase()))
    if (partial) return partial
  }
  const auto = config?.auto_prefer !== false
  if (config?.linux && auto) {
    const preferred = outputs.find(d => matchesPreferredLabel(`${d.label} ${d.deviceId}`, config.preferred_needles))
    if (preferred) return preferred
  }
  return null
}

/**
 * Windows ARM / non-Linux with no saved device: callers must use { audio: true }.
 * Linux (or a saved override) gets conference-speakerphone constraints.
 */
export function buildAudioConstraints(
  pick: { deviceId: string; label: string } | null,
  config: Partial<AudioCaptureConfig> | null | undefined,
): MediaStreamConstraints {
  const linux = !!config?.linux
  const hints = config?.capture
  if (!linux && !pick) {
    return { audio: true }
  }

  const audio: MediaTrackConstraints = {}
  if (pick?.deviceId) {
    audio.deviceId = { ideal: pick.deviceId }
  }
  if (linux && hints) {
    audio.channelCount = { ideal: hints.channelCount }
    audio.sampleRate = { ideal: hints.sampleRate }
    audio.echoCancellation = hints.echoCancellation
    audio.noiseSuppression = hints.noiseSuppression
    audio.autoGainControl = hints.autoGainControl
  }

  return { audio: Object.keys(audio).length ? audio : true }
}

export async function fetchAudioCaptureConfig(): Promise<AudioCaptureConfig | null> {
  try {
    const res = await fetch('/settings/audio-capture', { headers: { Accept: 'application/json' } })
    if (!res.ok) return null
    return await res.json()
  } catch {
    return null
  }
}

export async function openCaptureStream(
  config: AudioCaptureConfig | null,
): Promise<{ stream: MediaStream; label: string; switched: boolean }> {
  const linux = !!config?.linux
  const saved = !!(config?.input_device || config?.input_label)

  // Windows / non-Linux, no override: identical to the historic MediaRecorder path.
  if (!linux && !saved) {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
    return { stream, label: stream.getAudioTracks()[0]?.label ?? 'Default microphone', switched: false }
  }

  const probe = await navigator.mediaDevices.getUserMedia({ audio: true })
  let devices: MediaDeviceInfo[] = []
  try {
    devices = await navigator.mediaDevices.enumerateDevices()
  } catch {
    devices = []
  }
  const pick = pickAudioInput(devices, config ?? undefined)
  const currentId = probe.getAudioTracks()[0]?.getSettings()?.deviceId ?? ''
  const needSwitch = !!pick && pick.deviceId !== currentId

  if (!needSwitch) {
    if (linux && config?.capture) {
      const track = probe.getAudioTracks()[0]
      try {
        await track?.applyConstraints({
          channelCount: { ideal: config.capture.channelCount },
          sampleRate: { ideal: config.capture.sampleRate },
          echoCancellation: config.capture.echoCancellation,
          noiseSuppression: config.capture.noiseSuppression,
          autoGainControl: config.capture.autoGainControl,
        })
      } catch {
        // Device may reject 16 kHz / mono; keep the open stream.
      }
    }
    return {
      stream: probe,
      label: pick?.label || probe.getAudioTracks()[0]?.label || 'Default microphone',
      switched: false,
    }
  }

  probe.getTracks().forEach(t => t.stop())
  try {
    const stream = await navigator.mediaDevices.getUserMedia(buildAudioConstraints(pick, config))
    return { stream, label: pick?.label || stream.getAudioTracks()[0]?.label || 'Microphone', switched: true }
  } catch {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
    return { stream, label: stream.getAudioTracks()[0]?.label ?? 'Default microphone', switched: false }
  }
}
