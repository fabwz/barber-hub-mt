import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import ServiceRow from '@/components/ServiceRow.vue'

function row(durationMinutes: number, price = 10000, base = 8850, vat = 1150) {
  return mount(ServiceRow, { props: { name: 'Corte + barba', durationMinutes, price, base, vat } })
}

describe('ServiceRow', () => {
  it('shows name, duration and the price with VAT as ₡10.000', () => {
    const wrapper = row(45)

    expect(wrapper.element.tagName).toBe('LI')
    expect(wrapper.text()).toContain('Corte + barba')
    expect(wrapper.text()).toContain('45 min')
    expect(wrapper.text()).toContain('₡10.000')
    expect(wrapper.text()).toContain('IVA incluido: ₡1.150')
    expect(wrapper.text()).toContain('Base ₡8.850')
  })

  it('is flat, read-only and separated by a 1 px line', () => {
    const wrapper = row(45)

    expect(wrapper.classes()).toContain('border-b')
    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.attributes('role')).toBeUndefined()
    expect(wrapper.find('.tabular-nums').exists()).toBe(true)
  })

  it('formats long durations and small and large prices', () => {
    expect(row(60).text()).toContain('1 h')
    expect(row(90).text()).toContain('1 h 30 min')
    expect(row(30, 500, 442, 58).text()).toContain('₡500')
    expect(row(30, 1234567, 1, 1).text()).toContain('₡1.234.567')
  })
})
