import { PageProps } from '@/types'

export type PythonSetupPayload = NonNullable<PageProps['python_setup']>

export async function fetchPythonSetupStatus(): Promise<PythonSetupPayload | null> {
  try {
    const res = await fetch('/python-setup-status', {
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    })
    if (!res.ok) return null
    return await res.json() as PythonSetupPayload
  } catch {
    return null
  }
}
