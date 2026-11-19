import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  VOUCHER_PAYMENT_STORAGE_KEY,
  readStoredVoucherPayment,
  writeStoredVoucherPayment,
} from '@/composables/usePayment'
import VoucherPurchaseView from '@/views/VoucherPurchaseView.vue'
import { jsonResponse, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { ONVO_KEY, ONVO_URL } from '../helpers/payment'
import {
  BOUGHT_ID,
  FREE_SHELF,
  ONVO_SHELF,
  PAYPAL_SHELF,
  VOUCHER_ID,
  VOUCHER_PAYMENT_ID,
  VOUCHER_PURCHASE_PAGE,
  bought,
  list,
  mockVoucherFetch,
  onSale,
  payment,
  signIn,
  type VoucherHandlers,
} from '../helpers/vouchers'

const STORED = {
  enlace: 'pura-navaja',
  bono: VOUCHER_ID,
  pago: VOUCHER_PAYMENT_ID,
  metodo: 'tarjeta',
}

// Every view mounted by a test is unmounted after it: a running poll must not reach the next test.
const mountedViews: VueWrapper[] = []

async function open(handlers: VoucherHandlers = {}, path = VOUCHER_PURCHASE_PAGE) {
  const mock = mockVoucherFetch(handlers)
  const mounted = await mountView(VoucherPurchaseView, path)
  mountedViews.push(mounted.wrapper)
  await flushPromises()
  await vi.waitFor(() => expect(mounted.wrapper.find('[aria-busy="true"]').exists()).toBe(false))
  await flushPromises()
  return { ...mounted, ...mock }
}

const radio = (wrapper: VueWrapper, value: string) =>
  wrapper.get(`[role="radio"][data-value="${value}"]`)
const input = (wrapper: VueWrapper, autocomplete: string) =>
  wrapper.get<HTMLInputElement>(`input[autocomplete="${autocomplete}"]`)
const button = (wrapper: VueWrapper, text: string) =>
  wrapper.findAll('button').find((b) => b.text() === text)

async function payWithCard(wrapper: VueWrapper) {
  await radio(wrapper, 'tarjeta').trigger('click')
  await flushPromises()
  await input(wrapper, 'cc-number').setValue('4242424242424242')
  await input(wrapper, 'cc-exp').setValue('1235')
  await input(wrapper, 'cc-csc').setValue('737')
  await input(wrapper, 'cc-name').setValue('Ana Mora')
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
  document.body.innerHTML = ''
})

describe('VoucherPurchaseView: summary', () => {
  it('shows the voucher, its full price with VAT included and that there is no deposit', async () => {
    const { wrapper } = await open()

    const text = wrapper.text()
    expect(wrapper.get('h1').text()).toBe('Resumen y pago')
    expect(text).toContain('Bono 5 cortes')
    expect(text).toContain('5 sesiones')
    expect(text).toContain('60 días')
    expect(text).toContain('Cuenta desde que se confirme el pago')
    expect(text).toContain('Corte clásico, Corte + barba')
    expect(text).toContain('₡45.000')
    expect(text).toContain('IVA incluido')
    expect(text).toContain('sin depósito ni saldo en el local')
    expect(text).not.toContain('Depósito a pagar ahora')
    expect(button(wrapper, 'Pagar ₡45.000')).toBeDefined()
  })

  it('has no countdown: a voucher is not held like a temporary reservation', async () => {
    const { wrapper } = await open()

    expect(wrapper.find('[role="timer"]').exists()).toBe(false)
  })
})

describe('VoucherPurchaseView: payment and confirmation', () => {
  it('sends only the method and the token, never the card or an amount, and waits for the confirmation', async () => {
    const { wrapper, calls, onvo } = await open({
      purchase: () => jsonResponse(201, { data: payment() }),
    })

    await payWithCard(wrapper)

    expect(onvo[0]!.url).toBe(ONVO_URL)
    const sent = calls('POST', '/compras')
    expect(sent).toHaveLength(1)
    expect(JSON.parse(sent[0]!.body)).toEqual({ metodo: 'tarjeta', token: 'pm_test_1' })
    expect(sent[0]!.body).not.toContain('4242')
    expect(sent[0]!.body).not.toContain('monto')
    // The payment is pending: the voucher is NOT active yet.
    expect(wrapper.text()).toContain('Esperando la confirmación de su pago…')
    expect(wrapper.text()).not.toContain('Bono activado')
    expect(readStoredVoucherPayment()).toMatchObject({ bono: VOUCHER_ID, pago: VOUCHER_PAYMENT_ID })
    // No card data is kept anywhere.
    expect(sessionStorage.getItem(VOUCHER_PAYMENT_STORAGE_KEY)).not.toContain('4242')
  })

  it('activates the voucher only when the status read from the API says approved, then shows «Bono activado»', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    vi.setSystemTime(new Date('2030-01-04T08:00:00-06:00'))
    let reads = 0
    const { wrapper, calls } = await open({
      status: () => {
        reads += 1
        return jsonResponse(200, {
          data: payment({ estado: reads < 3 ? 'pendiente' : 'aprobado' }),
        })
      },
      mine: () => jsonResponse(200, list([bought()])),
    })
    await payWithCard(wrapper)
    expect(wrapper.text()).toContain('Esperando la confirmación de su pago…')

    await vi.advanceTimersByTimeAsync(3000)
    await flushPromises()
    expect(wrapper.text()).not.toContain('Bono activado')
    await vi.advanceTimersByTimeAsync(3000)
    await vi.advanceTimersByTimeAsync(3000)
    await flushPromises()

    expect(calls('GET', `/pagos/${VOUCHER_PAYMENT_ID}`).length).toBeGreaterThanOrEqual(3)
    expect(wrapper.get('h1').text()).toBe('Bono activado')
    const text = wrapper.text()
    expect(text).toContain('Su pago de ₡45.000 fue aprobado y el bono ya está activo.')
    expect(text).toContain('Bono 5 cortes')
    expect(text).toContain('5 sesiones')
    expect(text).toContain('Vence el')
    expect(text).toContain('mar 5 mar 2030')
    expect(text).toContain('Corte clásico, Corte + barba')
    expect(wrapper.find('form').exists()).toBe(false)
    const links = wrapper.findAll('a').map((link) => [link.text(), link.attributes('href')])
    expect(links).toContainEqual(['Ver mis bonos', '/b/pura-navaja/bonos'])
    expect(links).toContainEqual(['Volver a la barbería', '/b/pura-navaja'])
    // Still one purchase: the polling only reads.
    expect(calls('POST', '/compras')).toHaveLength(1)
  })

  it('keeps asking when it comes back from the provider: the address never activates the voucher', async () => {
    writeStoredVoucherPayment(STORED)
    const { wrapper, calls } = await open(
      { status: () => jsonResponse(200, { data: payment({ estado: 'pendiente' }) }) },
      `${VOUCHER_PURCHASE_PAGE}?retorno=1`,
    )

    expect(calls('GET', `/pagos/${VOUCHER_PAYMENT_ID}`)).toHaveLength(1)
    expect(wrapper.text()).toContain('Esperando la confirmación de su pago…')
    expect(wrapper.text()).not.toContain('Bono activado')
  })

  it('resumes an approved purchase after a reload and shows «Bono activado»', async () => {
    writeStoredVoucherPayment(STORED)
    const { wrapper } = await open({
      status: () => jsonResponse(200, { data: payment({ estado: 'aprobado' }) }),
    })

    expect(wrapper.get('h1').text()).toBe('Bono activado')
  })

  it('forgets a purchase cancelled at the provider and shows the methods again', async () => {
    writeStoredVoucherPayment(STORED)
    const { wrapper, calls } = await open({}, `${VOUCHER_PURCHASE_PAGE}?retorno=1&cancelado=1`)

    expect(calls('GET', `/pagos/${VOUCHER_PAYMENT_ID}`)).toHaveLength(0)
    expect(readStoredVoucherPayment()).toBeNull()
    expect(wrapper.text()).toContain('Método de pago')
  })

  it('says why a payment was rejected, that the voucher stayed inactive, and lets the person choose again', async () => {
    const { wrapper } = await open({
      purchase: () =>
        jsonResponse(201, {
          data: payment({ estado: 'rechazado', motivo: 'Fondos insuficientes' }),
        }),
    })

    await payWithCard(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cobrar su pago.')
    expect(wrapper.text()).toContain('No se le cobró ni se activó el bono')
    expect(wrapper.text()).not.toContain('Bono activado')
    expect(readStoredVoucherPayment()).toBeNull()
    await button(wrapper, 'Elegir otro método')!.trigger('click')
    expect(wrapper.text()).toContain('Método de pago')
  })

  it('does not present as a success a payment that came in when the voucher could not be activated', async () => {
    const { wrapper } = await open({
      purchase: () =>
        jsonResponse(201, {
          data: payment({ estado: 'aprobado', devolucion_pendiente: true }),
        }),
    })

    await payWithCard(wrapper)

    expect(wrapper.text()).toContain('Recibimos su pago, pero el bono no pudo activarse.')
    expect(wrapper.text()).not.toContain('Bono activado')
  })

  it('shows the server message when the API refuses the purchase', async () => {
    const { wrapper } = await open({
      purchase: () =>
        problem(409, { type: 'conflicto', detail: 'Esta barbería no ofrece bonos por ahora.' }),
    })

    await payWithCard(wrapper)

    expect(wrapper.text()).toContain('No se pudo realizar el pago.')
    expect(wrapper.text()).toContain('Esta barbería no ofrece bonos por ahora.')
    expect(readStoredVoucherPayment()).toBeNull()
  })
})

describe('VoucherPurchaseView: who can buy', () => {
  it('does not offer to buy when the vouchers are turned off', async () => {
    const { wrapper } = await open({
      onSale: () => jsonResponse(200, list([], { bonos_activos: false })),
    })

    expect(wrapper.text()).toContain('Esta barbería no ofrece bonos por ahora')
    expect(wrapper.find('form').exists()).toBe(false)
    expect(button(wrapper, 'Pagar ₡45.000')).toBeUndefined()
  })

  it('offers nothing to the owner or a collaborator (403 prohibido)', async () => {
    const { wrapper } = await open({
      onSale: () => problem(403, { type: 'prohibido', title: 'Prohibido' }),
    })

    expect(wrapper.text()).toContain('Esta compra es para clientes')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('says so when the voucher is no longer on sale', async () => {
    const { wrapper } = await open({
      onSale: () => jsonResponse(200, list([onSale({ id: 'otro' })], ONVO_SHELF)),
    })

    expect(wrapper.text()).toContain('Este bono ya no está a la venta')
    expect(wrapper.find('form').exists()).toBe(false)
  })
})

describe('VoucherPurchaseView: loading and error states', () => {
  it('shows a skeleton while it loads, with aria-busy', async () => {
    let release: (response: Response) => void = () => {}
    mockVoucherFetch({
      onSale: () => new Promise<Response>((resolve) => (release = resolve)),
    })
    const { wrapper } = await mountView(VoucherPurchaseView, VOUCHER_PURCHASE_PAGE)
    mountedViews.push(wrapper)
    await flushPromises()

    expect(wrapper.get('main').attributes('aria-busy')).toBe('true')
    expect(wrapper.text()).toContain('Cargando el resumen')
    release(jsonResponse(200, list([onSale()], ONVO_SHELF)))
    await flushPromises()
    expect(wrapper.get('main').attributes('aria-busy')).toBeUndefined()
  })

  it('shows the error and loads again from «Reintentar»', async () => {
    let calls = 0
    const { wrapper } = await open({
      onSale: () => {
        calls += 1
        return calls === 1 ? problem(500) : jsonResponse(200, list([onSale()], ONVO_SHELF))
      },
    })
    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar el bono.')

    await button(wrapper, 'Reintentar')!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Método de pago')
  })
})

describe('voucher purchase: the bought voucher is the one the payment names', () => {
  it('uses the id of the voucher bought, not the one on sale, to find its expiry', async () => {
    writeStoredVoucherPayment(STORED)
    const { wrapper } = await open({
      status: () =>
        jsonResponse(200, { data: payment({ estado: 'aprobado', bono_comprado: BOUGHT_ID }) }),
      mine: () =>
        jsonResponse(
          200,
          list([
            bought({ id: 'otro', vence_en: '2031-01-01T08:00:00-06:00' }),
            bought({ vence_en: '2030-03-05T08:00:00-06:00' }),
          ]),
        ),
    })

    expect(wrapper.text()).toContain('mar 5 mar 2030')
    expect(wrapper.text()).not.toContain('2031')
  })
})

describe('VoucherPurchaseView: the gateway of the shop', () => {
  const approval = 'https://www.sandbox.paypal.com/checkoutnow?token=T1'
  const paypalShelf = () => jsonResponse(200, list([onSale()], PAYPAL_SHELF))
  const toPayPal = () =>
    jsonResponse(201, {
      data: payment({ accion_siguiente: { tipo: 'redireccion', url: approval } }),
    })

  it('with ONVO offers the card and SINPE Móvil, as before', async () => {
    const { wrapper } = await open({
      onSale: () => jsonResponse(200, list([onSale()], ONVO_SHELF)),
    })

    expect(radio(wrapper, 'tarjeta').text()).toBe('Tarjeta')
    expect(radio(wrapper, 'sinpe_movil').text()).toBe('SINPE Móvil')
    expect(wrapper.text()).toContain('Pago seguro con ONVO Pay')
    await radio(wrapper, 'tarjeta').trigger('click')
    expect(input(wrapper, 'cc-number').exists()).toBe(true)
  })

  it('offers only the methods the API lists for the gateway', async () => {
    const { wrapper } = await open({
      onSale: () =>
        jsonResponse(200, list([onSale()], { ...ONVO_SHELF, metodos_de_pago: ['tarjeta'] })),
    })

    expect(radio(wrapper, 'tarjeta').exists()).toBe(true)
    expect(wrapper.find('[role="radio"][data-value="sinpe_movil"]').exists()).toBe(false)
  })

  it('with PayPal says PayPal, shows no card form and sends only the method before going to PayPal', async () => {
    const assign = vi.fn()
    vi.stubGlobal('location', { assign })
    const { wrapper, calls, onvo } = await open({ onSale: paypalShelf, purchase: toPayPal })

    expect(radio(wrapper, 'tarjeta').text()).toBe('PayPal')
    expect(wrapper.find('[role="radio"][data-value="sinpe_movil"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Pago protegido por PayPal')

    await radio(wrapper, 'tarjeta').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Al pagar, se abre PayPal para que confirme el pago.')
    expect(wrapper.find('input[autocomplete="cc-number"]').exists()).toBe(false)
    expect(button(wrapper, 'Continuar a PayPal')).toBeDefined()

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const sent = calls('POST', '/compras')
    expect(sent).toHaveLength(1)
    expect(JSON.parse(sent[0]!.body)).toEqual({ metodo: 'tarjeta' })
    // Nothing goes to ONVO and the payment is kept to be resumed when PayPal sends the person back.
    expect(onvo).toEqual([])
    expect(assign).toHaveBeenCalledTimes(1)
    expect(assign).toHaveBeenCalledWith(approval)
    expect(readStoredVoucherPayment()).toMatchObject({ bono: VOUCHER_ID, pago: VOUCHER_PAYMENT_ID })
    expect(wrapper.text()).not.toContain('Bono activado')
  })

  it('does not go to PayPal twice on a double click', async () => {
    const assign = vi.fn()
    vi.stubGlobal('location', { assign })
    const { wrapper, calls } = await open({ onSale: paypalShelf, purchase: toPayPal })

    await radio(wrapper, 'tarjeta').trigger('click')
    await wrapper.get('form').trigger('submit')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(calls('POST', '/compras')).toHaveLength(1)
    expect(assign).toHaveBeenCalledTimes(1)
  })

  it('on the return from PayPal asks the API for the status and activates only if it says approved', async () => {
    writeStoredVoucherPayment({ ...STORED, metodo: 'tarjeta' })
    let status = 'pendiente'
    const { wrapper, calls } = await open(
      {
        onSale: paypalShelf,
        status: () => jsonResponse(200, { data: payment({ estado: status }) }),
      },
      `${VOUCHER_PURCHASE_PAGE}?retorno=1`,
    )

    // The address says nothing: the status is read, and while it is pending the voucher is not active.
    expect(calls('GET', `/pagos/${VOUCHER_PAYMENT_ID}`)).toHaveLength(1)
    expect(wrapper.text()).toContain('Esperando la confirmación de su pago…')
    expect(wrapper.text()).not.toContain('Bono activado')

    status = 'aprobado'
    await button(wrapper, 'Verificar ahora')!.trigger('click')
    await flushPromises()

    expect(calls('GET', `/pagos/${VOUCHER_PAYMENT_ID}`)).toHaveLength(2)
    expect(wrapper.get('h1').text()).toBe('Bono activado')
  })

  it('forgets a purchase cancelled at PayPal and shows PayPal again', async () => {
    writeStoredVoucherPayment(STORED)
    const { wrapper, calls } = await open(
      { onSale: paypalShelf },
      `${VOUCHER_PURCHASE_PAGE}?retorno=1&cancelado=1`,
    )

    expect(calls('GET', `/pagos/${VOUCHER_PAYMENT_ID}`)).toHaveLength(0)
    expect(readStoredVoucherPayment()).toBeNull()
    expect(radio(wrapper, 'tarjeta').text()).toBe('PayPal')
  })

  it('shows the message of the API and does not leave when a PayPal purchase is refused', async () => {
    const assign = vi.fn()
    vi.stubGlobal('location', { assign })
    const { wrapper } = await open({
      onSale: paypalShelf,
      purchase: () =>
        problem(503, {
          type: 'servicio-no-disponible',
          detail: 'PayPal no responde. Intente más tarde.',
        }),
    })

    await radio(wrapper, 'tarjeta').trigger('click')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('No se pudo realizar el pago.')
    expect(wrapper.text()).toContain('PayPal no responde. Intente más tarde.')
    expect(assign).not.toHaveBeenCalled()
    expect(readStoredVoucherPayment()).toBeNull()
    expect(button(wrapper, 'Continuar a PayPal')).toBeDefined()
  })

  it('does not offer to buy when the shop does not charge (no gateway)', async () => {
    const { wrapper, calls } = await open({
      onSale: () => jsonResponse(200, list([onSale()], FREE_SHELF)),
    })

    expect(wrapper.text()).toContain('Esta barbería no tiene bonos a la venta')
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.find('[role="radio"]').exists()).toBe(false)
    expect(calls('POST', '/compras')).toHaveLength(0)
  })
})
