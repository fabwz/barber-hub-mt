import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import BookingPanel from '@/components/BookingPanel.vue'

const DAYS = [
  { value: '2026-11-06', closed: false },
  { value: '2026-11-07', closed: false },
]
const SLOTS = [
  { value: 's1', time: '09:00', barberName: 'Mauricio' },
  { value: 's2', time: '10:30', barberName: 'Daniela' },
]
const BARBERS = [
  { id: 'm', name: 'Mauricio' },
  { id: 'd', name: 'Daniela' },
]
const SELECTION = {
  serviceName: 'Corte clásico',
  barberName: 'Mauricio',
  date: '2026-11-07',
  startTime: '09:00',
  price: 7000,
}

const BASE = {
  hasService: true,
  barbers: BARBERS,
  days: DAYS,
  slots: SLOTS,
  hoursState: 'ready' as const,
  barber: '',
  date: '2026-11-07',
  slot: null,
}

beforeEach(() => vi.useFakeTimers())
afterEach(() => vi.useRealTimers())

describe('BookingPanel', () => {
  it('shows the empty state of the design while there is no service', () => {
    const wrapper = mount(BookingPanel, { props: { ...BASE, hasService: false } })

    expect(wrapper.text()).toContain('Elija un servicio')
    expect(wrapper.text()).toContain('Elija un servicio para continuar')
    expect(wrapper.find('select').exists()).toBe(false)
    expect(wrapper.findAll('button').map((b) => b.text())).toEqual(['Reservar'])
    expect(wrapper.get('button').attributes('disabled')).toBeDefined()
  })

  it('shows the steps in order: barber, date, hour and the Reservar button', () => {
    const wrapper = mount(BookingPanel, { props: BASE })

    expect(wrapper.findAll('h3').map((h) => h.text())).toEqual([
      'Servicio',
      'Barbero',
      'Fecha',
      'Hora',
    ])
    expect(
      wrapper
        .get('select')
        .findAll('option')
        .map((o) => o.text()),
    ).toEqual(['Sin preferencia', 'Mauricio', 'Daniela'])
    expect(wrapper.findAll('button').at(-1)!.text()).toBe('Reservar')
    expect(wrapper.text()).toContain('Elija una hora para continuar')
  })

  it('enables Reservar only with a chosen hour, and asks to reserve', async () => {
    const without = mount(BookingPanel, { props: BASE })
    expect(without.findAll('button').at(-1)!.attributes('disabled')).toBeDefined()

    const wrapper = mount(BookingPanel, {
      props: { ...BASE, slot: 's1', selection: SELECTION, total: 7000 },
    })
    const button = wrapper.findAll('button').at(-1)!
    expect(button.attributes('disabled')).toBeUndefined()
    expect(wrapper.text()).toContain('Corte clásico')
    expect(wrapper.text()).toContain('sáb 7 nov, 09:00')
    expect(wrapper.text()).toContain('₡7.000')

    await button.trigger('click')
    expect(wrapper.emitted('reserve')).toHaveLength(1)
  })

  it('shows «con quién» per hour only for «Sin preferencia»', () => {
    const any = mount(BookingPanel, { props: BASE })
    expect(any.text()).toContain('con Daniela')

    const one = mount(BookingPanel, { props: { ...BASE, barber: 'm' } })
    expect(one.text()).not.toContain('con Daniela')
  })

  it('shows skeletons while the hours load, marked as busy', () => {
    const wrapper = mount(BookingPanel, { props: { ...BASE, hoursState: 'loading' } })

    expect(wrapper.find('.animate-pulse').exists()).toBe(true)
    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true)
    expect(wrapper.find('[role="status"]').text()).toBe('Cargando horas disponibles.')
  })

  it('shows the error with «Reintentar» and keeps what was chosen', async () => {
    const wrapper = mount(BookingPanel, { props: { ...BASE, hoursState: 'error' } })

    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar los horarios.')
    expect(wrapper.get('select').element.value).toBe('')
    await wrapper.get('[role="alert"] button').trigger('click')
    expect(wrapper.emitted('retry')).toHaveLength(1)
  })

  it('offers the next days with hours when the day is empty, and picks one', async () => {
    const wrapper = mount(BookingPanel, {
      props: { ...BASE, hoursState: 'empty', slots: [], nextDays: ['2026-11-09'] },
    })

    expect(wrapper.text()).toContain('No quedan horas el sáb 7 nov.')
    const next = wrapper.findAll('button').find((b) => b.text() === 'Ver lun 9 nov')!
    await next.trigger('click')

    expect(wrapper.emitted('update:date')).toEqual([['2026-11-09']])
  })

  it('proposes «Sin preferencia» when a barber has no hours', async () => {
    const wrapper = mount(BookingPanel, {
      props: { ...BASE, barber: 'm', hoursState: 'empty', slots: [], nextDays: [] },
    })

    expect(wrapper.text()).toContain('No quedan horas el sáb 7 nov con Mauricio.')
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Sin preferencia')!
      .trigger('click')
    expect(wrapper.emitted('update:barber')).toEqual([['']])
  })

  it('shows the alternatives of a 409 as chips and picks one', async () => {
    const wrapper = mount(BookingPanel, {
      props: {
        ...BASE,
        slot: 's1',
        selection: SELECTION,
        failure: { title: 'Ese espacio ya no está disponible.', description: 'Elija otro.' },
        alternatives: [{ value: 'alt1', time: '09:15' }],
      },
    })

    expect(wrapper.text()).toContain('Otros espacios cercanos')
    expect(wrapper.get('[role="alert"]').text()).toContain('ya no está disponible')
    await wrapper
      .findAll('button')
      .find((b) => b.text() === '09:15')!
      .trigger('click')

    expect(wrapper.emitted('pick-alternative')).toEqual([['alt1']])
  })

  it('shows the notice of a space that was dropped', () => {
    const wrapper = mount(BookingPanel, {
      props: { ...BASE, notice: 'El espacio que había elegido ya no está disponible.' },
    })

    expect(wrapper.get('[role="note"]').text()).toContain('ya no está disponible')
  })

  it('shows the reservation with a countdown from the deadline of the API and a disabled payment', async () => {
    vi.setSystemTime(new Date('2030-03-05T10:00:00-06:00'))
    const wrapper = mount(BookingPanel, {
      props: {
        ...BASE,
        reservation: {
          ...SELECTION,
          status: 'temporary',
          expiresAt: '2030-03-05T10:10:00-06:00',
        },
      },
    })

    expect(wrapper.text()).toContain('Su reserva')
    expect(wrapper.get('[role="timer"]').text()).toContain('10:00')
    const pay = wrapper.findAll('button').find((b) => b.text() === 'Continuar al pago')!
    expect(pay.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('el pago llega con el siguiente issue')

    await vi.advanceTimersByTimeAsync(5000)
    expect(wrapper.get('[role="timer"]').text()).toContain('9:55')
  })

  it('counts 10 minutes when the API brings no deadline', () => {
    const wrapper = mount(BookingPanel, {
      props: { ...BASE, reservation: { ...SELECTION, status: 'temporary', expiresAt: null } },
    })

    expect(wrapper.get('[role="timer"]').text()).toContain('10:00')
  })

  it('says the reservation expired when the countdown ends, and offers to start again', async () => {
    vi.setSystemTime(new Date('2030-03-05T10:00:00-06:00'))
    const wrapper = mount(BookingPanel, {
      props: {
        ...BASE,
        reservation: { ...SELECTION, status: 'temporary', expiresAt: '2030-03-05T10:00:03-06:00' },
      },
    })

    await vi.advanceTimersByTimeAsync(3000)

    expect(wrapper.text()).toContain('La reserva temporal venció.')
    expect(wrapper.find('[role="timer"]').exists()).toBe(false)
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Elegir otro horario')!
      .trigger('click')
    expect(wrapper.emitted('restart')).toHaveLength(1)
  })

  it('a confirmed reservation has neither countdown nor payment button', () => {
    const wrapper = mount(BookingPanel, {
      props: { ...BASE, reservation: { ...SELECTION, status: 'confirmed', expiresAt: null } },
    })

    expect(wrapper.text()).toContain('Confirmada')
    expect(wrapper.find('[role="timer"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Continuar al pago')
  })

  it('announces the state in a polite live region', () => {
    const wrapper = mount(BookingPanel, { props: BASE })
    const live = wrapper.get('[aria-live="polite"]')

    expect(live.attributes('role')).toBe('status')
    expect(live.text()).toBe('2 horas disponibles el sáb 7 nov.')
  })
})
