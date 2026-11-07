import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import TimeSlots from '@/components/TimeSlots.vue'

const SLOTS = [
  { value: 'a', time: '09:00', barberName: 'Mauricio' },
  { value: 'b', time: '10:30', barberName: 'Daniela' },
  { value: 'c', time: '15:00', barberName: 'Mauricio', taken: true },
]

describe('TimeSlots', () => {
  it('groups the hours by period and shows each one as a pressable chip', () => {
    const wrapper = mount(TimeSlots, { props: { slots: SLOTS, modelValue: null } })

    expect(wrapper.get('[role="group"]').attributes('aria-label')).toBe('Hora')
    expect(
      wrapper.findAll('[role="group"] [role="group"]').map((g) => g.attributes('aria-label')),
    ).toEqual(['Mañana', 'Tarde'])
    const chips = wrapper.findAll('button')
    expect(chips.map((c) => c.text())).toEqual(['09:00', '10:30', '15:00'])
    expect(chips.every((c) => c.attributes('aria-pressed') === 'false')).toBe(true)
  })

  it('chooses a free hour', async () => {
    const wrapper = mount(TimeSlots, { props: { slots: SLOTS, modelValue: null } })

    await wrapper.findAll('button')[1]!.trigger('click')

    expect(wrapper.emitted('update:modelValue')).toEqual([['b']])
  })

  it('marks the chosen hour with aria-pressed and a heavier weight', () => {
    const wrapper = mount(TimeSlots, { props: { slots: SLOTS, modelValue: 'b' } })
    const chip = wrapper.findAll('button')[1]!

    expect(chip.attributes('aria-pressed')).toBe('true')
    expect(chip.classes()).toContain('font-medium')
    expect(chip.classes()).toContain('bg-elevated')
  })

  it('does not choose a taken hour and names it as taken', async () => {
    const wrapper = mount(TimeSlots, { props: { slots: SLOTS, modelValue: null } })
    const taken = wrapper.findAll('button')[2]!

    await taken.trigger('click')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(taken.attributes('aria-disabled')).toBe('true')
    expect(taken.attributes('aria-label')).toBe('15:00, ocupado')
    expect(taken.classes()).toContain('line-through')
  })

  it('says with whom each hour stays in «Sin preferencia»', () => {
    const wrapper = mount(TimeSlots, {
      props: { slots: SLOTS.slice(0, 2), modelValue: null, showBarber: true },
    })
    const chips = wrapper.findAll('button')

    expect(chips[0]!.text()).toContain('con Mauricio')
    expect(chips[1]!.text()).toContain('con Daniela')
    expect(chips[1]!.attributes('aria-label')).toBe('10:30 con Daniela')
  })

  it('does not name the barber when one was chosen', () => {
    const wrapper = mount(TimeSlots, { props: { slots: SLOTS, modelValue: null } })

    expect(wrapper.text()).not.toContain('con Mauricio')
  })

  it('is reachable and operable with the keyboard (native buttons)', async () => {
    const wrapper = mount(TimeSlots, {
      props: { slots: SLOTS, modelValue: null },
      attachTo: document.body,
    })
    const chip = wrapper.findAll('button')[0]!

    ;(chip.element as HTMLElement).focus()
    expect(document.activeElement).toBe(chip.element)
    expect(chip.element.tagName).toBe('BUTTON')
    expect(chip.classes()).toContain('min-h-11')
  })

  it('takes the group name from the label', () => {
    const wrapper = mount(TimeSlots, {
      props: { slots: SLOTS, modelValue: null, label: 'Otros espacios cercanos' },
    })

    expect(wrapper.get('[role="group"]').attributes('aria-label')).toBe('Otros espacios cercanos')
  })

  it('lays each period out on one line that scrolls sideways', () => {
    const wrapper = mount(TimeSlots, { props: { slots: SLOTS, modelValue: null } })
    const rows = wrapper.findAll('[role="group"] [role="group"] > div')

    expect(rows.length).toBeGreaterThan(0)
    for (const row of rows) {
      expect(row.classes()).toEqual(
        expect.arrayContaining(['flex', 'overflow-x-auto', 'scrollbar-none']),
      )
      expect(row.classes()).not.toContain('flex-wrap')
      expect(row.classes().some((c) => c.startsWith('grid'))).toBe(false)
    }
    for (const chip of wrapper.findAll('[role="group"] button')) {
      expect(chip.classes()).toContain('flex-none')
    }
  })

  it('on a phone offers a select with the hours and the taken ones disabled', async () => {
    const wrapper = mount(TimeSlots, { props: { slots: SLOTS, modelValue: null } })
    const select = wrapper.get('select')

    expect(select.findAll('option').map((o) => o.text())).toEqual([
      'Elija una hora',
      '09:00',
      '10:30',
      '15:00 ocupada',
    ])
    expect(select.findAll('option')[3]!.attributes('disabled')).toBeDefined()
    await select.setValue('b')
    expect(wrapper.emitted('update:modelValue')).toEqual([['b']])
  })
})
