export type WindowChromeAction = 'minimize' | 'maximize' | 'close'
export type DesktopPlatform = 'linux' | 'windows' | 'mac' | 'other'

type ElectronBrowserWindow = {
  minimize: () => void
  maximize: () => void
  unmaximize: () => void
  close: () => void
  isMaximized: () => boolean
  isDestroyed?: () => boolean
}

type ElectronRemote = {
  getCurrentWindow?: () => ElectronBrowserWindow
  BrowserWindow?: { getFocusedWindow?: () => ElectronBrowserWindow | null }
  app?: { quit: () => void }
}

function nodeRequire(): ((id: string) => unknown) | null {
  const req = (window as unknown as { require?: (id: string) => unknown }).require
  return typeof req === 'function' ? req : null
}

function electronRemote(): ElectronRemote | null {
  const req = nodeRequire()
  if (!req) return null
  try {
    return req('@electron/remote') as ElectronRemote
  } catch {
    return null
  }
}

export function getElectronWindow(): ElectronBrowserWindow | null {
  const remote = electronRemote()
  if (!remote) return null
  try {
    return remote.getCurrentWindow?.() ?? remote.BrowserWindow?.getFocusedWindow?.() ?? null
  } catch {
    return null
  }
}

export function detectDesktopPlatform(): DesktopPlatform {
  try {
    const req = nodeRequire()
    const platform = req ? (req('os') as { platform?: () => string }).platform?.() : undefined
    if (platform === 'linux') return 'linux'
    if (platform === 'win32') return 'windows'
    if (platform === 'darwin') return 'mac'
  } catch {
    // Fall through to user-agent.
  }

  const ua = navigator.userAgent
  if (/Windows/i.test(ua)) return 'windows'
  if (/Linux|X11/i.test(ua)) return 'linux'
  if (/Macintosh|Mac OS X/i.test(ua)) return 'mac'
  return 'other'
}

/**
 * titleBarHidden() keeps macOS traffic lights. Linux and Windows get none,
 * so those hosts need in-app close / minimize / maximize buttons.
 */
export function needsCustomWindowControls(platform: DesktopPlatform = detectDesktopPlatform()): boolean {
  return platform === 'linux' || platform === 'windows'
}

export function isWindowMaximized(): boolean {
  try {
    return Boolean(getElectronWindow()?.isMaximized())
  } catch {
    return false
  }
}

function csrfToken(): string {
  return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? ''
}

async function postWindowChrome(action: WindowChromeAction, restore = false): Promise<boolean> {
  const body = action === 'maximize' ? JSON.stringify({ restore }) : undefined
  const res = await fetch(`/window/${action}`, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': csrfToken(),
    },
    body,
  })
  return res.ok
}

export async function runWindowChrome(action: WindowChromeAction, restore = false): Promise<void> {
  const win = getElectronWindow()
  if (win) {
    if (action === 'minimize') {
      win.minimize()
      return
    }
    if (action === 'maximize') {
      if (restore || win.isMaximized()) {
        win.unmaximize()
      } else {
        win.maximize()
      }
      return
    }
    try {
      win.close()
    } catch {
      electronRemote()?.app?.quit()
    }
    return
  }

  try {
    const ok = await postWindowChrome(action, restore)
    if (ok) return
  } catch {
    // Last-ditch close if NativePHP IPC is down.
  }

  if (action === 'close') {
    window.close()
  }
}
