import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import VouchersView from '@/views/VouchersView.vue'
import { jsonResponse, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import {
  VOUCHERS_PAGE,
  VOUCHER_ID,
  FREE_SHELF,
  ONVO_SHELF,
  PAYPAL_SHELF,
  bought,
  expired,
  list,
  mockVoucherFetch,
  onSale,
  signIn,
  type VoucherHandlers,
} from '../helpers/vouchers'

const mountedViews: VueWrapper[] = []

async function open(handlers: VoucherHandlers = {}) {
  const mock = mockVoucherFetch(handlers)
  const mounted = await mountView(VouchersView, VOUCHERS_PAGE)
  mountedViews.push(mounted.wrapper)
  await flushPromises()
  await vi.waitFor(() => expect(mounted.wrapper.find('[aria-busy="true"]').exists()).toBe(false))
  await flushPromises()
  return { ...mounted, ...mock }
}

const section = (wrapper: VueWrapper, label: string) =>
  wrapper.findAll('section').find((item) => item.get('h2').text() === label)!

beforeEach(() => {
  sessionStorage.clear()
  signIn()
})
afterEach(() => {
  for (const view of mountedViews.splice(0)) view.unmount()
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('VouchersView: my vouchers and vouchers on sale', () => {
  it('shows the active voucher with its balance, its expiry and the services it covers', async () => {
    const { wrapper } = await open()

    const mine = section(wrapper, 'Mis bonos').text()
    expect(mine).toContain('Bono 5 cortes')
    expect(mine).toContain('Activo')
    expect(mine).toContain('3 de 5 sesiones')
    expect(mine).toContain('Vence el mar 5 mar 2030')
    expect(mine).toContain('Corte clásico, Corte + barba')
  })

  it('shows the voucher on sale with sessions, validity, covered services and the price with VAT included', async () => {
    const { wrapper } = await open()

    const sale = section(wrapper, 'Bonos a la venta').text()
    expect(sale).toContain('Bono 5 cortes')
    expect(sale).toContain('5 sesiones')
    expect(sale).toContain('60 días')
    expect(sale).toContain('Corte clásico, Corte + barba')
    expect(sale).toContain('₡45.000')
    expect(sale).toContain('IVA incluido')
    expect(sale).toContain('Los bonos solo sirven en Pura Navaja.')
  })

  it('leads «Comprar bono» to the purchase of that voucher', async () => {
    const { wrapper } = await open()

    const link = wrapper.get('a[aria-label="Comprar bono: Bono 5 cortes"]')
    expect(link.text()).toBe('Comprar bono')
    expect(link.attributes('href')).toBe(`/b/pura-navaja/bonos/${VOUCHER_ID}/compra`)
  })

  it('shows an expired voucher with balance 0 and its expiry date', async () => {
    const { wrapper } = await open({
      mine: () => jsonResponse(200, list([expired()])),
    })

    const mine = section(wrapper, 'Mis bonos').text()
    expect(mine).toContain('Bono 4 barbas')
    expect(mine).toContain('Vencido')
    expect(mine).toContain('0 sesiones')
    expect(mine).not.toContain('0 de 4')
    expect(mine).toContain('Venció el sáb 3 nov 2029')
    // The state is a word, never a dot or a color.
    expect(wrapper.find('[data-status-dot]').exists()).toBe(false)
  })

  it('shows the movements of a voucher on demand, the newest first, with the change of each one', async () => {
    const { wrapper } = await open()
    const toggle = wrapper.findAll('button').find((b) => b.text() === 'Movimientos')!

    expect(toggle.attributes('aria-expanded')).toBe('false')
    const rows = () => wrapper.get(`#${toggle.attributes('aria-controls')}`)
    expect(rows().isVisible()).toBe(false)

    await toggle.trigger('click')

    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(rows().isVisible()).toBe(true)
    const items = rows()
      .findAll('li')
      .map((item) => item.text())
    expect(items).toHaveLength(6)
    expect(items[0]).toContain('Apartado')
    expect(items[0]).toContain('−1 sesión')
    expect(items[1]).toContain('Reintegro')
    expect(items[1]).toContain('+1 sesión')
    expect(items[3]).toContain('Consumo')
    expect(items[3]).toContain('0 sesiones')
    expect(items[5]).toContain('Compra')
    expect(items[5]).toContain('+5 sesiones')
    expect(items[5]).toContain('vie 4 ene 2030')
  })

  it('lists the four kinds of movement with their label and signed sessions, newest first', async () => {
    const { wrapper } = await open({
      mine: () =>
        jsonResponse(
          200,
          list([
            bought({
              movimientos: [
                { tipo: 'compra', sesiones: 5, reserva: null, fecha: '2030-01-04T08:00:00-06:00' },
                {
                  tipo: 'apartado',
                  sesiones: -1,
                  reserva: 'r1',
                  fecha: '2030-01-12T09:00:00-06:00',
                },
                { tipo: 'consumo', sesiones: 0, reserva: 'r1', fecha: '2030-01-14T10:00:00-06:00' },
                {
                  tipo: 'reintegro',
                  sesiones: 1,
                  reserva: 'r2',
                  fecha: '2030-01-27T09:00:00-06:00',
                },
              ],
            }),
          ]),
        ),
    })
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Movimientos')!
      .trigger('click')

    const rows = wrapper.findAll('li li')
    expect(rows).toHaveLength(4)
    expect(rows.map((row) => row.find('.font-medium').text())).toEqual([
      'Reintegro',
      'Consumo',
      'Apartado',
      'Compra',
    ])
    expect(rows[0]!.text()).toContain('+1 sesión')
    expect(rows[3]!.text()).toContain('+5 sesiones')
  })
})

describe('VouchersView: vouchers turned off', () => {
  const off = () => jsonResponse(200, list([], { bonos_activos: false }))

  it('offers nothing to buy but keeps the vouchers already bought visible', async () => {
    const { wrapper } = await open({ onSale: off })

    expect(section(wrapper, 'Mis bonos').text()).toContain('3 de 5 sesiones')
    const sale = section(wrapper, 'Bonos a la venta').text()
    expect(sale).toContain('Esta barbería no ofrece bonos por ahora')
    expect(sale).toContain('Los que ya compró siguen visibles y puede usarlos.')
    expect(wrapper.text()).not.toContain('Comprar bono')
  })

  it('says there is nothing on sale when the vouchers are on but none is published', async () => {
    const { wrapper } = await open({
      onSale: () => jsonResponse(200, list([], ONVO_SHELF)),
    })

    expect(section(wrapper, 'Bonos a la venta').text()).toContain(
      'Esta barbería no tiene bonos a la venta',
    )
  })
})

describe('VouchersView: who sees what', () => {
  it('shows an empty list, not an error, to a person with no role who bought nothing', async () => {
    const { wrapper } = await open({ mine: () => jsonResponse(200, list([])) })

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(section(wrapper, 'Mis bonos').text()).toContain('Aún no tiene bonos')
    expect(section(wrapper, 'Mis bonos').text()).toContain(
      'Cuando compre uno, aparece aquí con sus sesiones y su vencimiento.',
    )
    // The vouchers on sale are still offered to them.
    expect(wrapper.text()).toContain('Comprar bono')
  })

  it('offers nothing to the owner or a collaborator (403 prohibido)', async () => {
    const forbidden = () => problem(403, { type: 'prohibido', title: 'Prohibido' })
    const { wrapper } = await open({ mine: forbidden, onSale: forbidden })

    expect(wrapper.text()).toContain('Esta lista es para clientes')
    expect(wrapper.text()).not.toContain('Comprar bono')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })
})

describe('VouchersView: loading, error and empty states', () => {
  it('shows a skeleton while it loads, with aria-busy', async () => {
    let release: (response: Response) => void = () => {}
    mockVoucherFetch({ mine: () => new Promise<Response>((resolve) => (release = resolve)) })
    const { wrapper } = await mountView(VouchersView, VOUCHERS_PAGE)
    mountedViews.push(wrapper)
    await flushPromises()

    expect(wrapper.get('main').attributes('aria-busy')).toBe('true')
    expect(wrapper.text()).toContain('Cargando sus bonos')
    release(jsonResponse(200, list([bought()])))
    await flushPromises()
    expect(wrapper.get('main').attributes('aria-busy')).toBeUndefined()
  })

  it('shows the error with «Reintentar» and loads again from it', async () => {
    let calls = 0
    const { wrapper } = await open({
      mine: () => {
        calls += 1
        return calls === 1 ? problem(500) : jsonResponse(200, list([bought()]))
      },
    })

    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar sus bonos.')
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Reintentar')!
      .trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('3 de 5 sesiones')
  })

  it('shows the empty state of the vouchers bought and of the vouchers on sale', async () => {
    const { wrapper } = await open({
      mine: () => jsonResponse(200, list([])),
      onSale: () => jsonResponse(200, list([], ONVO_SHELF)),
    })

    expect(wrapper.text()).toContain('Aún no tiene bonos')
    expect(wrapper.text()).toContain('Esta barbería no tiene bonos a la venta')
  })

  it('asks for every page of the vouchers', async () => {
    let page = 0
    const { calls } = await open({
      mine: (request) => {
        page = Number(new URL(request.url).searchParams.get('pagina'))
        return jsonResponse(
          200,
          list([bought({ id: `b${page}` })], { pagina: page, ultima_pagina: 2 }),
        )
      },
      onSale: () => jsonResponse(200, list([onSale()], ONVO_SHELF)),
    })

    expect(calls('GET', '/mis-bonos')).toHaveLength(2)
  })
})

describe('VouchersView: a shop that does not charge', () => {
  it('offers no voucher to buy, with the same state as an empty shelf', async () => {
    const { wrapper } = await open({
      onSale: () => jsonResponse(200, list([onSale()], FREE_SHELF)),
    })

    expect(section(wrapper, 'Bonos a la venta').text()).toContain(
      'Esta barbería no tiene bonos a la venta',
    )
    expect(wrapper.text()).not.toContain('Comprar bono')
  })

  it('still offers the vouchers to a shop that collects with PayPal', async () => {
    const { wrapper } = await open({
      onSale: () => jsonResponse(200, list([onSale()], PAYPAL_SHELF)),
    })

    expect(wrapper.text()).toContain('Comprar bono')
  })
})
