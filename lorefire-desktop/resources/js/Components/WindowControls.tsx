import React, { useEffect, useState } from 'react'
import { isWindowMaximized, needsCustomWindowControls, runWindowChrome } from '@/lib/windowChrome'

function ChromeIcon({ d, size = 12 }: { d: string; size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 12 12" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" aria-hidden="true">
      <path d={d} />
    </svg>
  )
}

export function WindowControls({ className = '' }: { className?: string }) {
  const [visible, setVisible] = useState(() => needsCustomWindowControls())
  const [maximized, setMaximized] = useState(() => isWindowMaximized())

  useEffect(() => {
    setVisible(needsCustomWindowControls())
    setMaximized(isWindowMaximized())
  }, [])

  if (!visible) {
    return null
  }

  const btn =
    'no-drag inline-flex items-center justify-center h-full w-12 text-[var(--color-text-bright)] hover:text-[var(--color-text-white)] hover:bg-[var(--color-surface)] transition-colors'

  return (
    <div
      className={`window-controls no-drag flex items-stretch h-full shrink-0 ${className}`}
      data-testid="window-controls"
      role="group"
      aria-label="Window controls"
    >
      <button
        type="button"
        data-testid="window-minimize"
        className={btn}
        title="Minimize"
        aria-label="Minimize"
        onClick={() => void runWindowChrome('minimize')}
      >
        <ChromeIcon d="M2 6h8" />
      </button>
      <button
        type="button"
        data-testid="window-maximize"
        className={btn}
        title={maximized ? 'Restore' : 'Maximize'}
        aria-label={maximized ? 'Restore' : 'Maximize'}
        onClick={() => {
          void runWindowChrome('maximize', maximized)
          setMaximized(current => !current)
        }}
      >
        {maximized
          ? <ChromeIcon d="M3.5 4.5h5v5h-5zM4.5 3.5h5v5" />
          : <ChromeIcon d="M2.5 2.5h7v7h-7z" />}
      </button>
      <button
        type="button"
        data-testid="window-close"
        className={`${btn} hover:text-white hover:bg-[#c0392b]`}
        title="Close"
        aria-label="Close"
        onClick={() => void runWindowChrome('close')}
      >
        <ChromeIcon d="M3 3l6 6M9 3l-6 6" />
      </button>
    </div>
  )
}
