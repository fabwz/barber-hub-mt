import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { readStoredPayment, writeStoredPayment } from '@/composables/usePayment'
import { SESSION_STORAGE_KEY } from '@/stores/session'
import PaymentReturnView from '@/views/PaymentReturnView.vue'
import { mountView } from '../helpers/mountView'
import { PAYMENT_ID, RESERVATION_ID } from '../helpers/payment'

const stored = {
  enlace: 'pura-navaja',
  reserva: RESERVATION_ID,
  pago: PAYMENT_ID,
  metodo: 'tarjeta',
}

beforeEach(() => {
  sessionStorage.clear()
  sessionStorage.setItem(
    SESSION_STORAGE_KEY,
    JSON.stringify({ token: 'token', person: { email: 'ana@correo.cr' } }),
  )
  // Nothing here may reach a server: the payment page is not asked for its data.
  vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')))
})
afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('PaymentReturnView', () => {
  it('says it is verifying and goes to the payment page of the stored reservation', async () => {
    writeStoredPayment(stored)

    const { wrapper, router } = await mountView(PaymentReturnView, '/pago/retorno')
    expect(wrapper.text()).toContain('Verificando su pago…')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('payment'))

    expect(router.currentRoute.value.path).toBe(`/b/pura-navaja/pago/${RESERVATION_ID}`)
    expect(router.currentRoute.value.query).toEqual({ retorno: '1' })
    // The payment stays stored: the payment page reads it to ask for the status.
    expect(readStoredPayment()).toEqual(stored)
  })

  it('goes to the home page when nothing was stored', async () => {
    const { router } = await mountView(PaymentReturnView, '/pago/retorno')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/')
  })

  it('goes to the home page when what was stored is not usable', async () => {
    sessionStorage.setItem('barberhub.payment', '{"enlace":"pura-navaja"}')

    const { router } = await mountView(PaymentReturnView, '/pago/retorno')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/')
  })

  it('passes a cancellation on and forgets the payment', async () => {
    writeStoredPayment(stored)

    const { router } = await mountView(PaymentReturnView, '/pago/retorno?cancelado=1')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('payment'))

    expect(router.currentRoute.value.path).toBe(`/b/pura-navaja/pago/${RESERVATION_ID}`)
    expect(router.currentRoute.value.query).toEqual({ retorno: '1', cancelado: '1' })
    expect(readStoredPayment()).toBeNull()
  })

  it('needs a session', async () => {
    sessionStorage.removeItem(SESSION_STORAGE_KEY)
    writeStoredPayment(stored)

    const { router } = await mountView(PaymentReturnView, '/pago/retorno')

    expect(router.currentRoute.value.name).toBe('login')
  })
})
