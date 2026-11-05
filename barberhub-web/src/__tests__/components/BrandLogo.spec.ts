import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import BrandLogo from '@/components/BrandLogo.vue'

describe('BrandLogo', () => {
  it('has the alt «BarberHub» and explicit width and height', () => {
    const img = mount(BrandLogo).get('img')

    expect(img.attributes('alt')).toBe('BarberHub')
    expect(img.attributes('width')).toBe('93')
    expect(img.attributes('height')).toBe('93')
  })

  it('the window is the whole mark (1209 x 742 box of the PNG), 56 px wide by default', () => {
    const style = mount(BrandLogo).get('span').attributes('style') ?? ''

    expect(style).toContain('width: 56px')
    expect(style).toContain('height: 35px')
  })

  it('offsets the image by the margin of the PNG so nothing is cropped or left over', () => {
    const style = mount(BrandLogo).get('img').attributes('style') ?? ''
    const scale = 56 / 1209

    expect(style).toContain(`left: ${-489 * scale}px`)
    expect(style).toContain(`top: ${-576 * scale}px`)
    expect(style).toContain(`width: ${2000 * scale}px`)
  })

  it('scales the window and the image with size, keeping the ratio', () => {
    const wrapper = mount(BrandLogo, { props: { size: 90 } })

    expect(wrapper.get('span').attributes('style')).toContain('width: 90px')
    expect(wrapper.get('span').attributes('style')).toContain('height: 56px')
    expect(wrapper.get('img').attributes('width')).toBe('149')
    expect(wrapper.get('img').attributes('height')).toBe('149')
  })
})
