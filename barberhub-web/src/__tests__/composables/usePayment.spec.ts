import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  PAYMENT_STORAGE_KEY,
  createOnvoPaymentMethod,
  readStoredPayment,
  redirectTo,
  usePayment,
  writeStoredPayment,
} from '@/composables/usePayment'
import { jsonResponse, problem } from '../helpers/http'
import {
  ONVO_KEY,
  ONVO_URL,
  PAYMENT_ID,
  RESERVATION_ID,
  mockPaymentFetch,
  payment,
} from '../helpers/payment'

const CARD = {
  number: '4242424242424242',
  expMonth: 12,
  expYear: 2035,
  cvv: '737',
  holderName: 'Ana Mora',
}

beforeEach(() => {
  sessionStorage.clear()
  vi.stubEnv('VITE_ONVO_PUBLISHABLE_KEY', ONVO_KEY)
  vi.stubEnv('VITE_ONVO_API_URL', 'https://api.onvopay.com/v1')
})
afterEach(() => {
  vi.unstubAllEnvs()
  vi.unstubAllGlobals()
})

describe('createOnvoPaymentMethod', () => {
  it('creates the payment method in ONVO with the public key and the shape of the README', async () => {
    const mock = mockPaymentFetch()

    const id = await createOnvoPaymentMethod(CARD, 'ana@correo.cr')

    expect(id).toBe('pm_test_1')
    expect(mock.onvo).toHaveLength(1)
    const call = mock.onvo[0]!
    expect(call.url).toBe(ONVO_URL)
    expect(call.init.method).toBe('POST')
    expect(call.init.credentials).toBe('omit')
    expect(call.init.headers).toEqual({
      Authorization: `Bearer ${ONVO_KEY}`,
      'Content-Type': 'application/json',
    })
    expect(JSON.parse(call.init.body as string)).toEqual({
      type: 'card',
      card: {
        number: '4242424242424242',
        expMonth: 12,
        expYear: 2035,
        cvv: '737',
        holderName: 'Ana Mora',
      },
      billing: { name: 'Ana Mora', email: 'ana@correo.cr', address: { country: 'CR' } },
    })
    // Nothing of ours is called for this: no session token reaches ONVO.
    expect(mock.api).toEqual([])
  })

  it('leaves the email out of the billing data when it is not known', async () => {
    const mock = mockPaymentFetch()

    await createOnvoPaymentMethod(CARD, null)

    const body = JSON.parse(mock.onvo[0]!.init.body as string)
    expect(body.billing).toEqual({ name: 'Ana Mora', address: { country: 'CR' } })
  })

  it.each(['onvo_live_publishable_key_abc', 'pk_test_abc', ''])(
    'rejects the key «%s» without calling anyone',
    async (key) => {
      vi.stubEnv('VITE_ONVO_PUBLISHABLE_KEY', key)
      const mock = mockPaymentFetch()

      await expect(createOnvoPaymentMethod(CARD)).rejects.toMatchObject({ kind: 'configuracion' })

      expect(mock.fetchMock).not.toHaveBeenCalled()
    },
  )

  it('uses the address of the environment, without a trailing slash', async () => {
    vi.stubEnv('VITE_ONVO_API_URL', 'https://onvo.test/v9/')
    const mock = mockPaymentFetch()

    await createOnvoPaymentMethod(CARD)

    expect(mock.onvo[0]!.url).toBe('https://onvo.test/v9/payment-methods')
  })

  it('fails when ONVO refuses the card or does not answer', async () => {
    mockPaymentFetch({ onvo: () => jsonResponse(422, { message: 'invalid' }) })
    await expect(createOnvoPaymentMethod(CARD)).rejects.toMatchObject({ kind: 'tarjeta-rechazada' })

    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))
    await expect(createOnvoPaymentMethod(CARD)).rejects.toMatchObject({ kind: 'red' })
  })
})

describe('the stored payment', () => {
  it('keeps only the enlace, the reservation, the payment and the method', () => {
    writeStoredPayment({ enlace: 'pura-navaja', reserva: 'r1', pago: 'p1', metodo: 'tarjeta' })

    expect(JSON.parse(sessionStorage.getItem(PAYMENT_STORAGE_KEY)!)).toEqual({
      enlace: 'pura-navaja',
      reserva: 'r1',
      pago: 'p1',
      metodo: 'tarjeta',
    })
    expect(readStoredPayment()).toEqual({
      enlace: 'pura-navaja',
      reserva: 'r1',
      pago: 'p1',
      metodo: 'tarjeta',
    })
  })

  it('reads nothing from a broken or incomplete value, and survives a storage that throws', () => {
    sessionStorage.setItem(PAYMENT_STORAGE_KEY, '{not json')
    expect(readStoredPayment()).toBeNull()
    sessionStorage.setItem(PAYMENT_STORAGE_KEY, JSON.stringify({ enlace: 'a' }))
    expect(readStoredPayment()).toBeNull()

    const broken = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    expect(() =>
      writeStoredPayment({ enlace: 'a', reserva: 'b', pago: 'c', metodo: 'tarjeta' }),
    ).not.toThrow()
    broken.mockRestore()
  })
})

describe('usePayment', () => {
  const input = {
    shopId: '0190b1f0-0000-7000-8000-000000000001',
    enlace: 'pura-navaja',
    reservationId: RESERVATION_ID,
    method: 'tarjeta' as const,
    gateway: 'onvo' as const,
    card: CARD,
  }

  it('sends only the token to our API, wipes the card and stores the payment', async () => {
    const mock = mockPaymentFetch()
    const used = vi.fn()
    const payments = usePayment()

    const created = await payments.submit({ ...input, onCardUsed: used })

    expect(created?.id).toBe(PAYMENT_ID)
    expect(used).toHaveBeenCalledTimes(1)
    expect(mock.posts()).toHaveLength(1)
    expect(JSON.parse(mock.posts()[0]!.body)).toEqual({ metodo: 'tarjeta', token: 'pm_test_1' })
    expect(readStoredPayment()).toEqual({
      enlace: 'pura-navaja',
      reserva: RESERVATION_ID,
      pago: PAYMENT_ID,
      metodo: 'tarjeta',
    })
  })

  it('creates one payment on a double click', async () => {
    let release: (response: Response) => void = () => {}
    const mock = mockPaymentFetch({
      create: () => new Promise<Response>((resolve) => (release = resolve)),
    })
    const payments = usePayment()

    const first = payments.submit(input)
    const second = payments.submit(input)
    await vi.waitFor(() => expect(mock.posts()).toHaveLength(1))
    release(jsonResponse(201, { data: payment() }))
    await Promise.all([first, second])

    expect(mock.posts()).toHaveLength(1)
    expect(mock.onvo).toHaveLength(1)
  })

  it('resumes an active payment instead of creating another', async () => {
    const mock = mockPaymentFetch()
    const payments = usePayment()
    await payments.submit(input)

    const again = await payments.submit(input)

    expect(again?.id).toBe(PAYMENT_ID)
    expect(mock.posts()).toHaveLength(1)
  })

  it('sends no token with PayPal and follows the approval address after storing the payment', async () => {
    const approval = 'https://www.sandbox.paypal.com/checkoutnow?token=T1'
    const mock = mockPaymentFetch({
      create: () =>
        jsonResponse(201, {
          data: payment({ accion_siguiente: { tipo: 'redireccion', url: approval } }),
        }),
    })
    let storedAtRedirect: unknown = null
    const assign = vi.fn(() => {
      storedAtRedirect = readStoredPayment()
    })
    vi.stubGlobal('location', { assign })
    const payments = usePayment()

    await payments.submit({ ...input, gateway: 'paypal', card: undefined })

    expect(JSON.parse(mock.posts()[0]!.body)).toEqual({ metodo: 'tarjeta' })
    expect(mock.onvo).toEqual([])
    expect(assign).toHaveBeenCalledWith(approval)
    expect(storedAtRedirect).toMatchObject({ pago: PAYMENT_ID, metodo: 'tarjeta' })
    expect(payments.redirecting.value).toBe(true)
  })

  it('does not follow an address that is not http(s)', () => {
    const assign = vi.fn()
    vi.stubGlobal('location', { assign })

    expect(redirectTo('javascript:alert(1)')).toBe(false)
    expect(redirectTo('not a url')).toBe(false)
    expect(assign).not.toHaveBeenCalled()
    expect(redirectTo('https://pay.test/x')).toBe(true)
  })

  it('exposes the message of a 409 and a 422 without losing what was chosen', async () => {
    mockPaymentFetch({
      create: () => problem(409, { type: 'conflicto', detail: 'La reserva ya no está vigente.' }),
    })
    const payments = usePayment()

    expect(await payments.submit(input)).toBeNull()
    expect(payments.error.value?.status).toBe(409)
    expect(payments.error.value?.detail).toBe('La reserva ya no está vigente.')

    mockPaymentFetch({
      create: () =>
        problem(422, { type: 'datos-invalidos', errores: { telefono: ['Teléfono inválido.'] } }),
    })
    await payments.submit({
      ...input,
      method: 'sinpe_movil',
      card: undefined,
      sinpe: { phone: '+50688888888', idType: '0', identification: '01-1393-1919' },
    })
    expect(payments.error.value?.errors.telefono).toEqual(['Teléfono inválido.'])
  })

  it('turns a bad key into a configuration error and never calls our API', async () => {
    vi.stubEnv('VITE_ONVO_PUBLISHABLE_KEY', 'onvo_live_x')
    const mock = mockPaymentFetch()
    const payments = usePayment()

    await payments.submit(input)

    expect(payments.error.value?.type).toBe('configuracion')
    expect(mock.fetchMock).not.toHaveBeenCalled()
    expect(payments.submitting.value).toBe(false)
  })

  it('restores the stored payment only for the same enlace and reservation', () => {
    writeStoredPayment({
      enlace: 'pura-navaja',
      reserva: RESERVATION_ID,
      pago: PAYMENT_ID,
      metodo: 'sinpe_movil',
    })
    const other = usePayment()
    expect(other.restore('otra-barberia', RESERVATION_ID)).toBeNull()
    expect(other.restore('pura-navaja', 'otra')).toBeNull()
    expect(other.paymentId.value).toBeNull()

    const payments = usePayment()
    expect(payments.restore('pura-navaja', RESERVATION_ID)?.pago).toBe(PAYMENT_ID)
    expect(payments.paymentId.value).toBe(PAYMENT_ID)
  })

  it('forgets a refused payment so the person chooses again', async () => {
    mockPaymentFetch({
      status: () =>
        jsonResponse(200, {
          data: payment({ estado: 'rechazado', motivo: 'Fondos insuficientes.' }),
        }),
    })
    writeStoredPayment({
      enlace: 'pura-navaja',
      reserva: RESERVATION_ID,
      pago: PAYMENT_ID,
      metodo: 'tarjeta',
    })
    const payments = usePayment()
    payments.restore('pura-navaja', RESERVATION_ID)

    const read = await payments.refresh('0190b1f0-0000-7000-8000-000000000001')

    expect(read?.estado).toBe('rechazado')
    expect(readStoredPayment()).toBeNull()
  })
})
