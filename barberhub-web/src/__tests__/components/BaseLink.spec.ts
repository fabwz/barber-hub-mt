import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import BaseLink from '@/components/BaseLink.vue'

describe('BaseLink', () => {
  it('renders an anchor with its href and text', () => {
    const wrapper = mount(BaseLink, {
      props: { href: '/servicios' },
      slots: { default: 'Servicios' },
    })

    expect(wrapper.element.tagName).toBe('A')
    expect(wrapper.attributes('href')).toBe('/servicios')
    expect(wrapper.text()).toBe('Servicios')
    expect(wrapper.classes()).toContain('underline')
  })

  it('marks the current page with aria-current', () => {
    const wrapper = mount(BaseLink, { props: { href: '/', current: true } })

    expect(wrapper.attributes('aria-current')).toBe('page')
    expect(mount(BaseLink, { props: { href: '/' } }).attributes('aria-current')).toBeUndefined()
  })

  it('opens external links in a new tab with a safe rel', () => {
    const wrapper = mount(BaseLink, { props: { href: 'https://waze.com', external: true } })

    expect(wrapper.attributes('target')).toBe('_blank')
    expect(wrapper.attributes('rel')).toBe('noopener noreferrer')
  })
})
