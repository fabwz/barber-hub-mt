import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import StatusLabel from '@/components/StatusLabel.vue'

type Props = InstanceType<typeof StatusLabel>['$props']

const CASES: Array<[Props['status'], string, string]> = [
  ['temporary', 'Pendiente de pago', 'bg-warning'],
  ['confirmed', 'Confirmada', 'bg-accent'],
  ['completed', 'Atendida', 'bg-accent'],
  ['no_show', 'Ausente', 'bg-danger'],
  ['cancelled_by_customer', 'Cancelada', 'bg-danger'],
  ['cancelled_by_barbershop', 'Cancelada', 'bg-danger'],
  ['pending', 'Pago pendiente', 'bg-warning'],
  ['approved', 'Pago aprobado', 'bg-accent'],
  ['rejected', 'Pago rechazado', 'bg-danger'],
  ['failed', 'Pago fallido', 'bg-danger'],
]

describe('StatusLabel', () => {
  it.each(CASES)('%s shows "%s" with a %s dot', (status, text, dot) => {
    const wrapper = mount(StatusLabel, { props: { status } })

    expect(wrapper.text()).toBe(text)
    expect(wrapper.find('span > span').classes()).toContain(dot)
  })

  it.each([
    ['expired', 'Expirada'],
    ['refunded', 'Reembolsado'],
  ] as const)('%s is a ring with muted text', (status, text) => {
    const wrapper = mount(StatusLabel, { props: { status } })
    const dot = wrapper.find('span > span')

    expect(wrapper.text()).toBe(text)
    expect(wrapper.classes()).toContain('text-fg-muted')
    expect(dot.classes()).toEqual(
      expect.arrayContaining(['border', 'border-fg-muted', 'bg-transparent']),
    )
  })

  it('never uses the word "Pagado" and hides the 8 px dot from assistive technology', () => {
    const wrapper = mount(StatusLabel, { props: { status: 'approved' } })
    const dot = wrapper.find('span > span')

    expect(wrapper.text()).not.toMatch(/pagado/i)
    expect(dot.attributes('aria-hidden')).toBe('true')
    expect(dot.classes()).toContain('size-2')
  })

  it('shows only the text, with no dot, when it is plain', () => {
    const wrapper = mount(StatusLabel, { props: { status: 'confirmed', plain: true } })

    expect(wrapper.text()).toBe('Confirmada')
    expect(wrapper.find('span > span').exists()).toBe(false)
  })
})
