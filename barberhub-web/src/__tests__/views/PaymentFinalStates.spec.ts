import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { PAYMENT_STORAGE_KEY, writeStoredPayment } from '@/composables/usePayment'
import { useBookingStore } from '@/stores/booking'
import { SESSION_STORAGE_KEY } from '@/stores/session'
import PaymentView from '@/views/PaymentView.vue'
import { jsonResponse, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { MAURICIO } from '../helpers/booking'
import {
  PAYMENT_ID,
  PAYMENT_PAGE,
  RESERVATION_ID,
  detail,
  mockPaymentFetch,
  payment,
  type PaymentHandlers,
} from '../helpers/payment'

// The final screens of the payment (issue #84), reached from the flow of PaymentView.
const RETURN_PAGE = `${PAYMENT_PAGE}?retorno=1`
const STORED = {
  enlace: 'pura-navaja',
  reserva: RESERVATION_ID,
  pago: PAYMENT_ID,
  metodo: 'sinpe_movil',
}

const mounted: VueWrapper[] = []

async function open(handlers: PaymentHandlers = {}, path = RETURN_PAGE) {
  const mock = mockPaymentFetch(handlers)
  const view = await mountView(PaymentView, path)
  mounted.push(view.wrapper)
  await flushPromises()
  await vi.waitFor(() => expect(view.wrapper.find('[aria-busy="true"]').exists()).toBe(false))
  await flushPromises()
  return { ...view, ...mock }
}

const button = (wrapper: VueWrapper, text: string) =>
  wrapper.findAll('button').find((b) => b.text() === text)

beforeEach(() => {
  sessionStorage.clear()
  sessionStorage.setItem(
    SESSION_STORAGE_KEY,
    JSON.stringify({ token: 'token', person: { email: 'ana@correo.cr' } }),
  )
  writeStoredPayment(STORED)
})
afterEach(() => {
  for (const view of mounted.splice(0)) view.unmount()
  vi.useRealTimers()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  document.body.innerHTML = ''
})

describe('Pago aprobado', () => {
  it('goes to the confirmed reservation', async () => {
    const { router } = await open({
      status: () => jsonResponse(200, { data: payment({ estado: 'aprobado' }) }),
    })

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('booking-confirmed'))
    expect(router.currentRoute.value.params).toMatchObject({
      enlace: 'pura-navaja',
      reserva: RESERVATION_ID,
    })
  })
})

describe('Pago rechazado', () => {
  const rejected = (over: Record<string, unknown> = {}) => ({
    status: () =>
      jsonResponse(200, { data: payment({ estado: 'rechazado', metodo: 'sinpe_movil', ...over }) }),
  })

  it('shows the reason of the API, keeps the reservation and the countdown, and moves the focus', async () => {
    const { wrapper, cancelCalls, posts } = await open(
      rejected({ motivo: 'El banco no autorizó el cobro' }),
    )

    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toContain('No pudimos cobrar su pago.')
    expect(alert.text()).toContain('Motivo: El banco no autorizó el cobro.')
    expect(alert.text()).toContain('Su reserva se mantiene; elija otro método para pagarla.')
    // The reservation is still there, with the time left to pay.
    expect(wrapper.text()).toContain('Corte + barba')
    expect(wrapper.get('[role="timer"]').text()).toContain('Su espacio se libera en')
    expect(cancelCalls()).toEqual([])
    expect(posts()).toEqual([])
    // Nothing but color carries it: icon and words. The title takes the focus.
    expect(document.activeElement?.textContent).toContain('No pudimos cobrar su pago.')
  })

  it('uses a generic message when the API gives no reason', async () => {
    const { wrapper } = await open(rejected({ motivo: null }))

    const alert = wrapper.get('[role="alert"]').text()
    expect(alert).toContain('El pago no se procesó y no se le cobró.')
    expect(alert).not.toContain('Motivo:')
  })

  it('lets the person choose another method without losing the reservation', async () => {
    const { wrapper, cancelCalls } = await open(rejected({ motivo: 'Fondos insuficientes.' }))
    expect(sessionStorage.getItem(PAYMENT_STORAGE_KEY)).toBeNull()

    await button(wrapper, 'Elegir otro método')!.trigger('click')
    await flushPromises()

    expect(wrapper.findAll('[role="radio"]').length).toBeGreaterThan(0)
    expect(wrapper.text()).toContain('Corte + barba')
    expect(cancelCalls()).toEqual([])
  })

  it('treats a failed payment (not rejected) as a retryable error', async () => {
    const { wrapper } = await open({
      status: () =>
        jsonResponse(200, { data: payment({ estado: 'fallido', metodo: 'sinpe_movil' }) }),
    })

    expect(wrapper.get('[role="alert"]').text()).toContain('No se pudo procesar su pago.')
    expect(wrapper.text()).not.toContain('No pudimos cobrar su pago.')
    await button(wrapper, 'Intentar de nuevo')!.trigger('click')
    await flushPromises()
    expect(wrapper.findAll('[role="radio"]').length).toBeGreaterThan(0)
  })
})

describe('Estamos verificando su pago', () => {
  const unreachable = { status: () => problem(503, { type: 'servicio-no-disponible' }) }

  it('shows it when the status answers 503, and does not allow another charge meanwhile', async () => {
    const { wrapper, posts } = await open(unreachable)

    const status = wrapper.get('[role="status"]:not(.sr-only)')
    expect(status.text()).toContain('Estamos verificando su pago')
    expect(status.text()).toContain('no se cobrará dos veces')
    expect(document.activeElement?.textContent).toContain('Estamos verificando su pago')
    // No method to choose and no way to start another payment while one is pending.
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.find('[role="radio"]').exists()).toBe(false)
    expect(button(wrapper, 'Elegir otro método')).toBeUndefined()
    expect(posts()).toEqual([])
  })

  it('«Verificar ahora» asks again, and a double click asks once', async () => {
    let answers = 0
    let release: (value: Response) => void = () => {}
    const { wrapper, statusCalls } = await open({
      status: () => {
        answers += 1
        if (answers === 1) return problem(503, { type: 'servicio-no-disponible' })
        return new Promise<Response>((resolve) => (release = resolve))
      },
    })
    expect(statusCalls()).toHaveLength(1)

    const check = button(wrapper, 'Verificar ahora')!
    await check.trigger('click')
    await check.trigger('click')
    await flushPromises()
    expect(statusCalls()).toHaveLength(2)

    release(jsonResponse(200, { data: payment({ estado: 'aprobado' }) }))
    await flushPromises()
    expect(statusCalls()).toHaveLength(2)
  })

  it('shows it when the payment is still pending after the wait of the polling ran out', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    vi.setSystemTime(new Date('2030-03-05T10:00:00-06:00'))
    const { wrapper, statusCalls } = await open({
      reservation: () =>
        jsonResponse(200, { data: detail({ vence_en: '2030-03-05T10:30:00-06:00' }) }),
      status: () =>
        jsonResponse(200, { data: payment({ estado: 'pendiente', metodo: 'sinpe_movil' }) }),
    })
    expect(wrapper.text()).toContain('Esperando la confirmación de su pago…')

    await vi.advanceTimersByTimeAsync(181_000)
    await flushPromises()

    expect(wrapper.text()).toContain('Estamos verificando su pago')
    const calls = statusCalls().length
    await vi.advanceTimersByTimeAsync(60_000)
    expect(statusCalls()).toHaveLength(calls)

    await button(wrapper, 'Verificar ahora')!.trigger('click')
    await flushPromises()
    expect(statusCalls()).toHaveLength(calls + 1)
  })
})

describe('Reserva vencida', () => {
  const afterDeadline = () =>
    jsonResponse(200, { data: detail({ vence_en: '2030-03-05T09:59:00-06:00' }) })

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    vi.setSystemTime(new Date('2030-03-05T10:00:00-06:00'))
  })

  it('shows it when vence_en already passed, says the space was freed and moves the focus', async () => {
    sessionStorage.removeItem(PAYMENT_STORAGE_KEY)
    const { wrapper } = await open({ reservation: afterDeadline }, PAYMENT_PAGE)

    const alert = wrapper.get('[role="alert"]').text()
    expect(alert).toContain('La reserva temporal venció.')
    expect(alert).toContain('el espacio se liberó')
    expect(wrapper.get('[aria-live="polite"]').text()).toBe('La reserva venció.')
    expect(wrapper.find('form').exists()).toBe(false)
    expect(button(wrapper, 'Elegir otro espacio')).toBeDefined()
  })

  it('shows it when the API says the reservation is no longer valid', async () => {
    sessionStorage.removeItem(PAYMENT_STORAGE_KEY)
    const { wrapper } = await open(
      {
        create: () =>
          problem(409, { type: 'plazo-vencido', detail: 'La reserva ya no está vigente.' }),
      },
      PAYMENT_PAGE,
    )
    await wrapper.get('[role="radio"][data-value="sinpe_movil"]').trigger('click')
    await wrapper.get('input[autocomplete="tel"]').setValue('8888 8888')
    await wrapper.get('select').setValue('0')
    await wrapper.get('input[autocomplete="off"]').setValue('01-1393-1919')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('La reserva temporal venció.')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('«Elegir otro espacio» goes back to the shop keeping the service, the barber and the day', async () => {
    sessionStorage.removeItem(PAYMENT_STORAGE_KEY)
    const { wrapper, router, cancelCalls } = await open(
      { reservation: afterDeadline },
      PAYMENT_PAGE,
    )

    await button(wrapper, 'Elegir otro espacio')!.trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('shop'))

    const booking = useBookingStore()
    expect(booking.serviceId).toBe('s2')
    expect(booking.barberId).toBe(MAURICIO)
    expect(booking.date).toBe('2030-03-05')
    expect(booking.slot).toBeNull()
    expect(cancelCalls()).toEqual([])
  })

  it('does not present as a success a payment approved after the reservation expired (PA-07)', async () => {
    const { wrapper, router } = await open({
      reservation: () =>
        jsonResponse(200, {
          data: detail({ estado: 'expirada', vence_en: null, monto_a_pagar: 0 }),
        }),
      status: () =>
        jsonResponse(200, {
          data: payment({ estado: 'aprobado', metodo: 'sinpe_movil', devolucion_pendiente: true }),
        }),
    })

    const alert = wrapper.get('[role="alert"]').text()
    expect(alert).toContain('Recibimos su pago, pero la reserva no pudo confirmarse.')
    expect(alert).toContain('pendiente de devolución')
    expect(alert).toContain('la gestionará la barbería')
    expect(wrapper.text()).not.toContain('fue aprobado')
    expect(router.currentRoute.value.name).toBe('payment')
    expect(button(wrapper, 'Elegir otro espacio')).toBeDefined()
  })
})
