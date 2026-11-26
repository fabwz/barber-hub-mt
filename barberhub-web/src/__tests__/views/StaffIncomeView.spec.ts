import { flushPromises, type DOMWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { signIn } from '../helpers/account'
import { jsonResponse, mockFetch, pathOf, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { CLIENT_SHOP, OWNER_SHOP, SHOP_A, SHOP_B, STAFF_SHOP, staffHandler } from '../helpers/staff'

// The day is fixed (Costa Rica time) so the default range is known: the 1st of the month to today.
const NOW = new Date('2026-11-07T12:00:00-06:00')

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(NOW)
  signIn('marco@example.test')
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

function report(over: Record<string, unknown> = {}) {
  return {
    desde: '2026-11-01',
    hasta: '2026-11-07',
    cobrado_en_linea: { bruto: 186000, reembolsado: 13500, neto: 172500 },
    bonos_vendidos: { total: 74000, cantidad: 2 },
    servicios_atendidos: { total: 248500, cantidad: 26 },
    detalle: [],
    ...over,
  }
}

const EMPTY = report({
  cobrado_en_linea: { bruto: 0, reembolsado: 0, neto: 0 },
  bonos_vendidos: { total: 0, cantidad: 0 },
  servicios_atendidos: { total: 0, cantidad: 0 },
})

type Override = (request: Request) => Response | undefined

/** The income API in memory: `incomeAnswer` is what each report request gets; `calls` lists the report URLs asked. */
function incomeApi({
  role = 'dueno',
  incomeAnswer = () => jsonResponse(200, { data: report() }),
  override = () => undefined,
}: {
  role?: 'dueno' | 'colaborador'
  incomeAnswer?: (url: URL) => Response
  override?: Override
} = {}) {
  const shopId = role === 'dueno' ? SHOP_A : SHOP_B
  const calls: string[] = []
  mockFetch((request) => {
    const url = new URL(request.url)
    if (url.pathname.endsWith('/administracion/ingresos'))
      calls.push(`${url.pathname}${url.search}`)
    const forced = override(request)
    if (forced !== undefined) return forced
    const fallback = staffHandler([OWNER_SHOP, STAFF_SHOP])(request)
    if (fallback.status !== 404) return fallback
    if (pathOf(request) === `GET /api/v1/barberias/${shopId}/administracion/ingresos`) {
      return incomeAnswer(url)
    }
    return jsonResponse(404, { status: 404, type: 'no-encontrado' })
  })
  return { shopId, calls }
}

async function open(options: Parameters<typeof incomeApi>[0] = {}) {
  const api = incomeApi(options)
  const mounted = await mountView(App, `/barberias/${api.shopId}/ingresos`)
  await flushPromises()
  return { ...mounted, ...api }
}

const text = (wrapper: { text: () => string }) => wrapper.text().replace(/\s+/g, ' ')

/** The breakdown of a block as «label value» lines, as it reads on screen. */
const rowsOf = (block: DOMWrapper<Element>) =>
  block.findAll('dl > div').map((row) =>
    row
      .findAll('dt, dd')
      .map((cell) => cell.text())
      .join(' '),
  )

describe('StaffIncomeView: the report', () => {
  it('shows the heading, the subtitle and the three blocks with the figures as the API gives them', async () => {
    const { wrapper } = await open()

    expect(wrapper.get('h1').text()).toBe('Ingresos')
    expect(text(wrapper)).toContain(
      'Lo que su barbería cobró en el rango de fechas, en hora de Costa Rica.',
    )

    const blocks = wrapper.findAll('section[aria-labelledby]')
    expect(blocks).toHaveLength(3)

    const online = text(blocks[0]!)
    expect(online).toContain('Cobrado en línea, neto de reembolsos')
    expect(online).toContain('₡172.500')
    expect(online).toContain(
      'Depósitos y pagos totales de reservas, menos los reembolsos completados.',
    )
    expect(rowsOf(blocks[0]!)).toEqual([
      'Cobrado en línea ₡186.000',
      'Reembolsos −₡13.500',
      'Neto ₡172.500',
    ])

    const vouchers = text(blocks[1]!)
    expect(vouchers).toContain('Bonos vendidos₡74.000')
    expect(vouchers).toContain(
      'Bonos pagados en el rango. No se suman al cobrado en línea de las reservas.',
    )
    expect(rowsOf(blocks[1]!)).toEqual(['Bonos vendidos 2', 'Total ₡74.000'])

    const attended = text(blocks[2]!)
    expect(attended).toContain('Aparte')
    expect(attended).toContain('Valor atendido₡248.500')
    expect(attended).toContain(
      'Precio de los servicios marcados como atendidos en el rango. No es dinero cobrado.',
    )
    expect(rowsOf(blocks[2]!)).toEqual(['Servicios atendidos 26', 'Valor atendido ₡248.500'])
  })

  it('always says that the balance paid at the shop is not included (PA-10)', async () => {
    const { wrapper } = await open()
    expect(text(wrapper)).toContain(
      'El saldo que se cobra en el local no se incluye. Aquí solo entra lo que se cobró en línea.',
    )
  })

  it('writes the refund as a deduction, and zero refunds without a sign', async () => {
    const { wrapper } = await open({
      incomeAnswer: () =>
        jsonResponse(200, {
          data: report({ cobrado_en_linea: { bruto: 186000, reembolsado: 0, neto: 186000 } }),
        }),
    })
    const rows = rowsOf(wrapper.findAll('section[aria-labelledby]')[0]!)
    expect(rows[1]).toBe('Reembolsos ₡0')
    expect(rows.join()).not.toContain('−')
  })

  it('does not recompute: a net that is not gross minus refunds is shown as it came', async () => {
    const { wrapper } = await open({
      incomeAnswer: () =>
        jsonResponse(200, {
          data: report({ cobrado_en_linea: { bruto: 100000, reembolsado: 5000, neto: 12345 } }),
        }),
    })
    const online = text(wrapper.findAll('section[aria-labelledby]')[0]!)
    expect(online).toContain('₡12.345')
    expect(online).toContain('−₡5.000')
  })

  it('has no «Reembolsos» tab or link, and the figures are not summed into a combined total', async () => {
    const { wrapper } = await open()
    expect(wrapper.find('[role="tab"], [role="tablist"]').exists()).toBe(false)
    expect(wrapper.findAll('main a, main button').map((n) => n.text())).not.toContain('Reembolsos')
    expect(wrapper.find('main a[href*="reembolsos"]').exists()).toBe(false)
    // The only «₡» figures are the API's own ones: no 172.500 + 74.000 or similar.
    expect(text(wrapper)).not.toContain('₡246.500')
    expect(text(wrapper)).not.toContain('₡495.000')
  })
})

describe('StaffIncomeView: the range', () => {
  it('opens on the current month, from the 1st to today in Costa Rica, and asks for exactly that', async () => {
    const { wrapper, calls } = await open()
    const [desde, hasta] = wrapper.findAll('input[type="date"]')
    expect((desde!.element as HTMLInputElement).value).toBe('2026-11-01')
    expect((hasta!.element as HTMLInputElement).value).toBe('2026-11-07')
    expect(calls).toEqual([
      `/api/v1/barberias/${SHOP_A}/administracion/ingresos?desde=2026-11-01&hasta=2026-11-07`,
    ])
  })

  it('has labelled fields «Desde» and «Hasta» and the «Ver ingresos» button', async () => {
    const { wrapper } = await open()
    expect(wrapper.get('label[for]').text()).toBe('Desde')
    const labels = wrapper.findAll('form label').map((l) => l.text())
    expect(labels).toEqual(['Desde', 'Hasta'])
    expect(wrapper.get('form button[type="submit"]').text()).toContain('Ver ingresos')
  })

  it('asks again with the new range when «Ver ingresos» is pressed', async () => {
    const { wrapper, calls } = await open()
    const [desde, hasta] = wrapper.findAll('input[type="date"]')
    await desde!.setValue('2026-10-01')
    await hasta!.setValue('2026-10-31')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(calls).toHaveLength(2)
    expect(calls[1]).toContain('desde=2026-10-01&hasta=2026-10-31')
  })

  it('refuses a final date before the initial one, in the field, without calling the API', async () => {
    const { wrapper, calls } = await open()
    const [desde, hasta] = wrapper.findAll('input[type="date"]')
    await desde!.setValue('2026-11-05')
    await hasta!.setValue('2026-11-02')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(calls).toHaveLength(1)
    expect(text(wrapper)).toContain('La fecha final no puede ser anterior a la inicial.')
    expect(hasta!.attributes('aria-invalid')).toBe('true')
    const describedBy = hasta!.attributes('aria-describedby')!
    expect(wrapper.get(`#${describedBy}`).text()).toContain('La fecha final no puede ser anterior')
    // The report already on screen is left as it was.
    expect(text(wrapper)).toContain('₡172.500')
  })

  it('clears the error when the date changes', async () => {
    const { wrapper } = await open()
    const [desde, hasta] = wrapper.findAll('input[type="date"]')
    await desde!.setValue('2026-11-05')
    await hasta!.setValue('2026-11-02')
    await wrapper.get('form').trigger('submit')
    expect(hasta!.attributes('aria-invalid')).toBe('true')
    await hasta!.setValue('2026-11-06')
    await hasta!.trigger('input')
    expect(hasta!.attributes('aria-invalid')).toBeUndefined()
  })

  it('asks for a date when one is empty, without calling the API', async () => {
    const { wrapper, calls } = await open()
    await wrapper.findAll('input[type="date"]')[0]!.setValue('')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(calls).toHaveLength(1)
    expect(text(wrapper)).toContain('Elija la fecha inicial.')
  })

  it('shows the 422 of the API (more than 366 days) under «Hasta»', async () => {
    let first = true
    const { wrapper } = await open({
      incomeAnswer: () => {
        if (first) {
          first = false
          return jsonResponse(200, { data: report() })
        }
        return problem(422, {
          type: 'datos-invalidos',
          errores: { hasta: ['El rango no puede pasar de 366 días.'] },
        })
      },
    })
    await wrapper.findAll('input[type="date"]')[0]!.setValue('2024-01-01')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(text(wrapper)).toContain('El rango no puede pasar de 366 días.')
    expect(wrapper.findAll('input[type="date"]')[1]!.attributes('aria-invalid')).toBe('true')
  })
})

describe('StaffIncomeView: states', () => {
  it('shows the skeleton while it loads', async () => {
    const api = incomeApi({
      override: (r) => (r.url.includes('/ingresos') ? new Response() : undefined),
    })
    const { wrapper } = await mountView(App, `/barberias/${api.shopId}/ingresos`)
    const region = wrapper.get('section[aria-label="Resultado"]')
    expect(region.attributes('aria-busy')).toBe('true')
    expect(region.text()).toContain('Cargando los ingresos')
    expect(wrapper.findAll('section[aria-labelledby]')).toHaveLength(0)
  })

  it('shows the empty state with the requested range, and still the PA-10 note', async () => {
    const { wrapper } = await open({ incomeAnswer: () => jsonResponse(200, { data: EMPTY }) })
    expect(text(wrapper)).toContain('No hay ingresos en este rango.')
    expect(text(wrapper)).toContain(
      'No hubo cobros, bonos vendidos ni servicios atendidos entre el dom 1 nov y el sáb 7 nov. Pruebe con otro rango de fechas.',
    )
    expect(wrapper.findAll('section[aria-labelledby]')).toHaveLength(0)
    expect(text(wrapper)).toContain('El saldo que se cobra en el local no se incluye.')
  })

  it('names the range that was asked in the empty state, not the default one', async () => {
    let first = true
    const { wrapper } = await open({
      incomeAnswer: () => {
        if (first) {
          first = false
          return jsonResponse(200, { data: report() })
        }
        return jsonResponse(200, { data: EMPTY })
      },
    })
    await wrapper.findAll('input[type="date"]')[0]!.setValue('2026-11-02')
    await wrapper.findAll('input[type="date"]')[1]!.setValue('2026-11-07')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(text(wrapper)).toContain('entre el lun 2 nov y el sáb 7 nov.')
  })

  it('shows the error and «Reintentar» asks for the same range again', async () => {
    let fail = true
    const { wrapper, calls } = await open({
      incomeAnswer: () => (fail ? problem(500) : jsonResponse(200, { data: report() })),
    })
    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toContain('No pudimos cargar los ingresos')
    expect(alert.text()).toContain('Revise su conexión e inténtelo de nuevo.')
    fail = false
    await alert.get('button').trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(text(wrapper)).toContain('₡172.500')
    expect(calls[1]).toBe(calls[0])
  })

  it('announces the result in a polite live region', async () => {
    const { wrapper } = await open()
    const region = wrapper.get('section[aria-label="Resultado"]')
    expect(region.attributes('aria-live')).toBe('polite')
    expect(region.text()).toContain('₡172.500')
  })
})

describe('access to «Ingresos»', () => {
  it('the owner has the link in the bar and it is the current one', async () => {
    const { wrapper } = await open()
    const link = wrapper.findAll('header nav a').find((a) => a.text() === 'Ingresos')!
    expect(link.attributes('aria-current')).toBe('page')
    expect(link.attributes('href')).toBe(`/barberias/${SHOP_A}/ingresos`)
  })

  it('a collaborator does not get in: the agenda opens, without asking for the report', async () => {
    const api = incomeApi({ role: 'colaborador' })
    const { router } = await mountView(App, `/barberias/${api.shopId}/ingresos`)
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('staff-agenda')
    expect(api.calls).toEqual([])
  })

  it('the bar of a collaborator has no «Ingresos» link', async () => {
    const api = incomeApi({ role: 'colaborador' })
    const { wrapper } = await mountView(App, `/barberias/${api.shopId}/agenda`)
    await flushPromises()
    expect(wrapper.findAll('header nav a').map((a) => a.text())).not.toContain('Ingresos')
  })

  it('a customer of the shop has no staff role: the shop selector opens instead', async () => {
    const api = incomeApi({
      override: (r) =>
        r.method === 'GET' && r.url.endsWith('/mis-barberias')
          ? jsonResponse(200, { data: [CLIENT_SHOP] })
          : undefined,
    })
    const { router } = await mountView(App, `/barberias/${CLIENT_SHOP.id}/ingresos`)
    await flushPromises()
    expect(router.currentRoute.value.path).toBe('/negocios')
    expect(api.calls).toEqual([])
  })
})
