import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { todayInCostaRica } from '@/composables/format'
import ShopView from '@/views/ShopView.vue'
import {
  MAURICIO,
  RESERVATION,
  availabilityBody,
  bookingHandler,
  group,
  slot,
} from '../helpers/booking'
import { jsonResponse, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import {
  CUT_BEARD,
  bought,
  expired,
  list,
  mockVoucherFetch,
  signIn,
  type VoucherHandlers,
} from '../helpers/vouchers'

const TODAY = todayInCostaRica()
const at = (time: string) => `${TODAY}T${time}:00-06:00`

/** Mauricio has one free hour: 09:00. */
function oneHour(request: Request) {
  const date = new URL(request.url).searchParams.get('fecha')!
  return jsonResponse(
    200,
    availabilityBody(date, [
      group(
        MAURICIO,
        'Mauricio',
        slot(MAURICIO, `${date}T09:00:00-06:00`, `${date}T09:30:00-06:00`),
      ),
    ]),
  )
}

/** The reservation paid with a voucher is confirmed at once, with nothing to pay. */
const confirmed = () =>
  jsonResponse(201, {
    data: {
      ...RESERVATION,
      estado: 'confirmada',
      inicio: at('09:00'),
      fin: at('09:30'),
      expira_en: null,
      monto_a_pagar: 0,
      saldo_en_local: 7000,
      vence_en: null,
      pasarela: null,
      metodos_de_pago: [],
    },
  })

const mounted: VueWrapper[] = []

async function open(handlers: VoucherHandlers = {}, reserve = confirmed) {
  const mock = mockVoucherFetch({
    ...handlers,
    other: bookingHandler({ availability: oneHour, reserve }),
  })
  const view = await mountView(ShopView, '/b/pura-navaja')
  mounted.push(view.wrapper)
  await flushPromises()
  return { ...view, ...mock }
}

async function chooseService(wrapper: VueWrapper, index: number) {
  await wrapper.findAll('[role="radio"]')[index]!.trigger('click')
  await flushPromises()
}

const chip = (wrapper: VueWrapper, time: string) =>
  wrapper.findAll('button[aria-pressed]').find((b) => b.text().startsWith(time))!
const button = (wrapper: VueWrapper, text: string) =>
  wrapper.findAll('button').find((b) => b.text() === text)
const payOption = (wrapper: VueWrapper) => wrapper.find('[role="radio"][data-value="bono"]')

beforeEach(() => {
  sessionStorage.clear()
  signIn()
})
afterEach(() => {
  for (const view of mounted.splice(0)) view.unmount()
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('Reserve with a voucher: when it is offered', () => {
  it('offers «Pagar con bono» once a service a voucher of the person covers is chosen', async () => {
    const { wrapper } = await open()
    expect(payOption(wrapper).exists()).toBe(false)

    await chooseService(wrapper, 0)

    const option = payOption(wrapper)
    expect(option.exists()).toBe(true)
    expect(option.text()).toContain('Pagar con bono')
    expect(option.text()).toContain('Se usará 1 sesión')
    expect(option.text()).toContain('sin depósito')
    // The usual payment stays chosen until the person picks the voucher.
    expect(wrapper.get('[role="radio"][data-value="metodo"]').attributes('aria-checked')).toBe(
      'true',
    )
    expect(button(wrapper, 'Reservar')).toBeDefined()
  })

  it('does not offer it for a service the voucher does not cover', async () => {
    const { wrapper } = await open({
      mine: () => jsonResponse(200, list([bought({ servicios: [CUT_BEARD] })])),
    })

    await chooseService(wrapper, 0)
    expect(payOption(wrapper).exists()).toBe(false)

    await chooseService(wrapper, 1)
    expect(payOption(wrapper).exists()).toBe(true)
  })

  it('does not offer it with an expired voucher or one with no sessions left', async () => {
    const { wrapper } = await open({
      mine: () =>
        jsonResponse(
          200,
          list([expired({ servicios: [CUT_BEARD] }), bought({ saldo: 0, servicios: [CUT_BEARD] })]),
        ),
    })

    await chooseService(wrapper, 1)

    expect(payOption(wrapper).exists()).toBe(false)
  })

  it('does not offer it when the shop has the vouchers turned off, even with a voucher already bought', async () => {
    const { wrapper } = await open({
      onSale: () => jsonResponse(200, list([], { bonos_activos: false })),
    })

    await chooseService(wrapper, 0)

    expect(payOption(wrapper).exists()).toBe(false)
  })

  it('offers nothing to the owner or a collaborator (403 prohibido) and hides «Mis bonos»', async () => {
    const forbidden = () => problem(403, { type: 'prohibido', title: 'Prohibido' })
    const { wrapper } = await open({ mine: forbidden, onSale: forbidden })

    await chooseService(wrapper, 0)

    expect(payOption(wrapper).exists()).toBe(false)
    expect(wrapper.findAll('a').some((link) => link.text() === 'Mis bonos')).toBe(false)
    expect(wrapper.findAll('a').some((link) => link.text() === 'Mis reservas')).toBe(true)
  })

  it('offers nothing and does not ask for vouchers without a session', async () => {
    sessionStorage.clear()
    const { wrapper, count } = await open()

    await chooseService(wrapper, 0)

    expect(payOption(wrapper).exists()).toBe(false)
    expect(count('GET', '/mis-bonos')).toBe(0)
    expect(count('GET', '/bonos')).toBe(0)
    expect(wrapper.findAll('a').some((link) => link.text() === 'Mis bonos')).toBe(false)
  })

  it('books as usual when the vouchers cannot be read', async () => {
    const { wrapper } = await open({ mine: () => problem(500), onSale: () => problem(500) })

    await chooseService(wrapper, 0)
    await chip(wrapper, '09:00').trigger('click')

    expect(payOption(wrapper).exists()).toBe(false)
    expect(button(wrapper, 'Reservar')!.attributes('disabled')).toBeUndefined()
  })

  it('has «Mis bonos» next to «Mis reservas» for a customer', async () => {
    const { wrapper } = await open()

    const link = wrapper.findAll('a').find((item) => item.text() === 'Mis bonos')!
    expect(link.attributes('href')).toBe('/b/pura-navaja/bonos')
  })
})

describe('Reserve with a voucher: the reservation', () => {
  async function book(wrapper: VueWrapper, withVoucher: boolean) {
    await chooseService(wrapper, 0)
    await chip(wrapper, '09:00').trigger('click')
    if (withVoucher) await payOption(wrapper).trigger('click')
    await button(wrapper, withVoucher ? 'Confirmar con bono' : 'Reservar')!.trigger('click')
    await flushPromises()
  }

  it('asks the API for a session of a voucher with con_bono and shows the reservation confirmed', async () => {
    const { wrapper, calls } = await open()
    await chooseService(wrapper, 0)
    await chip(wrapper, '09:00').trigger('click')

    await payOption(wrapper).trigger('click')
    expect(button(wrapper, 'Confirmar con bono')).toBeDefined()
    expect(wrapper.text()).toContain('1 sesión de su bono')
    await button(wrapper, 'Confirmar con bono')!.trigger('click')
    await flushPromises()

    const body = JSON.parse(calls('POST', '/reservas')[0]!.body)
    expect(body.con_bono).toBe(true)
    // The client never says which voucher: the server picks the one that expires first.
    expect(Object.keys(body).sort()).toEqual(['barbero', 'con_bono', 'inicio', 'servicio'])
    const text = wrapper.text()
    expect(text).toContain('Se usó 1 sesión de su bono.')
    expect(text).toContain('Confirmada')
    expect(text).toContain('Puede cancelar o cambiar la hora sin costo hasta 24 horas antes')
    expect(text).toContain('la sesión vuelve a su bono')
    expect(wrapper.find('[role="timer"]').exists()).toBe(false)
    expect(button(wrapper, 'Continuar al pago')).toBeUndefined()
    expect(button(wrapper, 'Ver mis reservas')).toBeDefined()
    expect(button(wrapper, 'Ver mis bonos')).toBeDefined()
  })

  it('does not send con_bono when the person pays the usual way', async () => {
    const { wrapper, calls } = await open({}, () =>
      jsonResponse(201, { data: { ...RESERVATION, inicio: at('09:00'), fin: at('09:30') } }),
    )

    await book(wrapper, false)

    const body = JSON.parse(calls('POST', '/reservas')[0]!.body)
    expect(body).not.toHaveProperty('con_bono')
    expect(wrapper.text()).not.toContain('Se usó 1 sesión de su bono.')
    expect(button(wrapper, 'Continuar al pago')).toBeDefined()
  })

  it('goes to «Mis reservas» and «Mis bonos» from the confirmation', async () => {
    const { wrapper, router } = await open()
    await book(wrapper, true)

    await button(wrapper, 'Ver mis bonos')!.trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('vouchers'))
    expect(router.currentRoute.value.path).toBe('/b/pura-navaja/bonos')
  })
})

describe('Reserve with a voucher: errors of the API', () => {
  async function refuse(type: string, detail: string) {
    const view = await open({}, () => problem(409, { type, title: 'Conflicto', detail }))
    await chooseService(view.wrapper, 0)
    await chip(view.wrapper, '09:00').trigger('click')
    await payOption(view.wrapper).trigger('click')
    await button(view.wrapper, 'Confirmar con bono')!.trigger('click')
    await flushPromises()
    return view
  }

  it('explains saldo-insuficiente, says nothing was used and goes back to the usual payment', async () => {
    const { wrapper, calls } = await refuse('saldo-insuficiente', 'Sin sesiones.')

    const alert = wrapper.get('[role="alert"]').text()
    expect(alert).toContain('Su bono ya no tiene sesiones disponibles.')
    expect(alert).toContain('No se reservó ni se usó ninguna sesión.')
    expect(alert).toContain('pague con tarjeta o SINPE Móvil')
    expect(button(wrapper, 'Confirmar con bono')).toBeUndefined()
    expect(button(wrapper, 'Reservar')).toBeDefined()
    // The vouchers are read again: the balance changed.
    expect(calls('GET', '/mis-bonos').length).toBeGreaterThanOrEqual(2)
    expect(wrapper.find('[role="timer"]').exists()).toBe(false)
  })

  it('explains bono-vencido and offers to pick another date or pay the usual way', async () => {
    const { wrapper } = await refuse('bono-vencido', 'Vence antes.')

    const alert = wrapper.get('[role="alert"]').text()
    expect(alert).toContain('Su bono vence antes de la fecha de la cita.')
    expect(alert).toContain('No se reservó ni se usó ninguna sesión.')
    expect(alert).toContain('Elija otra fecha')
    expect(button(wrapper, 'Reservar')).toBeDefined()
  })

  it('lets the person book the same space with the usual payment after the error', async () => {
    const { wrapper, calls } = await refuse('saldo-insuficiente', 'Sin sesiones.')

    await button(wrapper, 'Reservar')!.trigger('click')
    await flushPromises()

    const sent = calls('POST', '/reservas')
    expect(sent).toHaveLength(2)
    expect(JSON.parse(sent[1]!.body)).not.toHaveProperty('con_bono')
  })
})
