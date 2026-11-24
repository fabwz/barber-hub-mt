import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'

import ImageField from '@/components/ImageField.vue'
import ShopHeaderPreview from '@/components/ShopHeaderPreview.vue'

afterEach(() => {
  vi.restoreAllMocks()
  document.body.innerHTML = ''
})

function mountPreview(width: number) {
  vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(width)
  return mount(ShopHeaderPreview, {
    slots: { default: '<p>cabecera</p>' },
    attachTo: document.body,
  })
}

describe('ShopHeaderPreview', () => {
  it('shrinks the page-wide header to a narrower card, keeping its desktop layout', async () => {
    const wrapper = mountPreview(500)
    await nextTick()
    const inner = wrapper.get('p').element.parentElement!
    expect(inner.style.width).toBe('1000px')
  })

  it('shows it as it is when the card is as wide as the page', () => {
    const wrapper = mountPreview(1100)
    const inner = wrapper.get('p').element.parentElement!
    expect(inner.style.width).toBe('')
  })
})

describe('ShopHeaderPreview: only to look at', () => {
  it('is inert and hidden from assistive technology, so no link, phone or menu can be used', () => {
    const wrapper = mountPreview(1100)
    const element = wrapper.element as HTMLElement & { inert?: boolean }
    expect(element.hasAttribute('inert') || element.inert === true).toBe(true)
    expect(wrapper.attributes('aria-hidden')).toBe('true')
  })
})

describe('ImageField shape', () => {
  it('draws a circle for a logo, a wide rectangle for a banner and a square by default', () => {
    const frame = (shape?: 'circle' | 'banner') =>
      mount(ImageField, { props: { label: 'Imagen', shape } })
        .get('fieldset > div > div')
        .classes()

    expect(frame('circle')).toContain('rounded-full')
    expect(frame('banner')).toEqual(expect.arrayContaining(['w-60', 'rounded-card']))
    expect(frame()).toContain('rounded-field')
  })
})
