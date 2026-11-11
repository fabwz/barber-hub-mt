import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  PAYMENT_STORAGE_KEY,
  readStoredPayment,
  writeStoredPayment,
} from '@/composables/usePayment'
import { BOOKING_STORAGE_KEY, useBookingStore } from '@/stores/booking'
import { SESSION_STORAGE_KEY } from '@/stores/session'
import PaymentView from '@/views/PaymentView.vue'
import { jsonResponse, problem } from '../helpers/http'
import { MAURICIO } from '../helpers/booking'
import { mountView } from '../helpers/mountView'
import {
  ONVO_KEY,
  ONVO_URL,
  PAYMENT_ID,
  PAYMENT_PAGE,
  RESERVATION_ID,
  detail,
  mockPaymentFetch,
  payment,
  type PaymentHandlers,
} from '../helpers/payment'

const SHOP_ID = '0190b1f0-0000-7000-8000-000000000001'

type Wrapper = VueWrapper

function signIn() {
  sessionStorage.setItem(
    SESSION_STORAGE_KEY,
    JSON.stringify({ token: 'token', person: { email: 'ana@correo.cr' } }),
  )
}

// Every view mounted by a test is unmounted after it: a running poll must not reach the next test.
const mountedViews: VueWrapper[] = []

async function open(handlers: PaymentHandlers = {}, path = PAYMENT_PAGE) {
  const mock = mockPaymentFetch(handlers)
  const mounted = await mountView(PaymentView, path)
  mountedViews.push(mounted.wrapper)
  await flushPromises()
  // Under load the chain (shop, reservation, status) can outlast one flush: wait until nothing is loading.
  await vi.waitFor(() => expect(mounted.wrapper.find('[aria-busy="true"]').exists()).toBe(false))
  await flushPromises()
  return { ...mounted, ...mock }
}

const radio = (wrapper: Wrapper, value: string) =>
  wrapper.get(`[role="radio"][data-value="${value}"]`)
const input = (wrapper: Wrapper, autocomplete: string) =>
  wrapper.get<HTMLInputElement>(`input[autocomplete="${autocomplete}"]`)
const button = (wrapper: Wrapper, text: string) =>
  wrapper.findAll('button').find((b) => b.text() === text)

async function chooseMethod(wrapper: Wrapper, value: string) {
  await radio(wrapper, value).trigger('click')
  await flushPromises()
}

async function fillCard(wrapper: Wrapper) {
  await input(wrapper, 'cc-number').setValue('4242424242424242')
  await input(wrapper, 'cc-exp').setValue('1235')
  await input(wrapper, 'cc-csc').setValue('737')
  await input(wrapper, 'cc-name').setValue('Ana Mora')
}

async function fillSinpe(wrapper: Wrapper) {
  await input(wrapper, 'tel').setValue('8888 8888')
  await wrapper.get('select').setValue('0')
  await wrapper.get('input[autocomplete="off"]').setValue('01-1393-1919')
}

async function submit(wrapper: Wrapper) {
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

beforeEach(() => {
  sessionStorage.clear()
  signIn()
  vi.stubEnv('VITE_ONVO_PUBLISHABLE_KEY', ONVO_KEY)
  vi.stubEnv('VITE_ONVO_API_URL', 'https://api.onvopay.com/v1')
})
afterEach(() => {
  for (const view of mountedViews.splice(0)) view.unmount()
  vi.useRealTimers()
  vi.unstubAllEnvs()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  document.body.innerHTML = ''
})

describe('PaymentView: summary', () => {
  it('shows the amounts exactly as the API gives them, never calculated', async () => {
    // The API's own figures do not add up on purpose: the client must not recompute them.
    const { wrapper } = await open({
      reservation: () =>
        jsonResponse(200, {
          data: detail({ precio: 10000, monto_a_pagar: 3333, saldo_en_local: 6000 }),
        }),
    })

    const text = wrapper.text()
    expect(text).toContain('Corte + barba')
    expect(text).toContain('Mauricio')
    expect(text).toContain('mar 5 mar')
    expect(text).toContain('10:30')
    expect(text).toContain('₡10.000')
    expect(text).toContain('Depósito a pagar ahora')
    expect(text).toContain('₡3.333')
    expect(text).toContain('Saldo en el local')
    expect(text).toContain('₡6.000')
    expect(button(wrapper, 'Pagar ₡3.333')).toBeDefined()
  })

  it('shows no deposit rows when the amount to pay is the whole price', async () => {
    const { wrapper } = await open({
      reservation: () =>
        jsonResponse(200, { data: detail({ monto_a_pagar: 10000, saldo_en_local: 0 }) }),
    })

    expect(wrapper.text()).not.toContain('Depósito a pagar ahora')
    expect(wrapper.text()).not.toContain('Saldo en el local')
    expect(button(wrapper, 'Pagar ₡10.000')).toBeDefined()
  })

  it('says there is nothing to pay when the amount is 0', async () => {
    const { wrapper } = await open({
      reservation: () =>
        jsonResponse(200, {
          data: detail({
            monto_a_pagar: 0,
            saldo_en_local: 10000,
            pasarela: null,
            metodos_de_pago: [],
          }),
        }),
    })

    expect(wrapper.text()).toContain('Esta reserva no requiere pago.')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('has its three states: loading, error and empty', async () => {
    let release: (response: Response) => void = () => {}
    mockPaymentFetch({
      reservation: () => new Promise<Response>((resolve) => (release = resolve)),
    })
    const { wrapper } = await mountView(PaymentView, PAYMENT_PAGE)
    mountedViews.push(wrapper)
    await flushPromises()
    expect(wrapper.get('main').attributes('aria-busy')).toBe('true')
    expect(wrapper.text()).toContain('Cargando el resumen')
    release(jsonResponse(200, { data: detail() }))
    await flushPromises()
    expect(wrapper.get('main').attributes('aria-busy')).toBeUndefined()

    const failing = await open({ reservation: () => problem(500) })
    expect(failing.wrapper.text()).toContain('Error: no pudimos cargar su reserva.')
    const empty = await open({ reservation: () => problem(404, { type: 'no-encontrado' }) })
    expect(empty.wrapper.text()).toContain('No tiene una cita en curso')
  })

  it('retries the load from the error state', async () => {
    let calls = 0
    const { wrapper } = await open({
      reservation: () => {
        calls += 1
        return calls === 1 ? problem(500) : jsonResponse(200, { data: detail() })
      },
    })

    await button(wrapper, 'Reintentar')!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Método de pago')
  })
})

describe('PaymentView: countdown', () => {
  beforeEach(() => {
    // Only the clock and the interval are faked, so the promises keep flowing.
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    vi.setSystemTime(new Date('2030-03-05T10:00:00-06:00'))
  })

  it('counts down to vence_en, warns under two minutes and turns into an expired reservation', async () => {
    const { wrapper } = await open({
      reservation: () =>
        jsonResponse(200, { data: detail({ vence_en: '2030-03-05T10:02:10-06:00' }) }),
    })
    const live = wrapper.get('[aria-live="polite"]')

    expect(wrapper.get('[role="timer"]').text()).toContain('2:10')
    expect(wrapper.text()).not.toContain('Quedan menos de dos minutos.')
    expect(live.text()).toBe('')

    await vi.advanceTimersByTimeAsync(11_000)
    expect(wrapper.get('[role="timer"]').text()).toContain('1:59')
    expect(wrapper.text()).toContain('Quedan menos de dos minutos.')
    expect(live.text()).toBe('Quedan menos de dos minutos para pagar.')

    // It is not announced again every second.
    await vi.advanceTimersByTimeAsync(10_000)
    expect(wrapper.get('[role="timer"]').text()).toContain('1:49')
    expect(live.text()).toBe('Quedan menos de dos minutos para pagar.')

    await vi.advanceTimersByTimeAsync(110_000)
    expect(wrapper.text()).toContain('La reserva temporal venció')
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.find('[role="timer"]').exists()).toBe(false)
    expect(live.text()).toBe('La reserva venció.')
    expect(button(wrapper, 'Elegir otro espacio')).toBeDefined()
  })

  it('shows an expired reservation at once when vence_en already passed', async () => {
    const { wrapper } = await open({
      reservation: () =>
        jsonResponse(200, { data: detail({ vence_en: '2030-03-05T09:59:00-06:00' }) }),
    })

    expect(wrapper.text()).toContain('La reserva temporal venció')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('shows an expired reservation when the API says so', async () => {
    const { wrapper } = await open({
      reservation: () =>
        jsonResponse(200, {
          data: detail({ estado: 'expirada', vence_en: null, monto_a_pagar: 0 }),
        }),
    })

    expect(wrapper.text()).toContain('La reserva temporal venció')
  })

  it('has no countdown without vence_en', async () => {
    const { wrapper } = await open({
      reservation: () => jsonResponse(200, { data: detail({ vence_en: null }) }),
    })

    expect(wrapper.find('[role="timer"]').exists()).toBe(false)
    expect(wrapper.find('form').exists()).toBe(true)
  })
})

describe('PaymentView: methods', () => {
  it('offers the methods of ONVO and none is chosen until the person picks one', async () => {
    const { wrapper } = await open()

    expect(wrapper.findAll('[role="radio"]').map((r) => r.text())).toEqual([
      'Tarjeta',
      'SINPE Móvil',
    ])
    // The methods are tiles side by side, as in the design.
    expect(wrapper.get('[role="radiogroup"]').classes()).toContain('grid-cols-3')
    expect(
      wrapper.findAll('[role="radio"]').every((r) => r.attributes('aria-checked') === 'false'),
    ).toBe(true)

    await submit(wrapper)
    expect(wrapper.text()).toContain('Elija un método de pago.')
  })

  it('chooses the only method of PayPal and says it opens PayPal', async () => {
    const { wrapper, onvo } = await open({
      reservation: () =>
        jsonResponse(200, { data: detail({ pasarela: 'paypal', metodos_de_pago: ['tarjeta'] }) }),
    })

    expect(wrapper.findAll('[role="radio"]')).toHaveLength(1)
    expect(radio(wrapper, 'tarjeta').attributes('aria-checked')).toBe('true')
    // With PayPal the tile is called PayPal: the card is typed on its page.
    expect(radio(wrapper, 'tarjeta').text()).toBe('PayPal')
    expect(wrapper.text()).toContain('Al pagar, se abre PayPal para que confirme el pago.')
    expect(wrapper.text()).toContain('Pago protegido por PayPal')
    expect(wrapper.find('input[autocomplete="cc-number"]').exists()).toBe(false)
    expect(button(wrapper, 'Continuar a PayPal')).toBeDefined()
    expect(onvo).toEqual([])
  })

  it('says so when the gateway offers no method', async () => {
    const { wrapper } = await open({
      reservation: () =>
        jsonResponse(200, { data: detail({ pasarela: null, metodos_de_pago: [] }) }),
    })

    expect(wrapper.text()).toContain('No hay métodos de pago disponibles.')
  })
})

describe('PaymentView: card with ONVO', () => {
  it('creates the payment method in ONVO and sends only its id to our API', async () => {
    const consoles = (['log', 'info', 'warn', 'error', 'debug'] as const).map((name) =>
      vi.spyOn(console, name).mockImplementation(() => {}),
    )
    const { wrapper, onvo, api, posts } = await open({
      create: () => jsonResponse(201, { data: payment() }),
    })
    await chooseMethod(wrapper, 'tarjeta')
    await fillCard(wrapper)
    expect(input(wrapper, 'cc-number').element.value).toBe('4242 4242 4242 4242')
    expect(input(wrapper, 'cc-exp').element.value).toBe('12/35')

    await submit(wrapper)

    expect(onvo).toHaveLength(1)
    expect(onvo[0]!.url).toBe(ONVO_URL)
    expect(onvo[0]!.init.headers).toMatchObject({ Authorization: `Bearer ${ONVO_KEY}` })
    expect(JSON.parse(onvo[0]!.init.body as string)).toEqual({
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
    expect(posts()).toHaveLength(1)
    expect(JSON.parse(posts()[0]!.body)).toEqual({ metodo: 'tarjeta', token: 'pm_test_1' })

    // No request to our API carries the card, and nothing keeps it.
    for (const call of api) {
      expect(call.body).not.toMatch(/4242|737|12\/35|1235|Ana Mora/)
    }
    expect(JSON.parse(sessionStorage.getItem(PAYMENT_STORAGE_KEY)!)).toEqual({
      enlace: 'pura-navaja',
      reserva: RESERVATION_ID,
      pago: PAYMENT_ID,
      metodo: 'tarjeta',
    })
    const stored = JSON.stringify({ ...sessionStorage }) + JSON.stringify({ ...localStorage })
    expect(stored).not.toMatch(/4242|737|1235/)
    for (const spy of consoles) expect(JSON.stringify(spy.mock.calls)).not.toMatch(/4242|737/)
    expect(wrapper.html()).not.toContain('4242 4242')
  })

  it('validates the card before calling anyone and focuses the first problem', async () => {
    const { wrapper, onvo, posts } = await open()
    await chooseMethod(wrapper, 'tarjeta')
    await input(wrapper, 'cc-number').setValue('4242')
    await input(wrapper, 'cc-exp').setValue('1399')

    await submit(wrapper)

    expect(wrapper.text()).toContain('Ingrese el número de la tarjeta')
    expect(wrapper.text()).toContain('Ingrese el vencimiento como MM/AA')
    expect(wrapper.text()).toContain('Ingrese el código de 3 o 4 dígitos.')
    expect(wrapper.text()).toContain('Ingrese el nombre como aparece en la tarjeta.')
    expect(onvo).toEqual([])
    expect(posts()).toEqual([])
  })

  it('shows a configuration error and calls nobody when the key is not a test key', async () => {
    vi.stubEnv('VITE_ONVO_PUBLISHABLE_KEY', 'onvo_live_publishable_key_abc')
    const { wrapper, onvo, posts } = await open()
    await chooseMethod(wrapper, 'tarjeta')
    await fillCard(wrapper)

    await submit(wrapper)

    expect(wrapper.text()).toContain('El pago con tarjeta no está configurado.')
    expect(onvo).toEqual([])
    expect(posts()).toEqual([])
  })

  it('shows that ONVO did not accept the card and keeps what the person typed', async () => {
    const { wrapper, posts } = await open({ onvo: () => jsonResponse(400, { message: 'x' }) })
    await chooseMethod(wrapper, 'tarjeta')
    await fillCard(wrapper)

    await submit(wrapper)

    expect(wrapper.text()).toContain('Su tarjeta ha sido rechazada.')
    expect(posts()).toEqual([])
    expect(input(wrapper, 'cc-name').element.value).toBe('Ana Mora')
    expect(radio(wrapper, 'tarjeta').attributes('aria-checked')).toBe('true')
  })

  it('creates one payment on a double click and blocks the button meanwhile', async () => {
    let release: (response: Response) => void = () => {}
    const { wrapper, onvo, posts } = await open({
      create: () => new Promise<Response>((resolve) => (release = resolve)),
    })
    await chooseMethod(wrapper, 'tarjeta')
    await fillCard(wrapper)

    await wrapper.get('form').trigger('submit')
    await wrapper.get('form').trigger('submit')
    await vi.waitFor(() => expect(posts()).toHaveLength(1))

    const pay = button(wrapper, 'Procesando pago…')!
    expect(pay.attributes('disabled')).toBeDefined()
    expect(wrapper.get('main').attributes('aria-busy')).toBe('true')
    release(jsonResponse(201, { data: payment() }))
    await flushPromises()

    expect(onvo).toHaveLength(1)
    expect(posts()).toHaveLength(1)
  })

  it('shows the message of a 409, keeps the chosen method and wipes the card that was already used', async () => {
    const { wrapper } = await open({
      create: () => problem(409, { type: 'conflicto', detail: 'La reserva ya no está vigente.' }),
    })
    await chooseMethod(wrapper, 'tarjeta')
    await fillCard(wrapper)

    await submit(wrapper)

    expect(wrapper.text()).toContain('La reserva ya no está vigente.')
    expect(radio(wrapper, 'tarjeta').attributes('aria-checked')).toBe('true')
    // The card became a token as soon as ONVO answered: it is not kept to retype it for the person.
    expect(input(wrapper, 'cc-number').element.value).toBe('')
    expect(input(wrapper, 'cc-name').element.value).toBe('')
  })
})

describe('PaymentView: SINPE Móvil', () => {
  const instructions = {
    tipo: 'instrucciones',
    numero_destino: '+506 70196686',
    monto: 3333,
  }

  async function paySinpe(handlers: PaymentHandlers = {}) {
    const opened = await open({
      create: () =>
        jsonResponse(201, {
          data: payment({ metodo: 'sinpe_movil', accion_siguiente: instructions }),
        }),
      ...handlers,
    })
    await chooseMethod(opened.wrapper, 'sinpe_movil')
    await fillSinpe(opened.wrapper)
    await submit(opened.wrapper)
    return opened
  }

  it('sends the phone and the identification, then shows the instructions with copy buttons', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    const { wrapper, posts, onvo } = await paySinpe()

    expect(JSON.parse(posts()[0]!.body)).toEqual({
      metodo: 'sinpe_movil',
      telefono: '+50688888888',
      identificacion: '01-1393-1919',
      tipo_identificacion: '0',
    })
    expect(onvo).toEqual([])
    expect(wrapper.text()).toContain('+506 70196686')
    expect(wrapper.text()).toContain('₡3.333')
    expect(wrapper.text()).toContain('Esperando la confirmación de su pago…')

    await button(wrapper, 'Copiar')!.trigger('click')
    await flushPromises()
    expect(writeText).toHaveBeenCalledWith('+506 70196686')
    expect(wrapper.text()).toContain('Copiado')

    await wrapper.get('[aria-label="Copiar monto"]').trigger('click')
    await flushPromises()
    expect(writeText).toHaveBeenLastCalledWith('3333')
  })

  it('asks for the status every 3 seconds until the payment is approved, then stops', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    vi.setSystemTime(new Date('2030-03-05T10:00:00-06:00'))
    let answers = 0
    const { wrapper, statusCalls, router } = await paySinpe({
      status: () => {
        answers += 1
        return jsonResponse(200, {
          data: payment({ estado: answers >= 2 ? 'aprobado' : 'pendiente', metodo: 'sinpe_movil' }),
        })
      },
    })
    expect(statusCalls()).toHaveLength(0)

    await vi.advanceTimersByTimeAsync(3000)
    expect(statusCalls()).toHaveLength(1)
    expect(wrapper.text()).toContain('Esperando la confirmación de su pago…')

    await vi.advanceTimersByTimeAsync(3000)
    expect(statusCalls()).toHaveLength(2)
    // The confirmation is a lazy route: its chunk loads asynchronously, so it is awaited.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('booking-confirmed'))

    await vi.advanceTimersByTimeAsync(30_000)
    expect(statusCalls()).toHaveLength(2)
  })

  it('stops asking when the reservation expires', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    vi.setSystemTime(new Date('2030-03-05T10:00:00-06:00'))
    const { wrapper, statusCalls } = await paySinpe({
      reservation: () =>
        jsonResponse(200, { data: detail({ vence_en: '2030-03-05T10:00:07-06:00' }) }),
    })

    await vi.advanceTimersByTimeAsync(6000)
    expect(statusCalls()).toHaveLength(2)
    await vi.advanceTimersByTimeAsync(60_000)

    expect(statusCalls()).toHaveLength(2)
    expect(wrapper.text()).toContain('La reserva temporal venció')
  })

  it('«Verificar ahora» asks right away', async () => {
    const { wrapper, statusCalls } = await paySinpe()

    await button(wrapper, 'Verificar ahora')!.trigger('click')
    await flushPromises()

    expect(statusCalls()).toHaveLength(1)
  })

  it('clears the polling when the view is left', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    vi.setSystemTime(new Date('2030-03-05T10:00:00-06:00'))
    const { wrapper, statusCalls } = await paySinpe()

    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(30_000)

    expect(statusCalls()).toHaveLength(0)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('shows the messages of the API (422) under their fields', async () => {
    const { wrapper } = await open({
      create: () =>
        problem(422, {
          type: 'datos-invalidos',
          errores: { identificacion: ['La identificación no es válida.'] },
        }),
    })
    await chooseMethod(wrapper, 'sinpe_movil')
    await fillSinpe(wrapper)

    await submit(wrapper)

    expect(wrapper.text()).toContain('La identificación no es válida.')
    expect(input(wrapper, 'tel').element.value).toBe('8888-8888')
  })

  it('checks the form before sending', async () => {
    const { wrapper, posts } = await open()
    await chooseMethod(wrapper, 'sinpe_movil')

    await submit(wrapper)

    expect(wrapper.text()).toContain('Elija el tipo de identificación.')
    expect(posts()).toEqual([])
  })
})

describe('PaymentView: PayPal', () => {
  const approval = 'https://www.sandbox.paypal.com/checkoutnow?token=T1'

  it('saves the payment and goes to the approval address the API gives', async () => {
    let storedAtRedirect: unknown = null
    const assign = vi.fn(() => {
      storedAtRedirect = readStoredPayment()
    })
    vi.stubGlobal('location', { assign })
    const { wrapper, posts } = await open({
      reservation: () =>
        jsonResponse(200, { data: detail({ pasarela: 'paypal', metodos_de_pago: ['tarjeta'] }) }),
      create: () =>
        jsonResponse(201, {
          data: payment({ accion_siguiente: { tipo: 'redireccion', url: approval } }),
        }),
    })

    await submit(wrapper)

    expect(JSON.parse(posts()[0]!.body)).toEqual({ metodo: 'tarjeta' })
    expect(assign).toHaveBeenCalledTimes(1)
    expect(assign).toHaveBeenCalledWith(approval)
    expect(storedAtRedirect).toEqual({
      enlace: 'pura-navaja',
      reserva: RESERVATION_ID,
      pago: PAYMENT_ID,
      metodo: 'tarjeta',
    })
  })

  it('does not go twice on a double click', async () => {
    const assign = vi.fn()
    vi.stubGlobal('location', { assign })
    const { wrapper, posts } = await open({
      reservation: () =>
        jsonResponse(200, { data: detail({ pasarela: 'paypal', metodos_de_pago: ['tarjeta'] }) }),
      create: () =>
        jsonResponse(201, {
          data: payment({ accion_siguiente: { tipo: 'redireccion', url: approval } }),
        }),
    })

    await wrapper.get('form').trigger('submit')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(posts()).toHaveLength(1)
    expect(assign).toHaveBeenCalledTimes(1)
  })
})

describe('PaymentView: resuming a payment', () => {
  const stored = {
    enlace: 'pura-navaja',
    reserva: RESERVATION_ID,
    pago: PAYMENT_ID,
    metodo: 'tarjeta',
  }

  it('restores the stored payment, asks once for its status and keeps waiting if it is pending', async () => {
    writeStoredPayment(stored)
    const { wrapper, statusCalls, posts } = await open({}, `${PAYMENT_PAGE}?retorno=1`)

    expect(statusCalls()).toHaveLength(1)
    expect(posts()).toEqual([])
    expect(wrapper.text()).toContain('Esperando la confirmación de su pago…')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('goes to the confirmed reservation after the return of an approved payment', async () => {
    writeStoredPayment(stored)
    const { router } = await open(
      { status: () => jsonResponse(200, { data: payment({ estado: 'aprobado' }) }) },
      `${PAYMENT_PAGE}?retorno=1`,
    )

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('booking-confirmed'))
  })

  it('does not present as a success a payment that came in when it could no longer confirm the reservation', async () => {
    writeStoredPayment(stored)
    const { wrapper, router } = await open(
      {
        status: () =>
          jsonResponse(200, { data: payment({ estado: 'aprobado', devolucion_pendiente: true }) }),
        reservation: () =>
          jsonResponse(200, { data: detail({ estado: 'expirada', vence_en: null }) }),
      },
      `${PAYMENT_PAGE}?retorno=1`,
    )

    expect(router.currentRoute.value.name).toBe('payment')
    expect(wrapper.text()).toContain('Recibimos su pago, pero la reserva no pudo confirmarse.')
    expect(wrapper.text()).toContain('pendiente de devolución')
    expect(wrapper.text()).not.toContain('fue aprobado')
  })

  it('ignores a payment stored for another reservation', async () => {
    writeStoredPayment({ ...stored, reserva: 'otra-reserva' })
    const { wrapper, statusCalls } = await open()

    expect(statusCalls()).toEqual([])
    expect(wrapper.find('form').exists()).toBe(true)
  })

  it('says a card was rejected in Spanish, not with the text of the provider, and lets the person pay again', async () => {
    writeStoredPayment(stored)
    const { wrapper, posts, onvo } = await open(
      {
        status: () =>
          jsonResponse(200, {
            data: payment({ estado: 'rechazado', motivo: 'Transaction declined (test)' }),
          }),
      },
      `${PAYMENT_PAGE}?retorno=1`,
    )

    expect(wrapper.text()).toContain('No pudimos cobrar su pago.')
    expect(wrapper.text()).toContain('Motivo: su tarjeta fue rechazada.')
    expect(wrapper.text()).not.toContain('Transaction declined')
    expect(readStoredPayment()).toBeNull()
    expect(wrapper.find('form').exists()).toBe(false)

    // The reservation is kept and the form is back: another card makes a new payment.
    await button(wrapper, 'Elegir otro método')!.trigger('click')
    await flushPromises()
    await chooseMethod(wrapper, 'tarjeta')
    await fillCard(wrapper)
    await submit(wrapper)
    expect(onvo).toHaveLength(1)
    expect(posts()).toHaveLength(1)
    expect(JSON.parse(posts()[0]!.body)).toEqual({ metodo: 'tarjeta', token: 'pm_test_1' })
  })

  it('lets the person pay with SINPE Móvil after a rejected card', async () => {
    writeStoredPayment(stored)
    const { wrapper, posts, onvo } = await open(
      { status: () => jsonResponse(200, { data: payment({ estado: 'rechazado' }) }) },
      `${PAYMENT_PAGE}?retorno=1`,
    )

    await button(wrapper, 'Elegir otro método')!.trigger('click')
    await flushPromises()
    await chooseMethod(wrapper, 'sinpe_movil')
    await fillSinpe(wrapper)
    await submit(wrapper)

    expect(posts()).toHaveLength(1)
    expect(JSON.parse(posts()[0]!.body).metodo).toBe('sinpe_movil')
    expect(onvo).toEqual([])
  })

  it('shows the reason of a rejected SINPE payment and offers another method', async () => {
    writeStoredPayment({ ...stored, metodo: 'sinpe_movil' })
    const { wrapper } = await open(
      {
        status: () =>
          jsonResponse(200, {
            data: payment({
              estado: 'rechazado',
              metodo: 'sinpe_movil',
              motivo: 'Fondos insuficientes.',
            }),
          }),
      },
      `${PAYMENT_PAGE}?retorno=1`,
    )
    expect(wrapper.text()).toContain('Fondos insuficientes.')

    await button(wrapper, 'Elegir otro método')!.trigger('click')
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(true)
  })

  it('keeps the payment under verification when the provider does not answer, and asks again', async () => {
    writeStoredPayment(stored)
    let calls = 0
    const { wrapper, statusCalls, router } = await open(
      {
        status: () => {
          calls += 1
          return calls === 1
            ? problem(503, { type: 'servicio-no-disponible' })
            : jsonResponse(200, { data: payment({ estado: 'aprobado' }) })
        },
      },
      `${PAYMENT_PAGE}?retorno=1`,
    )
    await vi.waitFor(() => expect(wrapper.text()).toContain('Estamos verificando su pago'))

    await button(wrapper, 'Verificar ahora')!.trigger('click')
    await flushPromises()

    expect(statusCalls()).toHaveLength(2)
    expect(router.currentRoute.value.name).toBe('booking-confirmed')
  })

  it('shows that the payment was cancelled and lets the person choose again', async () => {
    writeStoredPayment(stored)
    const { wrapper, statusCalls } = await open({}, `${PAYMENT_PAGE}?retorno=1&cancelado=1`)

    expect(statusCalls()).toEqual([])
    expect(wrapper.text()).toContain('El pago se canceló.')
    expect(wrapper.findAll('[role="radio"]').length).toBeGreaterThan(0)
    expect(readStoredPayment()).toBeNull()
    expect(SHOP_ID).toBeTruthy()
  })
})

describe('PaymentView: «Cambiar reserva»', () => {
  const link = (wrapper: Wrapper) => button(wrapper, 'Cambiar reserva')
  const bookingStore = () => useBookingStore()

  it('offers a single «Cambiar reserva», with no other way to edit', async () => {
    const { wrapper } = await open()

    const labels = wrapper.findAll('button').map((b) => b.text())
    expect(labels.filter((text) => text === 'Cambiar reserva')).toHaveLength(1)
    expect(labels).not.toContain('Cambiar servicio u hora')
    expect(labels).not.toContain('Editar reserva')
  })

  it('cancels the temporary reservation and goes back keeping service, barber and day', async () => {
    const { wrapper, router, cancelCalls } = await open()

    await link(wrapper)!.trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('shop'))

    expect(cancelCalls()).toHaveLength(1)
    expect(router.currentRoute.value.path).toBe('/b/pura-navaja')
    const booking = bookingStore()
    expect(booking.serviceId).toBe('s2')
    expect(booking.barberId).toBe(MAURICIO)
    expect(booking.date).toBe('2030-03-05')
    // The old hour only travels as a suggestion; the shop view keeps it if it is still free.
    expect(booking.slot).toEqual({ start: '2030-03-05T10:30:00-06:00', barberId: MAURICIO })
    expect(booking.reservation).toBeNull()
    expect(JSON.parse(sessionStorage.getItem(BOOKING_STORAGE_KEY)!)).toMatchObject({
      serviceId: 's2',
      barberId: MAURICIO,
      date: '2030-03-05',
    })
  })

  it('shows the error and does not navigate when the cancellation fails', async () => {
    const { wrapper, router, cancelCalls } = await open({
      cancel: () => problem(500, { type: 'error-interno', title: 'Error', detail: 'Falló.' }),
    })

    await link(wrapper)!.trigger('click')
    await flushPromises()

    expect(cancelCalls()).toHaveLength(1)
    expect(router.currentRoute.value.name).toBe('payment')
    expect(wrapper.get('[role="alert"]').text()).toContain('no pudimos cambiar la reserva')
    expect(wrapper.get('[role="alert"]').text()).toContain('Su reserva sigue vigente')
    expect(bookingStore().serviceId).toBeNull()
    expect(link(wrapper)!.attributes('disabled')).toBeUndefined()
  })

  it('tries again from the error and goes back once it works', async () => {
    let attempt = 0
    const { wrapper, router, cancelCalls } = await open({
      cancel: () =>
        ++attempt === 1
          ? problem(500, { type: 'error-interno', title: 'Error', detail: 'Falló.' })
          : jsonResponse(200, { data: { estado: 'cancelada_por_cliente' } }),
    })

    await link(wrapper)!.trigger('click')
    await flushPromises()
    await button(wrapper, 'Reintentar')!.trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('shop'))

    expect(cancelCalls()).toHaveLength(2)
  })

  it('treats a 409 (already expired or cancelled) as released, without an error', async () => {
    const { wrapper, router } = await open({
      cancel: () => problem(409, { type: 'conflicto', title: 'Conflicto' }),
    })

    await link(wrapper)!.trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('shop'))

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(bookingStore().serviceId).toBe('s2')
  })

  it('blocks the button and announces it while the request is under way, and cancels once on a double click', async () => {
    let finish: (response: Response) => void = () => {}
    const { wrapper, router, cancelCalls } = await open({
      cancel: () => new Promise<Response>((resolve) => (finish = resolve)),
    })

    await link(wrapper)!.trigger('click')
    await link(wrapper)!.trigger('click')
    await flushPromises()

    expect(link(wrapper)!.attributes('disabled')).toBeDefined()
    expect(wrapper.get('[role="status"]').text()).toBe('Cambiando la reserva.')
    expect(router.currentRoute.value.name).toBe('payment')
    expect(cancelCalls()).toHaveLength(1)

    finish(jsonResponse(200, { data: { estado: 'cancelada_por_cliente' } }))
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('shop'))
    expect(cancelCalls()).toHaveLength(1)
  })

  it('is not offered while a SINPE payment is pending', async () => {
    writeStoredPayment({
      enlace: 'pura-navaja',
      reserva: RESERVATION_ID,
      pago: PAYMENT_ID,
      metodo: 'sinpe_movil',
    })
    const { wrapper, cancelCalls } = await open({}, `${PAYMENT_PAGE}?retorno=1`)

    expect(link(wrapper)).toBeUndefined()
    expect(cancelCalls()).toEqual([])
  })

  it('is not offered while the confirmation of PayPal is awaited', async () => {
    writeStoredPayment({
      enlace: 'pura-navaja',
      reserva: RESERVATION_ID,
      pago: PAYMENT_ID,
      metodo: 'tarjeta',
    })
    const { wrapper } = await open(
      { reservation: () => jsonResponse(200, { data: detail({ pasarela: 'paypal' }) }) },
      `${PAYMENT_PAGE}?retorno=1`,
    )

    expect(link(wrapper)).toBeUndefined()
  })

  it('is not offered once the payment is approved', async () => {
    writeStoredPayment({
      enlace: 'pura-navaja',
      reserva: RESERVATION_ID,
      pago: PAYMENT_ID,
      metodo: 'tarjeta',
    })
    const { wrapper } = await open(
      { status: () => jsonResponse(200, { data: payment({ estado: 'aprobado' }) }) },
      `${PAYMENT_PAGE}?retorno=1`,
    )

    expect(link(wrapper)).toBeUndefined()
  })

  it('is not offered once expired or with nothing to pay', async () => {
    const expired = await open({
      reservation: () =>
        jsonResponse(200, {
          data: detail({ estado: 'expirada', vence_en: null, monto_a_pagar: 0 }),
        }),
    })
    expect(link(expired.wrapper)).toBeUndefined()

    const free = await open({
      reservation: () =>
        jsonResponse(200, {
          data: detail({ monto_a_pagar: 0, pasarela: null, metodos_de_pago: [] }),
        }),
    })
    expect(link(free.wrapper)).toBeUndefined()
  })

  it('shows no late-cancellation notice or classification: a temporary has no deposit', async () => {
    const { wrapper } = await open({
      cancel: () =>
        jsonResponse(200, { data: { estado: 'cancelada_por_cliente', clasificacion: 'tardia' } }),
    })

    await link(wrapper)!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).not.toMatch(/tard[ií]a|a tiempo|clasificaci/i)
  })

  it('«Elegir otro espacio» of an expired reservation cancels nothing', async () => {
    const { wrapper, router, cancelCalls } = await open({
      reservation: () =>
        jsonResponse(200, {
          data: detail({ estado: 'expirada', vence_en: null, monto_a_pagar: 0 }),
        }),
    })

    await button(wrapper, 'Elegir otro espacio')!.trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('shop'))

    expect(cancelCalls()).toEqual([])
  })
})

describe('PaymentView: access', () => {
  it('needs a session', async () => {
    sessionStorage.clear()
    mockPaymentFetch()

    const { router } = await mountView(PaymentView, PAYMENT_PAGE)

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe(PAYMENT_PAGE)
  })
})
