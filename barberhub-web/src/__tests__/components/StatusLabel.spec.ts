import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import StatusLabel from '@/components/StatusLabel.vue'

type Props = InstanceType<typeof StatusLabel>['$props']

const CASES: Array<[Props['status'], string]> = [
  ['temporary', 'Pendiente de pago'],
  ['confirmed', 'Confirmada'],
  ['completed', 'Atendida'],
  ['no_show', 'Ausente'],
  ['cancelled_by_customer', 'Cancelada'],
  ['cancelled_by_barbershop', 'Cancelada'],
  ['pending', 'Pago pendiente'],
  ['approved', 'Pago aprobado'],
  ['rejected', 'Pago rechazado'],
  ['failed', 'Pago fallido'],
]

describe('StatusLabel', () => {
  it.each(CASES)('%s shows "%s" as text only, 14 px and weight 500', (status, text) => {
    const wrapper = mount(StatusLabel, { props: { status } })

    expect(wrapper.text()).toBe(text)
    expect(wrapper.classes()).toEqual(expect.arrayContaining(['text-sm', 'font-medium', 'text-fg']))
    expect(wrapper.classes()).not.toContain('text-fg-muted')
  })

  it.each([
    ['expired', 'Expirada'],
    ['refunded', 'Reembolsado'],
  ] as const)('%s is muted text', (status, text) => {
    const wrapper = mount(StatusLabel, { props: { status } })

    expect(wrapper.text()).toBe(text)
    expect(wrapper.classes()).toContain('text-fg-muted')
  })

  it('never draws a dot (yellow or any other): it is one element with only the text', () => {
    for (const [status] of CASES) {
      const wrapper = mount(StatusLabel, { props: { status } })

      expect(wrapper.element.children).toHaveLength(0)
      expect(wrapper.html()).not.toMatch(/bg-(warning|accent|danger)|rounded-full|size-2/)
    }
  })

  it('looks the same for every screen: the same status gives the same markup', () => {
    const first = mount(StatusLabel, { props: { status: 'pending' } })
    const second = mount(StatusLabel, { props: { status: 'pending' } })

    expect(first.html()).toBe(second.html())
  })

  it('never uses the word "Pagado"', () => {
    expect(mount(StatusLabel, { props: { status: 'approved' } }).text()).not.toMatch(/pagado/i)
  })
})
