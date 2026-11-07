import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import DatePicker from '@/components/DatePicker.vue'

const DAYS = [
  { value: '2026-11-06', closed: false },
  { value: '2026-11-07', closed: false },
  { value: '2026-11-08', closed: true },
]

function render(modelValue: string | null = '2026-11-07') {
  return mount(DatePicker, {
    props: { days: DAYS, modelValue },
    attachTo: document.body,
  })
}

/** The day buttons of the strip (not the «Otra fecha» button nor the calendar cells). */
const strip = (wrapper: ReturnType<typeof render>) => wrapper.findAll('[role="group"] button')

describe('DatePicker', () => {
  it('shows «Fecha», the month and one 56 × 72 button per day with a short date as name', () => {
    const wrapper = render()

    expect(wrapper.get('h3').text()).toBe('Fecha')
    expect(wrapper.text()).toContain('noviembre 2026')
    const buttons = strip(wrapper)
    expect(buttons.map((b) => b.attributes('aria-label'))).toEqual([
      'vie 6 nov',
      'sáb 7 nov',
      'dom 8 nov, sin horas',
    ])
    for (const button of buttons)
      expect(button.classes()).toEqual(expect.arrayContaining(['w-14', 'h-18']))
  })

  it('marks the day without hours with «–» and announces it as «sin horas»', () => {
    const closed = strip(render())[2]!

    expect(closed.text()).toContain('–')
    expect(closed.attributes('aria-disabled')).toBe('true')
    expect(closed.attributes('aria-label')).toContain('sin horas')
    expect(closed.classes()).toContain('cursor-not-allowed')
  })

  it('does not choose a day without hours, and chooses one with hours', async () => {
    const wrapper = render()
    const buttons = strip(wrapper)

    await buttons[2]!.trigger('click')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()

    await buttons[0]!.trigger('click')
    expect(wrapper.emitted('update:modelValue')).toEqual([['2026-11-06']])
  })

  it('shows the chosen day with aria-pressed, a surface and a heavier number', () => {
    const [first, second] = strip(render())

    expect(second!.attributes('aria-pressed')).toBe('true')
    expect(first!.attributes('aria-pressed')).toBe('false')
    expect(second!.classes()).toContain('bg-elevated')
    expect(second!.findAll('span')[1]!.classes()).toContain('font-medium')
  })

  it('has a single tab stop and moves focus with the arrows, Home and End', async () => {
    const wrapper = render()
    const buttons = strip(wrapper)

    expect(buttons.map((b) => b.attributes('tabindex'))).toEqual(['-1', '0', '-1'])
    ;(buttons[1]!.element as HTMLElement).focus()
    await buttons[1]!.trigger('keydown', { key: 'ArrowRight' })
    expect(document.activeElement).toBe(buttons[2]!.element)

    await buttons[2]!.trigger('keydown', { key: 'Home' })
    expect(document.activeElement).toBe(buttons[0]!.element)

    await buttons[0]!.trigger('keydown', { key: 'End' })
    expect(document.activeElement).toBe(buttons[2]!.element)
  })

  it('on a phone opens the month calendar with «Otra fecha» and explains the dashes', async () => {
    const wrapper = render()
    const toggle = wrapper.findAll('button').find((b) => b.text() === 'Otra fecha')!
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(wrapper.text()).not.toContain('Los días con guion')

    await toggle.trigger('click')

    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(wrapper.text()).toContain('Los días con guion no tienen horas disponibles.')
    const calendar = wrapper.get(`#${toggle.attributes('aria-controls')}`)
    // November 2026 starts on a Sunday: six blanks with Monday first.
    expect(calendar.findAll('span[aria-hidden="true"]')).toHaveLength(6)
    const cells = calendar.findAll('button')
    expect(cells).toHaveLength(30)
    expect(cells[5]!.attributes('aria-disabled')).toBeUndefined() // 6 nov
    expect(cells[7]!.attributes('aria-disabled')).toBe('true') // 8 nov, without hours
    expect(cells[0]!.attributes('aria-disabled')).toBe('true') // 1 nov, outside the days offered

    await cells[5]!.trigger('click')
    expect(wrapper.emitted('update:modelValue')).toEqual([['2026-11-06']])
    expect(toggle.attributes('aria-expanded')).toBe('false')
  })
})
