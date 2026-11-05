import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import BaseButton from '@/components/BaseButton.vue'

describe('BaseButton', () => {
  it('renders a native button with the default slot and type button', () => {
    const wrapper = mount(BaseButton, { slots: { default: 'Reservar cita' } })

    expect(wrapper.element.tagName).toBe('BUTTON')
    expect(wrapper.attributes('type')).toBe('button')
    expect(wrapper.text()).toBe('Reservar cita')
  })

  it('uses the tonal fill for primary and a transparent one for secondary', () => {
    const primary = mount(BaseButton)
    const secondary = mount(BaseButton, { props: { variant: 'secondary' } })

    expect(primary.classes()).toContain('bg-elevated')
    expect(secondary.classes()).toContain('bg-transparent')
    expect(secondary.classes()).not.toContain('bg-elevated')
  })

  it('exposes aria-busy and blocks clicks while loading', async () => {
    const wrapper = mount(BaseButton, { props: { loading: true } })

    expect(wrapper.attributes('aria-busy')).toBe('true')
    expect(wrapper.attributes('disabled')).toBeDefined()
    await wrapper.trigger('click')
    expect(wrapper.emitted('click')).toBeUndefined()
  })

  it('has no aria-busy at rest', () => {
    expect(mount(BaseButton).attributes('aria-busy')).toBeUndefined()
  })

  it('is disabled when asked and keeps the 44 px target', () => {
    const wrapper = mount(BaseButton, { props: { disabled: true } })

    expect(wrapper.attributes('disabled')).toBeDefined()
    expect(wrapper.classes()).toContain('min-h-11')
  })

  it('renders the icon slot before the label', () => {
    const wrapper = mount(BaseButton, {
      slots: { icon: '<svg data-testid="icon" />', default: 'Guardar' },
    })

    expect(wrapper.find('[data-testid="icon"]').exists()).toBe(true)
  })

  it('is operable with the keyboard through the native click', async () => {
    const wrapper = mount(BaseButton, { slots: { default: 'Guardar' } })

    await wrapper.trigger('click')

    expect(wrapper.emitted('click')).toHaveLength(1)
  })
})
