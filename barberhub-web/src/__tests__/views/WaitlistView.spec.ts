import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useOffersStore } from '@/stores/offers'
import WaitlistView from '@/views/WaitlistView.vue'
import { jsonResponse, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import {
  BASE,
  OFFER_ID,
  RESERVATION_ID,
  ROW_ID,
  WAITLIST_PAGE,
  closed,
  mockOffersApi,
  offer,
  row,
  signInAs,
} from '../helpers/offers'
import { bought } from '../helpers/vouchers'

beforeEach(() => {
  signInAs()
})
afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

async function open(api: Parameters<typeof mockOffersApi>[0] = {}) {
  const mocked = mockOffersApi(api)
  const view = await mountView(WaitlistView, WAITLIST_PAGE)
  await flushPromises()
  return { ...view, ...mocked }
}

const button = (wrapper: Awaited<ReturnType<typeof open>>['wrapper'], text: string) =>
  wrapper.findAll('button').find((item) => item.text() === text)

describe('«Lista de espera» of the customer: the states', () => {
  it('shows a skeleton while it loads', async () => {
    mockOffersApi({ rows: () => new Promise<never>(() => {}) })
    const { wrapper } = await mountView(WaitlistView, WAITLIST_PAGE)
    await flushPromises()

    expect(wrapper.get('main').attributes('aria-busy')).toBe('true')
    expect(wrapper.text()).toContain('Cargando sus filas')
    expect(wrapper.get('h1').text()).toBe('Lista de espera')
  })

  it('says so when there is no row, with a way back to book', async () => {
    const { wrapper } = await open()

    expect(wrapper.text()).toContain('No está en ninguna fila')
    expect(wrapper.text()).toContain('Tiene 0 de 3 filas activas.')
    expect(wrapper.get('a[href="/b/pura-navaja"]').text()).toBe('Reservar')
  })

  it('shows an error that can be tried again', async () => {
    let fail = true
    const { wrapper } = await open({
      rows: () => (fail ? problem(500, { type: 'error' }) : [row()]),
    })
    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar sus filas.')

    fail = false
    await button(wrapper, 'Reintentar')!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Corte clásico')
  })

  it('tells the team of the shop that the list is for customers (403)', async () => {
    const { wrapper } = await open({ rows: () => problem(403, { type: 'prohibido' }) })

    expect(wrapper.text()).toContain('Esta lista es para clientes')
  })

  it('is not there for a shop that does not exist', async () => {
    mockOffersApi()
    const { wrapper } = await mountView(WaitlistView, '/b/inexistente/lista-de-espera')
    await flushPromises()

    expect(wrapper.text()).toContain('No encontramos esta barbería')
  })
})

describe('«Lista de espera» of the customer: the rows', () => {
  it('lists the rows with their state, the day and the barber', async () => {
    const { wrapper } = await open({
      rows: () => [
        row(),
        row({ id: 'f2', servicio: 's2', barbero: null, fecha: '2030-03-06', estado: 'ofrecida' }),
      ],
    })

    const items = wrapper.findAll('li')
    expect(items).toHaveLength(2)
    expect(items[0]!.text()).toContain('Corte clásico')
    expect(items[0]!.text()).toContain('Cualquier barbero')
    expect(items[0]!.text()).toContain('mar 5 mar')
    expect(items[0]!.text()).toContain('En espera')
    expect(items[1]!.text()).toContain('Corte + barba')
    expect(items[1]!.text()).toContain('Con oferta')
    expect(wrapper.text()).toContain('Tiene 2 de 3 filas activas.')
  })

  it('shows the name of the barber that the API gives, without reading the day for it', async () => {
    const { wrapper, requests } = await open({
      rows: () => [row({ barbero: offer().barbero, barbero_nombre: 'Daniela' })],
    })

    expect(wrapper.get('li').text()).toContain('con Daniela')
    expect(requests.filter((item) => item.endsWith('/disponibilidad'))).toEqual([])
  })

  it('looks for the name only when the API sends null, and does not invent one otherwise', async () => {
    const { wrapper } = await open({
      rows: () => [row({ barbero: offer().barbero, barbero_nombre: null })],
    })

    expect(wrapper.get('li').text()).toContain('con Mauricio')

    const other = await open({
      rows: () => [row({ barbero: 'desconocido', barbero_nombre: null })],
      barbers: [],
    })
    expect(other.wrapper.get('li').text()).toContain('Barbero elegido')
  })

  it('leaves a row after asking, and keeps it if the person changes their mind', async () => {
    let rows = [row()]
    const { wrapper, requests } = await open({ rows: () => rows })

    await button(wrapper, 'Salir de la fila')!.trigger('click')
    const dialog = document.querySelector('[role="alertdialog"]')!
    expect(dialog.textContent).toContain('¿Salir de la fila?')
    ;(
      [...dialog.querySelectorAll('button')].find(
        (b) => b.textContent === 'No, seguir en la fila',
      ) as HTMLElement
    ).click()
    await flushPromises()
    expect(requests.filter((item) => item.startsWith('DELETE'))).toEqual([])
    expect(wrapper.findAll('li')).toHaveLength(1)

    await button(wrapper, 'Salir de la fila')!.trigger('click')
    rows = []
    ;(
      [...document.querySelectorAll('[role="alertdialog"] button')].find(
        (b) => b.textContent === 'Sí, salir',
      ) as HTMLElement
    ).click()
    await flushPromises()

    expect(requests).toContain(`DELETE ${BASE}/filas/${ROW_ID}`)
    expect(wrapper.text()).toContain('No está en ninguna fila')
  })

  it('a row that cannot be left shows the reason and stays', async () => {
    const { wrapper } = await open({
      rows: () => [row()],
      leave: () =>
        problem(409, {
          type: 'http://localhost/problemas/conflicto',
          detail: 'La fila ya se cumplió con una reserva.',
        }),
    })

    await button(wrapper, 'Salir de la fila')!.trigger('click')
    ;(
      [...document.querySelectorAll('[role="alertdialog"] button')].find(
        (b) => b.textContent === 'Sí, salir',
      ) as HTMLElement
    ).click()
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('La fila ya se cumplió con una reserva.')
    expect(wrapper.findAll('li')).toHaveLength(1)
  })
})

describe('«Lista de espera» of the customer: the offer of a row', () => {
  const offered = { rows: () => [row({ estado: 'ofrecida' })], offers: () => [offer()] }

  it('shows the offer with its countdown and the three answers', async () => {
    const { wrapper } = await open({ ...offered, vouchers: [bought()] })

    const card = wrapper.get('li')
    expect(card.text()).toContain('Se liberó un espacio')
    expect(card.get('[role="timer"]').text()).toMatch(/1[45]:\d\d/)
    expect(button(wrapper, 'Aceptar')).toBeDefined()
    expect(button(wrapper, 'Usar bono')).toBeDefined()
    expect(button(wrapper, 'Rechazar')).toBeDefined()
  })

  it('offers no voucher without one that covers the service', async () => {
    const { wrapper } = await open(offered)

    expect(button(wrapper, 'Usar bono')).toBeUndefined()
  })

  it('accepting goes to the confirmation of the reservation', async () => {
    const { wrapper, router, requests } = await open(offered)

    await button(wrapper, 'Aceptar')!.trigger('click')
    await flushPromises()

    expect(requests).toContain(`POST ${BASE}/ofertas/${OFFER_ID}/aceptacion`)
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('booking-confirmed'))
  })

  it('rejecting sends the answer and the row stays, waiting', async () => {
    let rows = [row({ estado: 'ofrecida' })]
    const { wrapper, requests } = await open({
      rows: () => rows,
      offers: () => [offer()],
      reject: () => {
        rows = [row()]
        return jsonResponse(200, { data: offer({ estado: 'rechazada' }) })
      },
    })

    await button(wrapper, 'Rechazar')!.trigger('click')
    await flushPromises()

    expect(requests).toContain(`POST ${BASE}/ofertas/${OFFER_ID}/rechazo`)
    expect(wrapper.get('li').text()).toContain('En espera')
    expect(wrapper.text()).not.toContain('Se liberó un espacio')
  })

  it('an offer that expires while the screen is open says so, and does not answer', async () => {
    const { wrapper } = await open(offered)

    await useOffersStore().handleEvent('.oferta.cerrada', closed('vencida'))
    await flushPromises()

    expect(wrapper.get('li').text()).toContain('La oferta venció.')
    expect(button(wrapper, 'Aceptar')).toBeUndefined()
  })

  it('an accepted offer that waits for its payment leads to the payment', async () => {
    const { wrapper } = await open({
      rows: () => [row({ estado: 'ofrecida' })],
      offers: () => [offer({ reserva: RESERVATION_ID })],
    })

    expect(wrapper.text()).toContain('Pago pendiente.')
    expect(wrapper.text()).not.toContain('Se liberó un espacio')
    expect(wrapper.get(`a[href="/b/pura-navaja/pago/${RESERVATION_ID}"]`).text()).toBe(
      'Continuar al pago',
    )
  })
})
