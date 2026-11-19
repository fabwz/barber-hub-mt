import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import AiBookingBlock from '@/components/AiBookingBlock.vue'

const SPACES = [
  { value: '2030-03-05T09:00:00-06:00', time: '09:00' },
  { value: '2030-03-05T10:30:00-06:00', time: '10:30' },
]

function build(props: Record<string, unknown> = {}) {
  return mount(AiBookingBlock, { props: { state: 'idle', open: true, phrase: '', ...props } })
}

const buttonOf = (wrapper: ReturnType<typeof build>, text: string) =>
  wrapper.findAll('button').find((b) => b.text() === text)!

beforeEach(() => vi.useFakeTimers())
afterEach(() => vi.useRealTimers())

describe('AiBookingBlock', () => {
  it('rests with the button disabled until there is a phrase', async () => {
    const wrapper = build()

    expect(buttonOf(wrapper, 'Generar propuesta').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Se interpreta con inteligencia artificial.')

    await wrapper.setProps({ phrase: '   ' })
    expect(buttonOf(wrapper, 'Generar propuesta').attributes('disabled')).toBeDefined()

    await wrapper.setProps({ phrase: 'un corte mañana' })
    expect(buttonOf(wrapper, 'Generar propuesta').attributes('disabled')).toBeUndefined()
  })

  it('announces the header state with aria-expanded and asks to change it', async () => {
    const wrapper = build({ open: false })
    const header = wrapper.get('button[aria-expanded]')

    expect(header.attributes('aria-expanded')).toBe('false')
    expect(header.attributes('aria-controls')).toBe(wrapper.get('form').element.parentElement!.id)
    await header.trigger('click')
    expect(wrapper.emitted('update:open')).toEqual([[true]])
  })

  it('emits submit with the phrase and nothing else, and never twice while loading', async () => {
    const wrapper = build({ phrase: 'un corte' })
    await wrapper.get('form').trigger('submit')
    expect(wrapper.emitted('submit')).toHaveLength(1)

    await wrapper.setProps({ state: 'loading' })
    expect(buttonOf(wrapper, 'Generando propuesta…').attributes('disabled')).toBeDefined()
    await wrapper.get('form').trigger('submit')
    expect(wrapper.emitted('submit')).toHaveLength(1)
  })

  it('announces that it is generating in a polite region and shows the skeleton', () => {
    const wrapper = build({ state: 'loading', phrase: 'un corte' })

    expect(wrapper.get('[role="status"]').text()).toBe('Generando propuesta.')
    expect(wrapper.get('[aria-busy="true"]').exists()).toBe(true)
    expect(wrapper.findAll('.animate-pulse').length).toBeGreaterThan(0)
  })

  it('marks no space as chosen until the person picks one, and emits the pick', async () => {
    const wrapper = build({ state: 'ready', spaces: SPACES, spacesFor: 'mar 5 mar' })
    const chips = wrapper.get('[role="group"]').findAll('button')

    expect(chips.map((c) => c.attributes('aria-pressed'))).toEqual(['false', 'false'])
    expect(chips[0]!.classes()).toContain('min-h-11')
    await chips[1]!.trigger('click')
    expect(wrapper.emitted('pick')).toEqual([[SPACES[1]!.value]])

    await wrapper.setProps({ selected: SPACES[1]!.value })
    expect(wrapper.get('[role="group"]').findAll('button')[1]!.attributes('aria-pressed')).toBe(
      'true',
    )
  })

  it('shows the error with «Reintentar», which asks again', async () => {
    const wrapper = build({ state: 'error', phrase: 'un corte' })

    expect(wrapper.get('[role="alert"]').text()).toContain('Puede continuar a mano')
    await buttonOf(wrapper, 'Reintentar').trigger('click')
    expect(wrapper.emitted('retry')).toHaveLength(1)
  })

  it('keeps «Reintentar» off during the wait the API asks for', async () => {
    const wrapper = build({ state: 'error', retryAfter: 2 })

    expect(wrapper.text()).toContain('Podrá intentar de nuevo en 2 s.')
    expect(buttonOf(wrapper, 'Reintentar').attributes('disabled')).toBeDefined()

    await vi.advanceTimersByTimeAsync(2000)
    expect(buttonOf(wrapper, 'Reintentar').attributes('disabled')).toBeUndefined()
  })
})
