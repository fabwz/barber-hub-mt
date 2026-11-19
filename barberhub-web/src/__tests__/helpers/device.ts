import { vi } from 'vitest'

type Listener = (event: { matches: boolean }) => void

export interface FakeDevice {
  /** Changes what the device says and notifies whoever listens, like the operating system would. */
  set(query: 'dark' | 'reducedMotion', matches: boolean): void
}

const QUERIES: Record<string, 'dark' | 'reducedMotion'> = {
  '(prefers-color-scheme: dark)': 'dark',
  '(prefers-reduced-motion: reduce)': 'reducedMotion',
}

/** Replaces window.matchMedia (jsdom has none) with a device the test can change live. */
export function fakeDevice(initial: { dark?: boolean; reducedMotion?: boolean } = {}): FakeDevice {
  const state = { dark: initial.dark ?? false, reducedMotion: initial.reducedMotion ?? false }
  const listeners: Record<'dark' | 'reducedMotion', Listener[]> = { dark: [], reducedMotion: [] }

  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => {
      const key = QUERIES[query]
      return {
        get matches() {
          return key === undefined ? false : state[key]
        },
        media: query,
        addEventListener: (_type: string, listener: Listener) => {
          if (key !== undefined) listeners[key].push(listener)
        },
        removeEventListener: () => {},
      }
    }),
  )

  return {
    set(query, matches) {
      state[query] = matches
      listeners[query].forEach((listener) => listener({ matches }))
    },
  }
}
