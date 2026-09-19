import { useState, useEffect, useRef } from 'react'
import axios from 'axios'

export type PdfStatus = 'idle' | 'pending' | 'done' | 'failed' | 'preview'

export function pdfExportButtonLabel(status: PdfStatus, idle: string): string {
  switch (status) {
    case 'pending': return 'Generating PDF…'
    case 'done': return 'Saved to Downloads ✓'
    case 'preview': return 'Opening print preview…'
    case 'failed': return 'Export Failed'
    default: return idle
  }
}

export function usePdfExport(exportUrl: string) {
  const [status, setStatus] = useState<PdfStatus>('idle')
  const [error, setError] = useState<string | null>(null)
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null)

  const stopPolling = () => {
    if (pollRef.current) {
      clearInterval(pollRef.current)
      pollRef.current = null
    }
  }

  useEffect(() => () => stopPolling(), [])

  const trigger = async (payload: Record<string, unknown> = {}) => {
    if (status === 'pending') return
    setStatus('pending')
    setError(null)

    try {
      const { data } = await axios.post(exportUrl, payload, {
        headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '' },
      })
      const key: string = data.key

      const pollOnce = async () => {
        try {
          const { data: poll } = await axios.get(`/pdf-export/status?key=${encodeURIComponent(key)}`)
          if (poll.status === 'done') {
            stopPolling()
            setStatus('done')
            setTimeout(() => setStatus('idle'), 3000)
          } else if (poll.status === 'preview' && poll.preview_url) {
            stopPolling()
            setStatus('preview')
            window.location.assign(poll.preview_url)
          } else if (poll.status === 'failed') {
            stopPolling()
            setError(poll.error ?? 'PDF generation failed')
            setStatus('failed')
            setTimeout(() => setStatus('idle'), 5000)
          }
        } catch {
          // network hiccup — keep polling
        }
      }

      pollOnce()
      pollRef.current = setInterval(pollOnce, 500)
    } catch {
      setStatus('failed')
      setError('Failed to start PDF export')
      setTimeout(() => setStatus('idle'), 5000)
    }
  }

  return { status, error, trigger }
}
