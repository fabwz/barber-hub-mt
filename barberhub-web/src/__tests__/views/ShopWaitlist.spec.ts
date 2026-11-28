import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { addDays, todayInCostaRica } from '@/composables/format'
import { useBookingStore } from '@/stores/booking'
import ShopView from '@/views/ShopView.vue'
import { MAURICIO, bookingHandler } from '../helpers/booking'
import { jsonResponse, mockFetch, pathOf, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { BASE, row, signInAs } from '../helpers/offers'
import { SHOP } from '../helpers/shop'

const TODAY = todayInCostaRica()

beforeEach(() => {
  signInAs()
})
afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

interface Api {
  /** `lista_espera_activa` of the shop. */
  on?: boolean
  rows?: unknown[]
  /** Answer of `POST /filas`. */
  join?: (request: Request) => Response
  staff?: boolean
}

/** A shop with no hours that day, and the routes of the waitlist. */
async function open(api: Api = {}, session = true) {
  if (!session) sessionStorage.clear()
  const requests: string[] = []
  const bodies: unknown[] = []
  const base = bookingHandler()
  mockFetch(async (request) => {
    const route = pathOf(request)
    requests.push(route)
    if (route === 'GET /api/v1/enlaces/pura-navaja') {
      return jsonResponse(200, { data: { ...SHOP, lista_espera_activa: api.on ?? true } })
    }
    if (route === `GET ${BASE}/mis-bonos` || route === `GET ${BASE}/bonos`) {
      return api.staff
        ? problem(403, { type: 'prohibido' })
        : jsonResponse(200, {
            data: [],
            meta: { pagina: 1, por_pagina: 50, total: 0, ultima_pagina: 1, bonos_activos: false },
          })
    }
    if (route === `GET ${BASE}/mis-filas`) return jsonResponse(200, { data: api.rows ?? [] })
    if (route === `POST ${BASE}/filas`) {
      bodies.push(await request.clone().json())
      return api.join
        ? api.join(request)
        : jsonResponse(201, { data: row({ servicio: 's1', fecha: TODAY }) })
    }
    return base(request)
  })
  const mounted = await mountView(ShopView, '/b/pura-navaja')
  await flushPromises()
  await mounted.wrapper.findAll('[role="radio"]')[0]!.trigger('click')
  await flushPromises()
  return { ...mounted, requests, bodies }
}

const button = (wrapper: Awaited<ReturnType<typeof open>>['wrapper'], text: string) =>
  wrapper.findAll('button').find((item) => item.text() === text)

async function join(wrapper: Awaited<ReturnType<typeof open>>['wrapper']) {
  await button(wrapper, 'Avisarme si se libera')!.trigger('click')
  await flushPromises()
}

describe('«Avisarme si se libera» (RF-30, RF-10)', () => {
  it('is offered on a day without hours when the shop has the waitlist on', async () => {
    const { wrapper } = await open()

    expect(wrapper.text()).toContain('¿Prefiere esperar?')
    expect(button(wrapper, 'Avisarme si se libera')).toBeDefined()
  })

  it('is not offered when the waitlist is off', async () => {
    const { wrapper } = await open({ on: false })

    expect(wrapper.text()).toContain('No quedan horas')
    expect(button(wrapper, 'Avisarme si se libera')).toBeUndefined()
  })

  it('is not offered to the team of the shop', async () => {
    const { wrapper } = await open({ staff: true })

    expect(button(wrapper, 'Avisarme si se libera')).toBeUndefined()
    expect(wrapper.text()).not.toContain('Lista de espera')
  })

  it('has its entry next to «Mis reservas», in the header of the shop', async () => {
    const { wrapper } = await open()

    const links = wrapper.findAll('a').map((item) => item.text())
    expect(links).toEqual(expect.arrayContaining(['Mis reservas', 'Lista de espera']))
    expect(wrapper.get('a[href="/b/pura-navaja/lista-de-espera"]').text()).toBe('Lista de espera')
  })

  it('without a session it goes to log in and comes back to the shop', async () => {
    const { wrapper, router } = await open({}, false)

    await join(wrapper)

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
    expect(router.currentRoute.value.query.redirect).toBe('/b/pura-navaja')
  })

  it('puts the person in the row of the service, the day and «cualquiera»', async () => {
    const { wrapper, bodies } = await open()

    await join(wrapper)

    expect(bodies).toEqual([{ servicio: 's1', fecha: TODAY, barbero: null }])
    const status = wrapper.findAll('[role="status"]').find((item) => item.text().includes('Listo'))
    expect(status!.text()).toContain('le avisaremos si se libera un espacio')
    expect(button(wrapper, 'Avisarme si se libera')!.attributes('disabled')).toBeDefined()
  })

  it('leads to «Lista de espera» once it is done', async () => {
    const { wrapper, router } = await open()
    await join(wrapper)

    await button(wrapper, 'Ver mis filas')!.trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('waitlist'))
  })

  it('checks the three rows first and does not ask the API for a fourth', async () => {
    const { wrapper, requests } = await open({
      rows: [
        row({ id: 'a' }),
        row({ id: 'b', servicio: 's2' }),
        row({ id: 'c', fecha: '2030-01-01' }),
      ],
    })

    await join(wrapper)

    expect(wrapper.text()).toContain('Ya tiene 3 filas activas en esta barbería.')
    expect(requests).not.toContain(`POST ${BASE}/filas`)
  })

  it('checks that the same row is not asked for twice', async () => {
    const { wrapper, requests } = await open({ rows: [row({ servicio: 's1', fecha: TODAY })] })

    await join(wrapper)

    expect(wrapper.text()).toContain('Ya está en la fila para ese servicio, día y barbero.')
    expect(requests).not.toContain(`POST ${BASE}/filas`)
  })

  it('a row for another barber is not a repeat', async () => {
    const { wrapper, bodies } = await open({
      rows: [row({ servicio: 's1', fecha: TODAY, barbero: MAURICIO })],
    })

    await join(wrapper)

    expect(bodies).toHaveLength(1)
  })

  it('shows the spaces that the API finds instead of putting the person in the row', async () => {
    const start = `${TODAY}T16:00:00-06:00`
    const { wrapper } = await open({
      join: () =>
        problem(409, {
          type: 'http://localhost/problemas/conflicto',
          detail: 'Hay espacios disponibles.',
          espacios: [{ barbero: MAURICIO, inicio: start, fin: `${TODAY}T16:30:00-06:00` }],
        }),
    })

    await join(wrapper)

    expect(wrapper.text()).toContain('Ya hay horas disponibles para esa combinación.')
    const space = wrapper.findAll('button').find((item) => item.text().includes('16:00'))!
    await space.trigger('click')
    await flushPromises()

    expect(useBookingStore().slot).toEqual({ start, barberId: MAURICIO })
  })

  it('a team member who tries gets the refusal in words', async () => {
    const { wrapper } = await open({ join: () => problem(403, { type: 'prohibido' }) })

    await join(wrapper)

    expect(wrapper.text()).toContain('El equipo de la barbería no puede anotarse en la fila.')
  })

  it('shows the text of the API for what it cannot foresee, such as the waitlist turned off', async () => {
    const { wrapper } = await open({
      join: () =>
        problem(409, {
          type: 'http://localhost/problemas/conflicto',
          detail: 'La barbería no tiene lista de espera.',
        }),
    })

    await join(wrapper)

    expect(wrapper.get('[role="status"]:not(.sr-only)').text()).toContain(
      'La barbería no tiene lista de espera.',
    )
    // The button is free again to try.
    expect(button(wrapper, 'Avisarme si se libera')!.attributes('disabled')).toBeUndefined()
  })

  it('what was said about a row is forgotten when the day changes', async () => {
    const { wrapper } = await open()
    await join(wrapper)
    expect(wrapper.text()).toContain('Listo. Lo anotamos')

    useBookingStore().selectDate(addDays(TODAY, 1))
    await flushPromises()

    expect(wrapper.text()).not.toContain('Listo. Lo anotamos')
  })
})
