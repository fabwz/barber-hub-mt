import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { signIn } from '../helpers/account'
import { jsonResponse, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { CLIENT_SHOP, SHOP_C } from '../helpers/staff'
import { soldVoucher, voucher, voucherDefinitionsApi } from '../helpers/voucherDefinitions'

beforeEach(() => signIn('marco@example.test'))
afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

const isList = (r: Request) => r.method === 'GET' && r.url.endsWith('/administracion/bonos')
const isSoldList = (r: Request) => r.url.includes('/administracion/bonos-vendidos')

async function open(options: Parameters<typeof voucherDefinitionsApi>[0] = {}, path = '/bonos') {
  const api = voucherDefinitionsApi(options)
  const mounted = await mountView(App, `/barberias/${api.shopId}${path}`)
  await flushPromises()
  return { ...mounted, ...api }
}

/** Presses a button of the open dialog by its text. */
function pressInDialog(text: string): void {
  const dialog = document.body.querySelector('[role="alertdialog"]')!
  const button = Array.from(dialog.querySelectorAll('button')).find((b) =>
    b.textContent?.includes(text),
  )
  button!.click()
}

describe('StaffVouchersView: the section', () => {
  it('is «Bonos» with its two sections as links, the current one marked, and «Nuevo bono»', async () => {
    const { wrapper } = await open()

    expect(wrapper.get('h1').text()).toBe('Bonos')
    expect(wrapper.text()).toContain(
      'Paquetes de sesiones que sus clientes compran por adelantado.',
    )
    const links = wrapper.get('nav[aria-label="Secciones de Bonos"]').findAll('a')
    expect(links.map((link) => link.text())).toEqual(['Bonos del negocio', 'Bonos vendidos'])
    expect(links[0]!.attributes('aria-current')).toBe('page')
    expect(links[1]!.attributes('aria-current')).toBeUndefined()
    expect(wrapper.find('[role="tab"], [role="tablist"]').exists()).toBe(false)
    expect(wrapper.find('main a[href$="/bonos/nuevo"]').text()).toContain('Nuevo bono')
  })

  it('the «Bonos» link of the bar shows for the owner and stays current in the section', async () => {
    const { wrapper } = await open()
    const link = wrapper.findAll('header nav a').find((a) => a.text() === 'Bonos')!
    expect(link.attributes('aria-current')).toBe('page')
  })
})

describe('VoucherDefinitionsView', () => {
  it('lists the vouchers with sessions, validity, price, covered services and status', async () => {
    const { wrapper } = await open({
      vouchers: [
        voucher(),
        voucher({
          id: '0190b1f0-0000-7000-8000-0000000000b2',
          nombre: 'Bono 3 perfilados',
          sesiones: 1,
          vigencia_dias: 1,
          precio: 6000,
          servicios: ['0190b1f0-0000-7000-8000-0000000000a3'],
          activo: false,
        }),
      ],
    })

    const rows = wrapper.findAll('ul[aria-label="Bonos de la barbería"] > li')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('Bono 5 cortes')
    expect(rows[0]!.text()).toContain('5 sesiones · 90 días')
    expect(rows[0]!.text()).toContain('₡32.000')
    expect(rows[0]!.text()).toContain('IVA incluido')
    expect(rows[0]!.text()).toContain('Corte clásico, Corte degradado')
    expect(rows[0]!.text()).toContain('A la venta')
    expect(rows[1]!.text()).toContain('1 sesión · 1 día')
    expect(rows[1]!.text()).toContain('Barba')
    expect(rows[1]!.text()).toContain('Retirado')
    expect(rows[1]!.text()).toContain('Volver a vender')
    expect(rows[0]!.get('a').attributes('href')).toContain(
      '/bonos/0190b1f0-0000-7000-8000-0000000000b1',
    )
  })

  it('shows the skeleton while it loads', async () => {
    const api = voucherDefinitionsApi({ override: (r) => (isList(r) ? new Response() : undefined) })
    const { wrapper } = await mountView(App, `/barberias/${api.shopId}/bonos`)
    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true)
  })

  it('shows the empty state with the way to create the first one', async () => {
    const { wrapper } = await open({ vouchers: [] })
    expect(wrapper.text()).toContain('Aún no tiene bonos.')
    expect(wrapper.text()).toContain(
      'Cree un bono para que sus clientes paguen varias visitas por adelantado.',
    )
    expect(wrapper.text()).toContain('Crear mi primer bono')
  })

  it('shows the error and tries again', async () => {
    let fail = true
    const { wrapper } = await open({
      override: (r) => (fail && isList(r) ? problem(500) : undefined),
    })
    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar los bonos')
    expect(wrapper.text()).toContain('Revise su conexión e inténtelo de nuevo.')
    fail = false
    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()
    expect(wrapper.findAll('ul[aria-label="Bonos de la barbería"] > li')).toHaveLength(1)
  })

  it('with the vouchers switched off it says so, blocks «Nuevo bono» and keeps the list', async () => {
    const { wrapper } = await open({ vouchersEnabled: false })

    const note = wrapper.get('[role="note"]')
    expect(note.text()).toContain('Los bonos están desactivados')
    expect(note.text()).toContain('no puede crear bonos nuevos ni los clientes comprarlos')
    expect(note.text()).toContain('Actívelos en Configuración, en «Cobro y políticas».')
    const settings = wrapper.findAll('main a').find((a) => a.text() === 'Ir a Cobro y políticas')!
    expect(settings.attributes('href')).toContain('/configuracion/cobro')
    const create = wrapper.findAll('main button').find((b) => b.text().includes('Nuevo bono'))!
    expect(create.attributes('disabled')).toBeDefined()
    expect(create.attributes('aria-describedby')).toBeTruthy()
    expect(wrapper.find('main a[href$="/bonos/nuevo"]').exists()).toBe(false)
    expect(wrapper.findAll('ul[aria-label="Bonos de la barbería"] > li')).toHaveLength(1)
    // The ones that exist can still be edited and withdrawn.
    expect(wrapper.find('button[aria-label="Retirar Bono 5 cortes"]').exists()).toBe(true)
  })

  it('with the vouchers switched on there is no notice', async () => {
    const { wrapper } = await open()
    expect(wrapper.find('[role="note"]').exists()).toBe(false)
  })

  it('withdrawing asks first and changes nothing if the owner says no', async () => {
    const { wrapper, bodies } = await open()
    await wrapper.get('button[aria-label="Retirar Bono 5 cortes"]').trigger('click')

    const dialog = document.body.querySelector('[role="alertdialog"]')!
    expect(dialog.textContent).toContain('¿Retirar «Bono 5 cortes» de la venta?')
    expect(dialog.textContent).toContain(
      'Los clientes que ya lo compraron conservan su saldo y su vigencia. Podrá volver a ponerlo a la venta cuando quiera.',
    )
    pressInDialog('No, mantenerlo')
    await flushPromises()

    expect(bodies).toEqual([])
    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
  })

  it('confirming sends `activo: false` and says the balances do not change', async () => {
    const { wrapper, bodies } = await open()
    await wrapper.get('button[aria-label="Retirar Bono 5 cortes"]').trigger('click')
    pressInDialog('Sí, retirar')
    await flushPromises()

    expect(bodies).toEqual([{ activo: false }])
    expect(wrapper.get('[role="status"]').text()).toContain(
      '«Bono 5 cortes» se retiró de la venta. Los saldos de quienes lo compraron no cambian.',
    )
    const row = wrapper.get('[data-testid="voucher-0190b1f0-0000-7000-8000-0000000000b1"]')
    expect(row.text()).toContain('Retirado')
    expect(row.text()).toContain('Volver a vender')
  })

  it('putting it back on sale does not ask', async () => {
    const { wrapper, bodies } = await open({ vouchers: [voucher({ activo: false })] })
    await wrapper.get('button[aria-label="Volver a vender Bono 5 cortes"]').trigger('click')
    await flushPromises()

    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
    expect(bodies).toEqual([{ activo: true }])
    expect(wrapper.get('[role="status"]').text()).toContain('«Bono 5 cortes» volvió a la venta.')
  })

  it('a failed change shows the error and «Reintentar» sends it again', async () => {
    let fail = true
    const { wrapper, calls } = await open({
      override: (r) => (fail && r.method === 'PATCH' ? problem(500) : undefined),
    })
    await wrapper.get('button[aria-label="Retirar Bono 5 cortes"]').trigger('click')
    pressInDialog('Sí, retirar')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain(
      'No pudimos cambiar la venta de «Bono 5 cortes»',
    )
    fail = false
    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()
    expect(calls.filter((c) => c.startsWith('PATCH'))).toHaveLength(2)
    expect(wrapper.get('[role="status"]').text()).toContain('se retiró de la venta')
  })

  it('the shop travels in the route, never in a header', async () => {
    const { fetchMock, shopId } = await open()
    const list = fetchMock.mock.calls.map((c) => c[0] as Request).find(isList)!
    expect(new URL(list.url).pathname).toBe(`/api/v1/barberias/${shopId}/administracion/bonos`)
    expect([...list.headers.keys()].some((key) => key.includes('barberia'))).toBe(false)
  })
})

describe('SoldVouchersView', () => {
  const sold = (index: number, over: Record<string, unknown> = {}) =>
    soldVoucher({
      id: `0190b1f0-0000-7000-8000-${String(1000 + index).padStart(12, '0')}`,
      cliente_nombre: `Cliente ${index}`,
      ...over,
    })

  it('lists the vouchers the customers bought, to read only', async () => {
    const { wrapper } = await open(
      {
        sold: [
          sold(1),
          sold(2, {
            saldo: 0,
            estado: 'vencido',
            vence_en: '2026-10-20T09:00:00-06:00',
          }),
        ],
      },
      '/bonos/vendidos',
    )

    const links = wrapper.get('nav[aria-label="Secciones de Bonos"]').findAll('a')
    expect(links[1]!.attributes('aria-current')).toBe('page')
    const rows = wrapper.findAll('ul[aria-label="Bonos vendidos de la barbería"] > li')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('Cliente 1')
    expect(rows[0]!.text()).toContain('Bono 5 cortes')
    expect(rows[0]!.text()).toContain('3 de 5 sesiones')
    expect(rows[0]!.text()).toContain('Vence vie 15 ene 2027')
    expect(rows[0]!.text()).toContain('Activo')
    expect(rows[1]!.text()).toContain('0 de 5 sesiones')
    expect(rows[1]!.text()).toContain('Venció mar 20 oct 2026')
    expect(rows[1]!.text()).toContain('Vencido')
    expect(rows.every((row) => row.find('button, input, a').exists() === false)).toBe(true)
    expect(wrapper.text()).not.toContain('Mostrar más')
  })

  it('shows the skeleton while it loads', async () => {
    const api = voucherDefinitionsApi({
      override: (r) => (isSoldList(r) ? new Response() : undefined),
    })
    const { wrapper } = await mountView(App, `/barberias/${api.shopId}/bonos/vendidos`)
    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true)
  })

  it('shows the empty state', async () => {
    const { wrapper } = await open({ sold: [] }, '/bonos/vendidos')
    expect(wrapper.text()).toContain('Aún no ha vendido bonos.')
    expect(wrapper.text()).toContain(
      'Cuando un cliente compre un bono, aparecerá aquí con su saldo y su vencimiento.',
    )
  })

  it('shows the error and tries again', async () => {
    let fail = true
    const { wrapper } = await open(
      { sold: [sold(1)], override: (r) => (fail && isSoldList(r) ? problem(500) : undefined) },
      '/bonos/vendidos',
    )
    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar los bonos vendidos')
    fail = false
    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()
    expect(wrapper.findAll('ul[aria-label="Bonos vendidos de la barbería"] > li')).toHaveLength(1)
  })

  it('«Mostrar más» adds the next page with `pagina` until there are no more', async () => {
    const all = Array.from({ length: 20 }, (_, index) => sold(index + 1))
    const { wrapper, calls } = await open({ sold: all }, '/bonos/vendidos')
    const items = () => wrapper.findAll('ul[aria-label="Bonos vendidos de la barbería"] > li')

    expect(items()).toHaveLength(15)
    const more = wrapper.findAll('button').find((b) => b.text() === 'Mostrar más')!
    await more.trigger('click')
    await flushPromises()

    expect(items()).toHaveLength(20)
    expect(items()[19]!.text()).toContain('Cliente 20')
    expect(wrapper.findAll('button').some((b) => b.text() === 'Mostrar más')).toBe(false)
    const asked = calls.filter((c) => c.includes('bonos-vendidos'))
    expect(asked[0]).toContain('pagina=1')
    expect(asked[1]).toContain('pagina=2')
  })

  it('a failed page keeps what is shown and lets the owner ask again', async () => {
    const all = Array.from({ length: 20 }, (_, index) => sold(index + 1))
    let fail = true
    const { wrapper } = await open(
      {
        sold: all,
        override: (r) =>
          fail && isSoldList(r) && r.url.includes('pagina=2') ? problem(500) : undefined,
      },
      '/bonos/vendidos',
    )
    const press = async () => {
      await wrapper
        .findAll('button')
        .find((b) => b.text() === 'Mostrar más')!
        .trigger('click')
      await flushPromises()
    }

    await press()
    expect(wrapper.findAll('ul[aria-label="Bonos vendidos de la barbería"] > li')).toHaveLength(15)
    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar más bonos vendidos')
    fail = false
    await press()
    expect(wrapper.findAll('ul[aria-label="Bonos vendidos de la barbería"] > li')).toHaveLength(20)
  })
})

describe('access to «Bonos»', () => {
  it('a collaborator of the shop does not get in: the agenda opens instead', async () => {
    const api = voucherDefinitionsApi({ role: 'colaborador' })
    const { router } = await mountView(App, `/barberias/${api.shopId}/bonos`)
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('staff-agenda')
    expect(api.calls.some((c) => c.includes('/administracion/bonos'))).toBe(false)
  })

  it('a collaborator does not reach the form or the sold ones either', async () => {
    const api = voucherDefinitionsApi({ role: 'colaborador' })
    const { router } = await mountView(App, `/barberias/${api.shopId}/bonos/nuevo`)
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('staff-agenda')
    await router.push(`/barberias/${api.shopId}/bonos/vendidos`)
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('staff-agenda')
    expect(api.calls.some((c) => c.includes('/administracion/bonos'))).toBe(false)
  })

  it('a customer of the shop has no staff role: the shop selector opens instead', async () => {
    const api = voucherDefinitionsApi({
      override: (r) =>
        r.method === 'GET' && r.url.endsWith('/mis-barberias')
          ? jsonResponse(200, { data: [CLIENT_SHOP] })
          : undefined,
    })
    const { router } = await mountView(App, `/barberias/${SHOP_C}/bonos`)
    await flushPromises()
    expect(router.currentRoute.value.path).toBe('/negocios')
    expect(api.calls.some((c) => c.includes('/administracion/bonos'))).toBe(false)
  })

  it('the bar of a collaborator has no «Bonos» link', async () => {
    const api = voucherDefinitionsApi({ role: 'colaborador' })
    const { wrapper } = await mountView(App, `/barberias/${api.shopId}/agenda`)
    await flushPromises()
    expect(wrapper.findAll('header nav a').map((a) => a.text())).not.toContain('Bonos')
  })
})
