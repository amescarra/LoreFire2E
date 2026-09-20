import { useCallback, useEffect, useRef, useState } from 'react'
import { fetchAudioCaptureConfig, openCaptureStream } from '@/lib/audioCapture'

export type EnrollmentCapture = {
  activeKey: string | null
  seconds: number
  label: string | null
  error: string | null
  busy: boolean
  start: (key: string) => Promise<void>
  stop: () => Promise<File | null>
  setError: (message: string | null) => void
}

/**
 * Short in-app enrollment take. Reuses Live's getUserMedia / Anker S500
 * constraints via openCaptureStream. Does not touch session chunk uploads.
 */
export function useEnrollmentCapture(): EnrollmentCapture {
  const [activeKey, setActiveKey] = useState<string | null>(null)
  const [seconds, setSeconds] = useState(0)
  const [label, setLabel] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const recorderRef = useRef<MediaRecorder | null>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const chunksRef = useRef<Blob[]>([])
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null)
  const mimeRef = useRef('audio/webm')
  const stopResolver = useRef<((file: File | null) => void) | null>(null)

  const cleanupStream = () => {
    streamRef.current?.getTracks().forEach(track => track.stop())
    streamRef.current = null
    if (timerRef.current) {
      clearInterval(timerRef.current)
      timerRef.current = null
    }
  }

  useEffect(() => () => {
    recorderRef.current?.stop()
    cleanupStream()
  }, [])

  const start = useCallback(async (key: string) => {
    if (recorderRef.current || busy) {
      return
    }
    setError(null)
    setSeconds(0)
    setBusy(true)
    try {
      const config = await fetchAudioCaptureConfig()
      const opened = await openCaptureStream(config)
      streamRef.current = opened.stream
      setLabel(opened.label)

      const mime = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : 'audio/ogg'
      mimeRef.current = mime
      chunksRef.current = []

      const recorder = new MediaRecorder(opened.stream, { mimeType: mime })
      recorderRef.current = recorder
      recorder.ondataavailable = (event) => {
        if (event.data.size > 0) {
          chunksRef.current.push(event.data)
        }
      }
      recorder.onstop = () => {
        const type = mimeRef.current
        const blob = new Blob(chunksRef.current, { type })
        cleanupStream()
        recorderRef.current = null
        const ext = type.includes('ogg') ? 'ogg' : 'webm'
        const file = blob.size > 64
          ? new File([blob], `enrollment.${ext}`, { type })
          : null
        stopResolver.current?.(file)
        stopResolver.current = null
        setActiveKey(null)
        setBusy(false)
      }
      recorder.start(250)
      setActiveKey(key)
      timerRef.current = setInterval(() => setSeconds(s => s + 1), 1000)
    } catch (err) {
      cleanupStream()
      const name = err instanceof DOMException ? err.name : ''
      if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
        setError('Microphone permission denied. Allow the mic in Electron / the browser, then try again.')
      } else if (name === 'NotFoundError') {
        setError('No microphone found. Check the Anker S500 / Bluetooth device in Settings.')
      } else {
        setError('Could not open the microphone. Check Settings → Session Audio.')
      }
      setActiveKey(null)
    } finally {
      if (!recorderRef.current) {
        setBusy(false)
      }
    }
  }, [busy])

  const stop = useCallback((): Promise<File | null> => {
    return new Promise((resolve) => {
      const recorder = recorderRef.current
      if (!recorder || recorder.state === 'inactive') {
        cleanupStream()
        setActiveKey(null)
        setBusy(false)
        resolve(null)
        return
      }
      stopResolver.current = resolve
      try {
        recorder.stop()
      } catch {
        cleanupStream()
        recorderRef.current = null
        setActiveKey(null)
        setBusy(false)
        resolve(null)
      }
    })
  }, [])

  return { activeKey, seconds, label, error, busy, start, stop, setError }
}
