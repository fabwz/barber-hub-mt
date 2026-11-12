import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import BookingDetailView from '@/views/BookingDetailView.vue'
import { jsonResponse, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { DETAIL_PAGE, detailed, mockBookingsFetch, signIn } from '../helpers/myBookings'

const mounted: VueWrapper[] = []

async function openDetail(handlers: Parameters<typeof mockBookingsFetch>[0] = {}) {
  const mock = mockBookingsFetch(handlers)
  const view = await mountView(BookingDetailView, DETAIL_PAGE)
  mounted.push(view.wrapper)
  await flushPromises()
  await vi.waitFor(() => expect(view.wrapper.find('main[aria-busy="true"]').exists()).toBe(false))
  return { ...view, ...mock }
}

const button = (wrapper: VueWrapper, text: string) =>
  wrapper.findAll('button').find((b) => b.text() === text)

beforeEach(() => {
  sessionStorage.clear()
  signIn()
})
afterEach(() => {
  for (const view of mounted.splice(0)) view.unmount()
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('BookingDetailView', () => {
  it('shows the reservation, its state and the free window as hours before the appointment', async () => {
    const { wrapper } = await openDetail()

    expect(wrapper.text()).toContain('Corte + barba')
    expect(wrapper.text()).toContain('Mauricio')
    expect(wrapper.text()).toContain('Confirmada')
    expect(wrapper.text()).toContain('hasta 4 horas antes de la cita')
    // No exact deadline is computed in the client.
    expect(wrapper.text()).not.toContain('Todavía está a tiempo')
    expect(document.activeElement).toBe(wrapper.get('h1').element)
  })

  it('offers «Reprogramar» and «Cancelar» only to a confirmed reservation that is still to come', async () => {
    const { wrapper } = await openDetail()
    expect(button(wrapper, 'Reprogramar')).toBeDefined()
    expect(button(wrapper, 'Cancelar')).toBeDefined()
  })

  it.each([
    [
      'a confirmed one in the past',
      { inicio: '2020-03-05T10:30:00-06:00', fin: '2020-03-05T11:15:00-06:00' },
    ],
    ['an attended one', { estado: 'completada' }],
    ['an absent one', { estado: 'ausente' }],
    ['a cancelled one', { estado: 'cancelada_por_cliente' }],
    ['an expired one', { estado: 'expirada' }],
  ])('offers no action to %s', async (_name, over) => {
    const { wrapper } = await openDetail({
      detail: () => jsonResponse(200, { data: detailed(over) }),
    })

    expect(button(wrapper, 'Reprogramar')).toBeUndefined()
    expect(button(wrapper, 'Cancelar')).toBeUndefined()
    expect(wrapper.text()).not.toContain('Continuar al pago')
    expect(wrapper.text()).toContain('Esta reserva ya no admite cambios ni cancelación.')
  })

  it('shows the state under the title as text only, with no dot', async () => {
    const { wrapper } = await openDetail()

    expect(wrapper.get('h1').element.nextElementSibling?.textContent?.trim()).toBe('Confirmada')
    expect(wrapper.find('main .rounded-full').exists()).toBe(false)
  })

  it('offers «Continuar al pago» to a temporary reservation, with what is due now', async () => {
    const { wrapper } = await openDetail({
      detail: () =>
        jsonResponse(200, {
          data: detailed({ estado: 'temporal', monto_a_pagar: 3333, saldo_en_local: 6667 }),
        }),
    })

    const pay = wrapper.findAll('a').find((a) => a.text() === 'Continuar al pago')
    expect(pay?.attributes('href')).toBe(`/b/pura-navaja/pago/${detailed().id}`)
    expect(wrapper.text()).toContain('₡3.333')
    expect(button(wrapper, 'Cancelar')).toBeUndefined()
  })

  it('shows the same empty state for a reservation that is not found', async () => {
    const { wrapper } = await openDetail({ detail: () => problem(404, { type: 'no-encontrado' }) })

    expect(wrapper.text()).toContain('No encontramos esta reserva')
  })

  it('shows the error with «Reintentar»', async () => {
    const { wrapper, count } = await openDetail({ detail: () => problem(500) })

    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar su reserva.')
    await button(wrapper, 'Reintentar')!.trigger('click')
    await flushPromises()
    expect(count('GET', `/reservas/${detailed().id}`)).toBe(2)
  })

  it('shows skeletons while loading', async () => {
    let release: () => void = () => {}
    const gate = new Promise<void>((resolve) => (release = resolve))
    mockBookingsFetch({
      detail: async () => {
        await gate
        return jsonResponse(200, { data: detailed() })
      },
    })
    const { wrapper } = await mountView(BookingDetailView, DETAIL_PAGE)
    mounted.push(wrapper)
    await vi.waitFor(() => expect(wrapper.find('.animate-pulse').exists()).toBe(true))
    release()
    await flushPromises()
    expect(wrapper.find('.animate-pulse').exists()).toBe(false)
  })
})
