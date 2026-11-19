import { beforeEach, describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'

import BrandLogo from '@/components/BrandLogo.vue'
import boneLogo from '@/assets/images/bh-logo-bone.png'
import navyLogo from '@/assets/images/bh-logo-navy.png'
import { usePreferencesStore } from '@/stores/preferences'
import { fakeDevice } from '../helpers/device'

function mountLogo(props: { size?: number } = {}) {
  const pinia = createPinia()
  setActivePinia(pinia)
  return mount(BrandLogo, { props, global: { plugins: [pinia] } })
}

beforeEach(() => {
  localStorage.clear()
  vi.unstubAllGlobals()
  fakeDevice({ dark: true })
})

describe('BrandLogo', () => {
  it('has the alt «BarberHub» and explicit width and height', () => {
    const img = mountLogo().get('img')

    expect(img.attributes('alt')).toBe('BarberHub')
    expect(img.attributes('width')).toBe('93')
    expect(img.attributes('height')).toBe('93')
  })

  it('the window is the whole mark (1209 x 742 box of the PNG), 56 px wide by default', () => {
    const style = mountLogo().get('span').attributes('style') ?? ''

    expect(style).toContain('width: 56px')
    expect(style).toContain('height: 35px')
  })

  it('offsets the image by the margin of the PNG so nothing is cropped or left over', () => {
    const style = mountLogo().get('img').attributes('style') ?? ''
    const scale = 56 / 1209

    expect(style).toContain(`left: ${-489 * scale}px`)
    expect(style).toContain(`top: ${-576 * scale}px`)
    expect(style).toContain(`width: ${2000 * scale}px`)
  })

  it('scales the window and the image with size, keeping the ratio', () => {
    const wrapper = mountLogo({ size: 90 })

    expect(wrapper.get('span').attributes('style')).toContain('width: 90px')
    expect(wrapper.get('span').attributes('style')).toContain('height: 56px')
    expect(wrapper.get('img').attributes('width')).toBe('149')
    expect(wrapper.get('img').attributes('height')).toBe('149')
  })

  it('shows the bone logo in the dark theme and the navy one in the light theme', async () => {
    const wrapper = mountLogo()
    const store = usePreferencesStore()
    expect(wrapper.get('img').attributes('src')).toBe(boneLogo)

    store.setTheme('light')
    await nextTick()
    expect(wrapper.get('img').attributes('src')).toBe(navyLogo)

    store.setTheme('dark')
    await nextTick()
    expect(wrapper.get('img').attributes('src')).toBe(boneLogo)
  })

  it('with the automatic theme the logo follows the device live', async () => {
    const device = fakeDevice({ dark: true })
    const wrapper = mountLogo()
    expect(wrapper.get('img').attributes('src')).toBe(boneLogo)

    device.set('dark', false)
    await nextTick()

    expect(wrapper.get('img').attributes('src')).toBe(navyLogo)
  })

  it('does not recolor the files: no filter in the markup', () => {
    const html = mountLogo().html()

    expect(html).not.toContain('filter')
    expect(html).not.toContain('invert')
  })

  it('keeps the same window and offsets in both themes', async () => {
    const wrapper = mountLogo()
    const dark = wrapper.get('img').attributes('style')
    usePreferencesStore().setTheme('light')
    await nextTick()

    expect(wrapper.get('img').attributes('style')).toBe(dark)
  })
})
