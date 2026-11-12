import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { writeStoredPayment } from '@/composables/usePayment'
import { SESSION_STORAGE_KEY } from '@/stores/session'
import BookingConfirmedView from '@/views/BookingConfirmedView.vue'
import { jsonResponse, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import {
  PAYMENT_ID,
  RESERVATION_ID,
  detail,
  mockPaymentFetch,
  payment,
  type PaymentHandlers,
} from '../helpers/payment'

const CONFIRMED_PAGE = `/b/pura-navaja/reserva/${RESERVATION_ID}/confirmada`
const STORED = {
  enlace: 'pura-navaja',
  reserva: RESERVATION_ID,
  pago: PAYMENT_ID,
  metodo: 'tarjeta',
}

/** A confirmed reservation as the API gives it once paid: nothing left to pay now, the whole price as balance. */
const confirmed = (over: Record<string, unknown> = {}) =>
  detail({
    estado: 'confirmada',
    monto_a_pagar: 0,
    saldo_en_local: 10000,
    vence_en: null,
    expira_en: null,
    ...over,
  })

// A deposit of ₡3.000 out of ₡10.000, so that it differs from `monto_a_pagar` (0) and `saldo_en_local` (10000).
const approved = () => payment({ estado: 'aprobado', monto: 3000 })

const mounted: VueWrapper[] = []

/**
 * Mounts the view and waits for it to settle. With `settle: false` it only waits for the requests:
 * a view that sends the person to another page stays «loading» (mounted directly, nothing replaces it).
 */
async function open(
  handlers: PaymentHandlers = {},
  extra: Parameters<typeof mountView>[2] = [],
  settle = true,
) {
  const mock = mockPaymentFetch({
    reservation: () => jsonResponse(200, { data: confirmed() }),
    ...handlers,
  })
  const view = await mountView(BookingConfirmedView, CONFIRMED_PAGE, extra)
  mounted.push(view.wrapper)
  await flushPromises()
  if (settle) {
    await vi.waitFor(() => expect(view.wrapper.find('[aria-busy="true"]').exists()).toBe(false))
    await flushPromises()
  }
  return { ...view, ...mock }
}

beforeEach(() => {
  sessionStorage.clear()
  sessionStorage.setItem(
    SESSION_STORAGE_KEY,
    JSON.stringify({ token: 'token', person: { email: 'ana@correo.cr' } }),
  )
})
afterEach(() => {
  for (const view of mounted.splice(0)) view.unmount()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  document.body.innerHTML = ''
})

describe('BookingConfirmedView', () => {
  it('shows the service, barber, date, hour, the deposit of the PAYMENT and the balance', async () => {
    writeStoredPayment(STORED)
    const { wrapper } = await open({
      status: () => jsonResponse(200, { data: approved() }),
    })

    const text = wrapper.text()
    expect(wrapper.get('h1').text()).toBe('Reserva confirmada')
    expect(text).toContain('Su pago de ₡3.000 fue aprobado y el espacio es suyo.')
    expect(text).toContain('Corte + barba')
    expect(text).toContain('Mauricio')
    expect(text).toContain('10:30–11:15')
    // The title already says it: no «Confirmada» label with a dot.
    expect(text).not.toContain('Confirmada')

    // The deposit is the amount of the approved payment, not `monto_a_pagar` (0 after paying).
    const paid = wrapper.get('section[aria-labelledby="payment-title"] dl').text()
    expect(paid).toContain('Depósito pagado')
    expect(paid).toContain('₡3.000')
    expect(paid).not.toContain('₡0')
    // `saldo_en_local` of a confirmed reservation is the whole price: the balance is what the deposit left.
    expect(paid).toContain('Saldo en el local')
    expect(paid).toContain('₡7.000')
    expect(paid).not.toContain('₡10.000')
  })

  it('shows the cancellation deadline the API gives, and omits the line when it gives none', async () => {
    writeStoredPayment(STORED)
    const withDeadline = await open({ status: () => jsonResponse(200, { data: approved() }) })
    expect(withDeadline.wrapper.text()).toContain('hasta 24 horas antes de la cita')
    expect(withDeadline.wrapper.text()).toContain('El saldo se paga en el local.')

    const none = await open({
      status: () => jsonResponse(200, { data: approved() }),
      reservation: () => jsonResponse(200, { data: confirmed({ plazo_cancelacion_horas: 0 }) }),
    })
    expect(none.wrapper.text()).not.toContain('Puede cancelar o cambiar la hora')
  })

  it('opens directly, reading the reservation, and shows no deposit it cannot know', async () => {
    const { wrapper, statusCalls } = await open()

    expect(wrapper.get('h1').text()).toBe('Reserva confirmada')
    expect(wrapper.text()).toContain('Corte + barba')
    expect(wrapper.text()).toContain('₡10.000')
    expect(wrapper.text()).not.toContain('Depósito pagado')
    expect(wrapper.text()).not.toContain('fue aprobado')
    expect(statusCalls()).toEqual([])
  })

  it('still confirms the reservation when the provider does not answer for the payment', async () => {
    writeStoredPayment(STORED)
    const { wrapper } = await open({
      status: () => problem(503, { type: 'servicio-no-disponible' }),
    })

    expect(wrapper.get('h1').text()).toBe('Reserva confirmada')
    expect(wrapper.text()).not.toContain('Depósito pagado')
  })

  it('does not promise an email, because the system sends none when it confirms', async () => {
    writeStoredPayment(STORED)
    const { wrapper } = await open({ status: () => jsonResponse(200, { data: approved() }) })

    const main = wrapper.get('main').text()
    expect(main).toContain('Revise los datos de su cita aquí.')
    expect(main).not.toMatch(/correo|enviamos|email/i)
  })

  it('takes the focus to the title and announces the confirmation politely', async () => {
    const { wrapper } = await open()

    expect(document.activeElement).toBe(wrapper.get('h1').element)
    expect(wrapper.get('[aria-live="polite"]').text()).toBe(
      'Reserva confirmada. Revise los datos de su cita aquí.',
    )
  })

  it('offers «Volver a la barbería» and, now that the route my-bookings exists, «Ver mis reservas»', async () => {
    const { wrapper, router } = await open()
    const back = wrapper.findAll('a').find((a) => a.text() === 'Volver a la barbería')
    expect(back?.attributes('href')).toBe('/b/pura-navaja')
    expect(router.hasRoute('my-bookings')).toBe(true)

    const button = wrapper.findAll('button').find((b) => b.text() === 'Ver mis reservas')
    await button!.trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('my-bookings'))
    expect(router.currentRoute.value.path).toBe('/b/pura-navaja/mis-reservas')
  })

  it.each([
    ['a temporary reservation', confirmed({ estado: 'temporal' })],
    ['an expired reservation', confirmed({ estado: 'expirada' })],
  ])('sends %s to the payment page, which says what it is', async (_name, data) => {
    const { router } = await open({ reservation: () => jsonResponse(200, { data }) }, [], false)

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('payment'))
  })

  it('forgets the temporary reservation of the shop page, so it does not show «pago en proceso» again', async () => {
    const { useBookingStore } = await import('@/stores/booking')
    let release: (value: Response) => void = () => {}
    let asked = false
    mockPaymentFetch({
      reservation: () =>
        new Promise<Response>((resolve) => {
          asked = true
          release = resolve
        }),
    })
    const view = await mountView(BookingConfirmedView, CONFIRMED_PAGE)
    mounted.push(view.wrapper)
    const booking = useBookingStore()
    // The shop page still holds the reservation as it was created: temporary.
    booking.reservation = {
      reservation: { id: RESERVATION_ID },
      serviceName: 'Corte + barba',
      barberName: 'Mauricio',
    } as never

    await vi.waitFor(() => expect(asked).toBe(true))
    release(jsonResponse(200, { data: confirmed() }))
    await flushPromises()

    await vi.waitFor(() => expect(booking.reservation).toBeNull())
  })

  it('does not celebrate a payment that is not a clean approval', async () => {
    writeStoredPayment(STORED)
    const { router } = await open(
      {
        status: () =>
          jsonResponse(200, { data: payment({ estado: 'aprobado', devolucion_pendiente: true }) }),
      },
      [],
      false,
    )

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('payment'))
  })

  it('has its three states: loading, empty and error with retry', async () => {
    mockPaymentFetch({ reservation: () => new Promise<Response>(() => {}) })
    const loading = await mountView(BookingConfirmedView, CONFIRMED_PAGE)
    mounted.push(loading.wrapper)
    await flushPromises()
    expect(loading.wrapper.get('main').attributes('aria-busy')).toBe('true')

    const missing = await open({ reservation: () => problem(404, { type: 'no-encontrado' }) })
    expect(missing.wrapper.text()).toContain('No encontramos esta reserva')

    let calls = 0
    const failing = await open({
      reservation: () => {
        calls += 1
        return calls === 1 ? problem(500) : jsonResponse(200, { data: confirmed() })
      },
    })
    expect(failing.wrapper.get('[role="alert"]').text()).toContain('no pudimos cargar su reserva')
    await failing.wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()
    expect(failing.wrapper.get('h1').text()).toBe('Reserva confirmada')
  })

  it('needs a session', async () => {
    sessionStorage.clear()
    mockPaymentFetch()

    const { router } = await mountView(BookingConfirmedView, CONFIRMED_PAGE)

    expect(router.currentRoute.value.name).toBe('login')
  })
})
