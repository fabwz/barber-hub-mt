import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import MyBookingsView from '@/views/MyBookingsView.vue'
import { jsonResponse, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import {
  DETAIL_PAGE,
  LIST_PAGE,
  listed,
  mockBookingsFetch,
  page,
  signIn,
} from '../helpers/myBookings'

const mounted: VueWrapper[] = []

async function open(handlers: Parameters<typeof mockBookingsFetch>[0] = {}) {
  const mock = mockBookingsFetch(handlers)
  const view = await mountView(MyBookingsView, LIST_PAGE)
  mounted.push(view.wrapper)
  await flushPromises()
  await vi.waitFor(() => expect(view.wrapper.find('main[aria-busy="true"]').exists()).toBe(false))
  return { ...view, ...mock }
}

beforeEach(() => {
  sessionStorage.clear()
  signIn()
})
afterEach(() => {
  for (const view of mounted.splice(0)) view.unmount()
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('MyBookingsView', () => {
  it('groups upcoming and past reservations with status, hour and what the API says is due', async () => {
    const { wrapper } = await open({
      list: () =>
        jsonResponse(
          200,
          page([
            listed({
              id: 'old-1',
              estado: 'completada',
              inicio: '2020-10-20T14:00:00-06:00',
              fin: '2020-10-20T14:30:00-06:00',
            }),
            listed({
              id: 'old-2',
              estado: 'cancelada_por_cliente',
              inicio: '2021-10-02T11:30:00-06:00',
              fin: '2021-10-02T12:10:00-06:00',
            }),
            listed({ id: 'next-1', servicio_nombre: 'Fade' }),
            listed({
              id: 'next-2',
              estado: 'temporal',
              servicio_nombre: 'Barba',
              monto_a_pagar: 1500,
              saldo_en_local: 3500,
            }),
          ]),
        ),
    })

    const headings = wrapper.findAll('h2').map((h) => h.text())
    expect(headings).toEqual(['Próximas', 'Pasadas'])
    const [upcoming, past] = wrapper.findAll('section')
    expect(upcoming!.findAll('li')).toHaveLength(2)
    expect(upcoming!.text()).toContain('Fade')
    expect(upcoming!.text()).toContain('10:30–11:15')
    expect(upcoming!.text()).toContain('Confirmada')
    expect(upcoming!.text()).toContain('Total')
    expect(upcoming!.text()).toContain('₡10.000')
    expect(upcoming!.text()).toContain('Pendiente de pago')
    expect(upcoming!.text()).toContain('A pagar ahora')
    expect(upcoming!.text()).toContain('₡1.500')
    // Past: the most recent first.
    const rows = past!.findAll('li')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('Cancelada')
    expect(rows[1]!.text()).toContain('Atendida')
    expect(rows[0]!.get('a').attributes('href')).toBe('/b/pura-navaja/mis-reservas/old-2')
  })

  it('asks for every page', async () => {
    const mock = await open({
      list: (request) => {
        const pagina = Number(new URL(request.url).searchParams.get('pagina'))
        return jsonResponse(200, page([listed({ id: `p${pagina}` })], pagina, 2))
      },
    })

    expect(mock.count('GET', '/mis-reservas')).toBe(2)
    expect(mock.wrapper.findAll('main li')).toHaveLength(2)
  })

  it('offers «Continuar al pago» for a live temporary reservation and not for an expired one', async () => {
    const { wrapper } = await open({
      list: () =>
        jsonResponse(
          200,
          page([
            listed({ id: 'live', estado: 'temporal' }),
            listed({ id: 'gone', estado: 'expirada' }),
          ]),
        ),
    })

    const pay = wrapper.findAll('a').filter((a) => a.text() === 'Continuar al pago')
    expect(pay).toHaveLength(1)
    expect(pay[0]!.attributes('href')).toBe('/b/pura-navaja/pago/live')
    expect(wrapper.text()).toContain('Expirada')
  })

  it('shows «Aún no tiene reservas» with «Reservar» toward the shop', async () => {
    const { wrapper } = await open({ list: () => jsonResponse(200, page([])) })

    expect(wrapper.text()).toContain('Aún no tiene reservas')
    const link = wrapper.findAll('a').find((a) => a.text() === 'Reservar')
    expect(link?.attributes('href')).toBe('/b/pura-navaja')
  })

  it('shows skeletons while loading', async () => {
    let release: () => void = () => {}
    const gate = new Promise<void>((resolve) => (release = resolve))
    mockBookingsFetch({
      list: async () => {
        await gate
        return jsonResponse(200, page([]))
      },
    })
    const { wrapper } = await mountView(MyBookingsView, LIST_PAGE)
    mounted.push(wrapper)
    await vi.waitFor(() => expect(wrapper.find('.animate-pulse').exists()).toBe(true))

    expect(wrapper.get('main').attributes('aria-busy')).toBe('true')
    release()
    await flushPromises()
    expect(wrapper.find('.animate-pulse').exists()).toBe(false)
  })

  it('shows the error with «Reintentar», which asks again', async () => {
    let fail = true
    const { wrapper, count } = await open({
      list: () => (fail ? problem(500) : jsonResponse(200, page([listed()]))),
    })

    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar sus reservas.')
    fail = false
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Reintentar')!
      .trigger('click')
    await flushPromises()

    expect(count('GET', '/mis-reservas')).toBe(2)
    expect(wrapper.text()).toContain('Próximas')
  })

  it('tells staff the list is for customers (403)', async () => {
    const { wrapper } = await open({ list: () => problem(403, { type: 'prohibido' }) })

    expect(wrapper.text()).toContain('Esta lista es para clientes')
  })

  it('shows the state as text only, with no dot', async () => {
    const { wrapper } = await open()

    expect(wrapper.text()).toContain('Confirmada')
    expect(wrapper.find('main li .rounded-full').exists()).toBe(false)
  })

  it('goes back to the shop with «Volver» when nothing is behind', async () => {
    const { wrapper, router } = await open()

    const back = wrapper.findAll('button').find((b) => b.text() === 'Volver')!
    await back.trigger('click')

    // The shop is a lazy route: the navigation ends after its module loads.
    await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/b/pura-navaja'))
  })

  it('does not loop between the list and a reservation when going back', async () => {
    mockBookingsFetch()
    const { wrapper, router } = await mountView(App, LIST_PAGE)
    mounted.push(wrapper)
    const path = () => router.currentRoute.value.path
    const waitFor = (expected: string) => vi.waitFor(() => expect(path()).toBe(expected))
    const press = async (tag: string, text: string) => {
      await vi.waitFor(() =>
        expect(wrapper.findAll(tag).some((el) => el.text() === text)).toBe(true),
      )
      await wrapper
        .findAll(tag)
        .find((el) => el.text() === text)!
        .trigger('click')
    }

    // List -> reservation (a row), then «Mis reservas» of the reservation: back to the list.
    await vi.waitFor(() => expect(wrapper.find('main li a').exists()).toBe(true))
    await wrapper.get('main li a').trigger('click')
    await waitFor(DETAIL_PAGE)
    await press('button', 'Mis reservas')
    await waitFor(LIST_PAGE)

    // «Volver» of the list must not return to the reservation: nothing else is behind, so the shop.
    await press('button', 'Volver')
    await waitFor('/b/pura-navaja')
  })

  it('moves the focus to the title once loaded', async () => {
    const { wrapper } = await open()

    expect(document.activeElement).toBe(wrapper.get('h1').element)
  })
})
