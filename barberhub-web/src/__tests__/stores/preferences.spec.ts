import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import {
  PREFERENCES_STORAGE_KEY,
  parseStoredPreferences,
  usePreferencesStore,
} from '@/stores/preferences'
import { fakeDevice } from '../helpers/device'

const root = document.documentElement

/** A fresh store, like a page load: it reads the browser's storage and the device again. */
function freshStore() {
  setActivePinia(createPinia())
  return usePreferencesStore()
}

beforeEach(() => {
  localStorage.clear()
  delete root.dataset.theme
  delete root.dataset.textSize
  delete root.dataset.motion
})
afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('preferences store — defaults', () => {
  it('starts on automatic theme, normal text and motion that follows the device', () => {
    fakeDevice({ dark: true })
    const store = freshStore()

    expect(store.theme).toBe('auto')
    expect(store.textSize).toBe('normal')
    expect(store.reduceMotion).toBeNull()
    expect(root.dataset.theme).toBe('dark')
    expect(root.dataset.textSize).toBe('normal')
    expect(root.dataset.motion).toBeUndefined()
  })

  it('writes nothing until the person chooses something', () => {
    fakeDevice()
    freshStore()

    expect(localStorage.getItem(PREFERENCES_STORAGE_KEY)).toBeNull()
  })
})

describe('preferences store — theme', () => {
  it('automatic follows the device and changes live when the device changes', () => {
    const device = fakeDevice({ dark: true })
    const store = freshStore()
    expect(store.resolvedTheme).toBe('dark')

    device.set('dark', false)
    expect(store.resolvedTheme).toBe('light')
    expect(root.dataset.theme).toBe('light')

    device.set('dark', true)
    expect(root.dataset.theme).toBe('dark')
  })

  it('a fixed theme ignores the device', () => {
    const device = fakeDevice({ dark: true })
    const store = freshStore()

    store.setTheme('light')
    device.set('dark', false)
    device.set('dark', true)

    expect(root.dataset.theme).toBe('light')
  })

  it('applies at once, without waiting for a tick', () => {
    fakeDevice({ dark: true })
    const store = freshStore()

    store.setTheme('light')

    expect(root.dataset.theme).toBe('light')
  })

  it('goes back to following the device when «Automático» is chosen again', () => {
    const device = fakeDevice({ dark: false })
    const store = freshStore()
    store.setTheme('dark')
    expect(root.dataset.theme).toBe('dark')

    store.setTheme('auto')

    expect(root.dataset.theme).toBe('light')
    device.set('dark', true)
    expect(root.dataset.theme).toBe('dark')
  })
})

describe('preferences store — text size', () => {
  it.each([
    ['normal', 'normal'],
    ['large', 'large'],
    ['xlarge', 'xlarge'],
  ] as const)('puts %s on the root', (size, attribute) => {
    fakeDevice()
    const store = freshStore()

    store.setTextSize(size)

    expect(root.dataset.textSize).toBe(attribute)
  })
})

describe('preferences store — reduced motion', () => {
  it('follows the device by default, live, and shows its state in the switch', () => {
    const device = fakeDevice({ reducedMotion: true })
    const store = freshStore()
    expect(store.reducedMotion).toBe(true)

    device.set('reducedMotion', false)

    expect(store.reducedMotion).toBe(false)
  })

  it('the person can turn it on when the device does not ask for it', () => {
    fakeDevice({ reducedMotion: false })
    const store = freshStore()

    store.setReduceMotion(true)

    expect(store.reducedMotion).toBe(true)
    expect(root.dataset.motion).toBe('reduce')
  })

  it('the person can turn it off when the device asks for it, and the device no longer decides', () => {
    const device = fakeDevice({ reducedMotion: true })
    const store = freshStore()

    store.setReduceMotion(false)
    device.set('reducedMotion', true)

    expect(store.reducedMotion).toBe(false)
    expect(root.dataset.motion).toBe('full')
  })

  it('can go back to the device', () => {
    fakeDevice({ reducedMotion: true })
    const store = freshStore()
    store.setReduceMotion(false)

    store.setReduceMotion(null)

    expect(store.reducedMotion).toBe(true)
    expect(root.dataset.motion).toBeUndefined()
  })
})

describe('preferences store — persistence', () => {
  it('keeps the three choices after a reload', () => {
    fakeDevice({ dark: true })
    const store = freshStore()
    store.setTheme('light')
    store.setTextSize('xlarge')
    store.setReduceMotion(true)

    delete root.dataset.theme
    delete root.dataset.textSize
    delete root.dataset.motion
    const reloaded = freshStore()

    expect(reloaded.theme).toBe('light')
    expect(reloaded.textSize).toBe('xlarge')
    expect(reloaded.reduceMotion).toBe(true)
    expect(root.dataset.theme).toBe('light')
    expect(root.dataset.textSize).toBe('xlarge')
    expect(root.dataset.motion).toBe('reduce')
  })

  it('saves only the browser key, with the three fields and nothing else', () => {
    fakeDevice()
    freshStore().setTheme('dark')

    expect(JSON.parse(localStorage.getItem(PREFERENCES_STORAGE_KEY) ?? '')).toEqual({
      theme: 'dark',
      textSize: 'normal',
      reduceMotion: null,
    })
    expect(localStorage).toHaveLength(1)
  })

  it('ignores a saved value that is broken or unknown', () => {
    fakeDevice()
    localStorage.setItem(PREFERENCES_STORAGE_KEY, '{not json')
    expect(freshStore().theme).toBe('auto')

    localStorage.setItem(
      PREFERENCES_STORAGE_KEY,
      JSON.stringify({ theme: 'sepia', textSize: 'huge', reduceMotion: 'yes' }),
    )
    const store = freshStore()
    expect([store.theme, store.textSize, store.reduceMotion]).toEqual(['auto', 'normal', null])
  })
})

describe('preferences store — without storage', () => {
  it('does not break when reading fails and keeps working in memory', () => {
    fakeDevice({ dark: true })
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('blocked', 'QuotaExceededError')
    })

    const store = freshStore()
    expect(store.theme).toBe('auto')

    expect(() => store.setTheme('light')).not.toThrow()
    expect(() => store.setTextSize('large')).not.toThrow()
    expect(() => store.setReduceMotion(true)).not.toThrow()
    expect(root.dataset.theme).toBe('light')
    expect(root.dataset.textSize).toBe('large')
    expect(root.dataset.motion).toBe('reduce')
    expect(store.theme).toBe('light')
  })

  it('works when the device cannot answer (no matchMedia): stays dark', () => {
    vi.stubGlobal('matchMedia', undefined)

    const store = freshStore()

    expect(store.resolvedTheme).toBe('dark')
    expect(store.reducedMotion).toBe(false)
  })
})

describe('parseStoredPreferences', () => {
  it('returns the defaults for nothing, null or a non-object', () => {
    const defaults = { theme: 'auto', textSize: 'normal', reduceMotion: null }
    expect(parseStoredPreferences(null)).toEqual(defaults)
    expect(parseStoredPreferences('null')).toEqual(defaults)
    expect(parseStoredPreferences('7')).toEqual(defaults)
  })

  it('keeps the valid fields and defaults the rest', () => {
    expect(parseStoredPreferences('{"theme":"dark","textSize":"x"}')).toEqual({
      theme: 'dark',
      textSize: 'normal',
      reduceMotion: null,
    })
  })
})
