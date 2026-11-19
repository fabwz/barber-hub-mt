import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { PREFERENCES_STORAGE_KEY, usePreferencesStore } from '@/stores/preferences'
import { fakeDevice } from './helpers/device'

const html = readFileSync(join(process.cwd(), 'index.html'), 'utf8')
const script = /<script>([\s\S]*?)<\/script>/.exec(html)?.[1] ?? ''
const root = document.documentElement

function attributes() {
  return { ...root.dataset }
}

function reset(): void {
  delete root.dataset.theme
  delete root.dataset.textSize
  delete root.dataset.motion
}

/** Runs the inline script of index.html, as the browser does before the first render. */
function runScript(): void {
  new Function(script)()
}

/** What the store puts on <html> for the same storage and device. */
function runStore() {
  reset()
  setActivePinia(createPinia())
  usePreferencesStore()
  return attributes()
}

beforeEach(() => {
  localStorage.clear()
  reset()
})
afterEach(() => vi.unstubAllGlobals())

describe('script in index.html (before the first render)', () => {
  it('exists, is inline and comes before the app module', () => {
    expect(script).not.toBe('')
    expect(html.indexOf('<script>')).toBeLessThan(html.indexOf('type="module"'))
  })

  it('without saved preferences follows the device: dark and light', () => {
    const device = fakeDevice({ dark: true })
    runScript()
    expect(attributes()).toEqual({ theme: 'dark', textSize: 'normal' })

    reset()
    device.set('dark', false)
    runScript()
    expect(root.dataset.theme).toBe('light')
  })

  it('applies what was saved', () => {
    fakeDevice({ dark: true })
    localStorage.setItem(
      PREFERENCES_STORAGE_KEY,
      JSON.stringify({ theme: 'light', textSize: 'xlarge', reduceMotion: true }),
    )

    runScript()

    expect(attributes()).toEqual({ theme: 'light', textSize: 'xlarge', motion: 'reduce' })
  })

  it('does not break when storage is blocked or broken', () => {
    fakeDevice({ dark: false })
    localStorage.setItem(PREFERENCES_STORAGE_KEY, '{broken')
    expect(runScript).not.toThrow()
    expect(root.dataset.theme).toBe('light')

    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError')
    })
    reset()
    expect(runScript).not.toThrow()
    expect(root.dataset.textSize).toBe('normal')
    vi.restoreAllMocks()
  })

  it('stays dark when the browser has no matchMedia', () => {
    vi.stubGlobal('matchMedia', undefined)
    runScript()
    expect(root.dataset.theme).toBe('dark')
  })

  it.each([
    [undefined, { dark: true }],
    [{ theme: 'auto' }, { dark: false }],
    [{ theme: 'dark' }, { dark: false }],
    [{ theme: 'light', textSize: 'large' }, { dark: true }],
    [{ textSize: 'xlarge', reduceMotion: false }, { dark: false }],
    [{ theme: 'sepia', textSize: 'huge', reduceMotion: 'yes' }, { dark: true }],
  ])('sets the same attributes as the store for %j on device %j', (saved, device) => {
    fakeDevice(device)
    if (saved !== undefined) localStorage.setItem(PREFERENCES_STORAGE_KEY, JSON.stringify(saved))

    runScript()
    const fromScript = attributes()

    expect(runStore()).toEqual(fromScript)
  })
})
