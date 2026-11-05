import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import InlineNotice from '@/components/InlineNotice.vue'

describe('InlineNotice', () => {
  const wrapper = mount(InlineNotice, {
    props: { title: 'Texto de ejemplo.' },
    slots: { default: 'El contenido final lo define el negocio.' },
  })

  it('is a note with both sentences', () => {
    expect(wrapper.attributes('role')).toBe('note')
    expect(wrapper.text()).toBe('Texto de ejemplo. El contenido final lo define el negocio.')
  })

  it('has a 16 px decorative info icon', () => {
    const icon = wrapper.get('svg')
    expect(icon.attributes('width')).toBe('16')
    expect(icon.attributes('aria-hidden')).toBe('true')
  })

  it('first sentence weight 500, second in fg-muted, with no state color', () => {
    const [first, second] = wrapper.findAll('p > span')
    expect(first!.classes()).toContain('font-medium')
    expect(second!.classes()).toContain('text-fg-muted')
    expect(wrapper.html()).not.toMatch(/danger|warning/)
  })
})
