import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { Sun } from 'lucide-vue-next'

import SegmentedControl from '@/components/SegmentedControl.vue'

const OPTIONS = [
  { value: 'a', label: 'Uno', icon: Sun },
  { value: 'b', label: 'Dos' },
  { value: 'c', label: 'Tres' },
]

function build(value = 'a') {
  const wrapper = mount(SegmentedControl<string>, {
    props: {
      label: 'Tema',
      options: OPTIONS,
      modelValue: value,
      'onUpdate:modelValue': (next: string) => wrapper.setProps({ modelValue: next }),
    },
    attachTo: document.body,
  })
  return wrapper
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('SegmentedControl', () => {
  it('is a radiogroup with a name and one radio per option', () => {
    const wrapper = build()

    expect(wrapper.get('[role="radiogroup"]').attributes('aria-label')).toBe('Tema')
    expect(wrapper.findAll('[role="radio"]').map((radio) => radio.text())).toEqual([
      'Uno',
      'Dos',
      'Tres',
    ])
  })

  it('announces the chosen option with aria-checked and gives it the only tab stop', () => {
    const radios = build('b').findAll('[role="radio"]')

    expect(radios.map((radio) => radio.attributes('aria-checked'))).toEqual([
      'false',
      'true',
      'false',
    ])
    expect(radios.map((radio) => radio.attributes('tabindex'))).toEqual(['-1', '0', '-1'])
  })

  it('does not rely on color alone: the chosen one has weight 500 and a filled surface', () => {
    const [first, second] = build('b').findAll('[role="radio"]')

    expect(second!.classes()).toEqual(expect.arrayContaining(['bg-elevated', 'font-medium']))
    expect(first!.classes()).toContain('font-normal')
  })

  it('chooses on click', async () => {
    const wrapper = build()

    await wrapper.findAll('[role="radio"]')[2]!.trigger('click')

    expect(wrapper.props('modelValue')).toBe('c')
  })

  it('moves the choice and the focus with the arrow keys, wrapping around, and with Home and End', async () => {
    const wrapper = build()
    const group = wrapper.get('[role="radiogroup"]')

    await group.trigger('keydown', { key: 'ArrowRight' })
    expect(wrapper.props('modelValue')).toBe('b')
    expect(document.activeElement).toBe(wrapper.findAll('[role="radio"]')[1]!.element)

    await group.trigger('keydown', { key: 'End' })
    expect(wrapper.props('modelValue')).toBe('c')

    await group.trigger('keydown', { key: 'ArrowDown' })
    expect(wrapper.props('modelValue')).toBe('a')

    await group.trigger('keydown', { key: 'ArrowLeft' })
    expect(wrapper.props('modelValue')).toBe('c')

    await group.trigger('keydown', { key: 'Home' })
    expect(wrapper.props('modelValue')).toBe('a')
  })

  it('ignores other keys', async () => {
    const wrapper = build()

    await wrapper.get('[role="radiogroup"]').trigger('keydown', { key: 'x' })

    expect(wrapper.props('modelValue')).toBe('a')
  })

  it('has 44 px targets and stacks in 52 px rows when the text is larger on a phone', () => {
    const wrapper = build()

    expect(wrapper.get('[role="radio"]').classes()).toEqual(
      expect.arrayContaining(['min-h-11', 'text-scaled:max-md:min-h-13']),
    )
    expect(wrapper.get('[role="radiogroup"]').classes()).toEqual(
      expect.arrayContaining(['text-scaled:max-md:flex-col', 'text-scaled:max-md:rounded-card']),
    )
  })

  it('hides the icon from assistive technology', () => {
    expect(build().get('svg').attributes('aria-hidden')).toBe('true')
  })
})
