import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'

import PreferencesPanel from '@/components/PreferencesPanel.vue'
import { PREFERENCES_STORAGE_KEY, usePreferencesStore } from '@/stores/preferences'
import { fakeDevice, type FakeDevice } from '../helpers/device'

const root = document.documentElement
let device: FakeDevice

function build() {
  const pinia = createPinia()
  setActivePinia(pinia)
  return mount(PreferencesPanel, { global: { plugins: [pinia] }, attachTo: document.body })
}

type Panel = ReturnType<typeof build>

function radios(wrapper: Panel, group: string) {
  return wrapper.get(`[role="radiogroup"][aria-label="${group}"]`).findAll('[role="radio"]')
}

function checked(wrapper: Panel, group: string): string {
  return radios(wrapper, group)
    .find((radio) => radio.attributes('aria-checked') === 'true')!
    .text()
}

beforeEach(() => {
  localStorage.clear()
  device = fakeDevice({ dark: true })
  delete root.dataset.theme
  delete root.dataset.textSize
  delete root.dataset.motion
})
afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  document.body.innerHTML = ''
})

describe('PreferencesPanel — content', () => {
  it('has the note, the three groups and the text sample, in Spanish', () => {
    const text = build().text()

    expect(text).toContain(
      'Elija cómo quiere ver BarberHub: los cambios se aplican de inmediato y se guardan en este navegador.',
    )
    expect(text).toContain('Tema')
    expect(text).toContain('Tamaño del texto')
    expect(text).toContain('Reducir el movimiento')
    expect(text).toContain('Muestra')
  })

  it('has no save button: it applies at once', () => {
    expect(build().find('button[type="submit"]').exists()).toBe(false)
  })

  it('starts on Automático, Normal and the switch following the device', () => {
    const wrapper = build()

    expect(checked(wrapper, 'Tema')).toBe('Automático')
    expect(checked(wrapper, 'Tamaño del texto')).toContain('Normal')
    expect(wrapper.get('[role="switch"]').attributes('aria-checked')).toBe('false')
  })

  it('lists the three text sizes with their percentage', () => {
    const text = radios(build(), 'Tamaño del texto').map((radio) => radio.text())

    expect(text[0]).toContain('Normal')
    expect(text[0]).toContain('100')
    expect(text[1]).toContain('Grande')
    expect(text[1]).toContain('125')
    expect(text[2]).toContain('Muy grande')
    expect(text[2]).toContain('150')
  })
})

describe('PreferencesPanel — theme', () => {
  it('lists Claro, Oscuro and Automático', () => {
    expect(radios(build(), 'Tema').map((radio) => radio.text())).toEqual([
      'Claro',
      'Oscuro',
      'Automático',
    ])
  })

  it('applies the theme at once and says what it does in a polite region', async () => {
    const wrapper = build()

    await radios(wrapper, 'Tema')[0]!.trigger('click')

    expect(root.dataset.theme).toBe('light')
    expect(checked(wrapper, 'Tema')).toBe('Claro')
    expect(wrapper.get('[aria-live="polite"]').text()).toBe('Fondo claro y texto oscuro.')

    await radios(wrapper, 'Tema')[1]!.trigger('click')
    expect(root.dataset.theme).toBe('dark')
    expect(wrapper.get('[aria-live="polite"]').text()).toBe('Fondo oscuro y texto claro.')
  })

  it('«Automático» follows the device live', async () => {
    const wrapper = build()
    expect(root.dataset.theme).toBe('dark')

    device.set('dark', false)
    await nextTick()

    expect(root.dataset.theme).toBe('light')
    expect(wrapper.get('[aria-live="polite"]').text()).toBe(
      'Sigue la preferencia de su dispositivo',
    )
  })
})

describe('PreferencesPanel — text size', () => {
  it('puts the chosen size on the root and marks it as checked', async () => {
    const wrapper = build()

    await radios(wrapper, 'Tamaño del texto')[2]!.trigger('click')
    expect(root.dataset.textSize).toBe('xlarge')
    expect(checked(wrapper, 'Tamaño del texto')).toContain('Muy grande')

    await radios(wrapper, 'Tamaño del texto')[1]!.trigger('click')
    expect(root.dataset.textSize).toBe('large')

    await radios(wrapper, 'Tamaño del texto')[0]!.trigger('click')
    expect(root.dataset.textSize).toBe('normal')
  })

  it('the sample grows with the root: it is written in rem, with no fixed pixel size', () => {
    const sample = build().get('[data-testid="text-sample"]')

    expect(sample.html()).not.toMatch(/font-size:\s*\d+px/)
    expect(sample.html()).not.toMatch(/style=/)
  })
})

describe('PreferencesPanel — reduced motion', () => {
  it('shows the device state while the person has not chosen', () => {
    device.set('reducedMotion', true)
    const wrapper = build()

    expect(wrapper.get('[role="switch"]').attributes('aria-checked')).toBe('true')
    expect(root.dataset.motion).toBeUndefined()
  })

  it('turns on over a device that does not ask for it', async () => {
    const wrapper = build()

    await wrapper.get('[role="switch"]').trigger('click')

    expect(wrapper.get('[role="switch"]').attributes('aria-checked')).toBe('true')
    expect(root.dataset.motion).toBe('reduce')
  })

  it('turns off over a device that asks for it', async () => {
    device.set('reducedMotion', true)
    const wrapper = build()

    await wrapper.get('[role="switch"]').trigger('click')

    expect(wrapper.get('[role="switch"]').attributes('aria-checked')).toBe('false')
    expect(root.dataset.motion).toBe('full')
  })

  it('names the switch «Reducir el movimiento» and explains the default', () => {
    const wrapper = build()
    const button = wrapper.get('[role="switch"]')

    expect(wrapper.get(`#${button.attributes('aria-labelledby')}`).text()).toBe(
      'Reducir el movimiento',
    )
    expect(wrapper.get(`#${button.attributes('aria-describedby')}`).text()).toContain(
      'Por defecto sigue la preferencia de su dispositivo.',
    )
  })
})

describe('PreferencesPanel — persistence', () => {
  it('keeps what was chosen after a reload (a new store reads the browser)', async () => {
    const first = build()
    await radios(first, 'Tema')[0]!.trigger('click')
    await radios(first, 'Tamaño del texto')[1]!.trigger('click')
    await first.get('[role="switch"]').trigger('click')
    first.unmount()

    const reloaded = build()

    expect(checked(reloaded, 'Tema')).toBe('Claro')
    expect(checked(reloaded, 'Tamaño del texto')).toContain('Grande')
    expect(reloaded.get('[role="switch"]').attributes('aria-checked')).toBe('true')
    expect(JSON.parse(localStorage.getItem(PREFERENCES_STORAGE_KEY) ?? '{}')).toEqual({
      theme: 'light',
      textSize: 'large',
      reduceMotion: true,
    })
  })

  it('works when the browser blocks the storage', async () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('blocked', 'QuotaExceededError')
    })
    const wrapper = build()

    await radios(wrapper, 'Tema')[0]!.trigger('click')

    expect(root.dataset.theme).toBe('light')
    expect(usePreferencesStore().theme).toBe('light')
  })

  it('never calls the API', async () => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = build()

    await radios(wrapper, 'Tema')[0]!.trigger('click')
    await wrapper.get('[role="switch"]').trigger('click')

    expect(fetchMock).not.toHaveBeenCalled()
  })
})

describe('PreferencesPanel — keyboard', () => {
  it('the arrow keys change the text size and keep the focus on the chosen row', async () => {
    const wrapper = build()
    const first = radios(wrapper, 'Tamaño del texto')[0]!
    ;(first.element as HTMLElement).focus()

    await first.trigger('keydown', { key: 'ArrowDown' })

    expect(root.dataset.textSize).toBe('large')
    expect(document.activeElement).toBe(radios(wrapper, 'Tamaño del texto')[1]!.element)
  })
})
