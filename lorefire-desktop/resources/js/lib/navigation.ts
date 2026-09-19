import { router } from '@inertiajs/react'

const FALLBACK = '/campaigns'

function hasInertiaPage(): boolean {
  const app = document.getElementById('app')
  return Boolean(app?.getAttribute('data-page'))
}

/**
 * In-app back for NativePHP/Electron (no browser chrome).
 * Uses history when Inertia has a prior entry; otherwise visits a safe root
 * instead of leaving the webview on about:blank.
 */
export function goBack(fallback = FALLBACK): void {
  const before = window.location.href
  window.history.back()
  window.setTimeout(() => {
    if (window.location.href !== before) return
    if (!hasInertiaPage()) {
      window.location.replace(fallback)
      return
    }
    router.visit(fallback)
  }, 120)
}

/**
 * Recover when OS/mouse back walks off the SPA document.
 */
export function installHistoryGuard(fallback = FALLBACK): void {
  window.addEventListener('popstate', () => {
    window.setTimeout(() => {
      if (!hasInertiaPage()) {
        window.location.replace(fallback)
      }
    }, 150)
  })
}
