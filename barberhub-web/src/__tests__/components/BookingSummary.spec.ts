import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import BookingSummary from '@/components/BookingSummary.vue'

const BASE = {
  serviceName: 'Corte clásico',
  barberName: 'Mauricio',
  date: '2026-11-07',
  startTime: '10:30',
  endTime: '11:00',
  price: 7000,
}

describe('BookingSummary', () => {
  it('lists the service, barber, date, range and total with VAT included', () => {
    const wrapper = mount(BookingSummary, { props: BASE })
    const text = wrapper.text()

    expect(wrapper.findAll('dt').map((t) => t.text())).toEqual([
      'Servicio',
      'Barbero',
      'Fecha',
      'Hora',
      'Total',
    ])
    expect(text).toContain('Corte clásico')
    expect(text).toContain('sáb 7 nov')
    expect(text).toContain('10:30–11:00')
    expect(text).toContain('₡7.000')
    expect(text).toContain('IVA incluido')
  })

  it('shows only the start when there is no end', () => {
    const wrapper = mount(BookingSummary, { props: { ...BASE, endTime: undefined } })

    expect(wrapper.text()).toContain('10:30')
    expect(wrapper.text()).not.toContain('–11')
  })

  it('a choice in progress has no status and no countdown', () => {
    const wrapper = mount(BookingSummary, { props: BASE })

    expect(wrapper.text()).not.toContain('Pendiente de pago')
    expect(wrapper.find('[role="timer"]').exists()).toBe(false)
  })

  it('a temporary reservation says it is pending and shows the time left', () => {
    const wrapper = mount(BookingSummary, {
      props: { ...BASE, status: 'temporary', remaining: '9:58' },
    })

    expect(wrapper.text()).toContain('Pendiente de pago')
    const timer = wrapper.get('[role="timer"]')
    expect(timer.text()).toContain('9:58')
  })

  it('a confirmed reservation says it is confirmed', () => {
    const wrapper = mount(BookingSummary, { props: { ...BASE, status: 'confirmed' } })

    expect(wrapper.text()).toContain('Confirmada')
    expect(wrapper.find('[role="timer"]').exists()).toBe(false)
  })
})
