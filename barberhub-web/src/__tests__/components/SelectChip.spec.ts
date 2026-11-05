import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import SelectChip from '@/components/SelectChip.vue'

describe('SelectChip', () => {
  it('is free by default: aria-pressed false and no check', () => {
    const wrapper = mount(SelectChip, { slots: { default: '10:30' } })

    expect(wrapper.attributes('aria-pressed')).toBe('false')
    expect(wrapper.find('svg').exists()).toBe(false)
    expect(wrapper.classes()).toContain('tabular-nums')
  })

  it('shows a 14 px check and aria-pressed true when selected', () => {
    const wrapper = mount(SelectChip, { props: { modelValue: true } })

    expect(wrapper.attributes('aria-pressed')).toBe('true')
    expect(wrapper.find('svg').attributes('width')).toBe('14')
  })

  it('toggles the v-model with a click and with the keyboard activation', async () => {
    const wrapper = mount(SelectChip, {
      props: {
        modelValue: false,
        'onUpdate:modelValue': (value: boolean | undefined) =>
          wrapper.setProps({ modelValue: value }),
      },
    })

    await wrapper.trigger('click')
    expect(wrapper.attributes('aria-pressed')).toBe('true')

    await wrapper.trigger('click')
    expect(wrapper.attributes('aria-pressed')).toBe('false')
  })

  it('is a focusable native button so Enter and Space activate it', () => {
    const wrapper = mount(SelectChip)

    expect(wrapper.element.tagName).toBe('BUTTON')
    expect(wrapper.attributes('tabindex')).toBeUndefined()
  })

  it('busy chips are aria-disabled, struck through and not selectable', async () => {
    const wrapper = mount(SelectChip, { props: { busy: true, modelValue: false } })

    expect(wrapper.attributes('aria-disabled')).toBe('true')
    expect(wrapper.classes()).toContain('line-through')
    await wrapper.trigger('click')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(wrapper.attributes('aria-pressed')).toBe('false')
  })
})
