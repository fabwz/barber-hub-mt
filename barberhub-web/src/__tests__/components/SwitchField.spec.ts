import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import SwitchField from '@/components/SwitchField.vue'

function build(modelValue: boolean) {
  const wrapper = mount(SwitchField, {
    props: {
      label: 'Reducir el movimiento',
      description: 'Quita las animaciones.',
      modelValue,
      'onUpdate:modelValue': (next: boolean) => wrapper.setProps({ modelValue: next }),
    },
  })
  return wrapper
}

describe('SwitchField', () => {
  it('is a switch named by its label and described by its description', () => {
    const wrapper = build(false)
    const button = wrapper.get('[role="switch"]')

    expect(wrapper.get(`#${button.attributes('aria-labelledby')}`).text()).toBe(
      'Reducir el movimiento',
    )
    expect(wrapper.get(`#${button.attributes('aria-describedby')}`).text()).toBe(
      'Quita las animaciones.',
    )
  })

  it('announces the state with aria-checked', async () => {
    const wrapper = build(false)
    const button = wrapper.get('[role="switch"]')
    expect(button.attributes('aria-checked')).toBe('false')

    await button.trigger('click')

    expect(button.attributes('aria-checked')).toBe('true')
    expect(wrapper.props('modelValue')).toBe(true)
  })

  it('toggles back and forth', async () => {
    const wrapper = build(true)

    await wrapper.get('[role="switch"]').trigger('click')

    expect(wrapper.props('modelValue')).toBe(false)
  })

  it('shows the state with the knob side and a check, not just with color', async () => {
    const wrapper = build(false)
    expect(wrapper.find('svg').exists()).toBe(false)
    expect(wrapper.get('[role="switch"] span span').classes()).toContain('translate-x-0')

    await wrapper.get('[role="switch"]').trigger('click')

    expect(wrapper.find('svg').exists()).toBe(true)
    expect(wrapper.get('[role="switch"] span span').classes()).toContain('translate-x-5')
  })

  it('has a 56 x 44 px target around a 48 x 28 px track', () => {
    const button = build(false).get('[role="switch"]')

    expect(button.classes()).toEqual(expect.arrayContaining(['h-11', 'w-14']))
    expect(button.get('span').classes()).toEqual(expect.arrayContaining(['h-7', 'w-12']))
  })

  it('takes the focus ring on the track, 1 px, 2 px apart', () => {
    const button = build(false).get('[role="switch"]')

    expect(button.classes()).toContain('focus-visible:outline-none')
    expect(button.get('span').classes()).toEqual(
      expect.arrayContaining([
        'group-focus-visible:outline-1',
        'group-focus-visible:outline-offset-2',
        'group-focus-visible:outline-accent',
      ]),
    )
  })
})
