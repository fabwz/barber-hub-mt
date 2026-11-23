import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { mountView } from '../helpers/mountView'
import { signIn } from '../helpers/account'
import { DAY, DAY_ITEMS, agendaApi, freezeClock } from '../helpers/agenda'

beforeEach(() => {
  signIn('marco@example.test')
  freezeClock()
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

async function open(options: Parameters<typeof agendaApi>[0] = {}, query = '') {
  const api = agendaApi(options)
  const mounted = await mountView(App, `/barberias/${api.shopId}/agenda${query}`)
  await flushPromises()
  return { ...mounted, ...api }
}

describe('StaffAgendaView: the owner', () => {
  it('asks the whole day with `dia` and shows the rows without the expired ones', async () => {
    const { wrapper, calls, shopId } = await open()

    expect(wrapper.get('h1').text()).toBe('Agenda')
    expect(
      calls.requests.some(
        (r) => r.includes(`/barberias/${shopId}/reservas?`) && r.includes(`dia=${DAY}`),
      ),
    ).toBe(true)
    const rows = wrapper.findAll('ul[aria-label="Reservas del día"] a')
    expect(rows).toHaveLength(5)
    expect(rows[0]!.text()).toContain('09:00–09:30')
    expect(rows[0]!.text()).toContain('Luis Mora')
    expect(rows[0]!.text()).toContain('Atendida')
    expect(rows[0]!.text()).toContain('Corte clásico · Mauricio')
    expect(wrapper.text()).not.toContain('Ana Quirós')
    expect(wrapper.text()).not.toContain('Expirada')
    expect(wrapper.text()).toContain('5 reservas')
  })

  it('marks the current time between the reservations that passed and those to come', async () => {
    const { wrapper } = await open()

    const marker = wrapper.get('[data-testid="now-marker"]')
    expect(marker.text()).toContain('11:20')
    const items = wrapper.findAll('ul[aria-label="Reservas del día"] > li')
    const at = items.findIndex((item) => item.attributes('data-testid') === 'now-marker')
    expect(items[at - 1]!.text()).toContain('Carlos Jiménez')
    expect(items[at + 1]!.text()).toContain('Javier Araya')
  })

  it('has no mark on another day', async () => {
    const { wrapper } = await open({}, '?dia=2026-11-04')

    expect(wrapper.find('[data-testid="now-marker"]').exists()).toBe(false)
  })

  it('filters by collaborator and by status, and «Cancelada» joins both cancellations', async () => {
    const { wrapper, calls, router } = await open()
    const before = calls.requests.length

    await wrapper.get('#agenda-status').setValue('cancelada')
    await flushPromises()
    expect(wrapper.findAll('ul[aria-label="Reservas del día"] a')).toHaveLength(1)
    expect(router.currentRoute.value.query.estado).toBe('cancelada')

    await wrapper.get('#agenda-status').setValue('')
    await flushPromises()
    await wrapper.get('#agenda-collaborator').setValue('b2')
    await flushPromises()
    const names = wrapper.findAll('ul[aria-label="Reservas del día"] a').map((a) => a.text())
    expect(names).toHaveLength(2)
    expect(names.join(' ')).toContain('Diego Vargas')
    expect(names.join(' ')).toContain('Javier Araya')
    // Filtering is done on the loaded day: the API is not asked again.
    expect(calls.requests.length).toBe(before)
  })

  it('says so when the filters leave nothing, and «Limpiar filtros» brings the day back', async () => {
    const { wrapper } = await open({}, '?estado=ausente&colaborador=b1')

    expect(wrapper.text()).toContain('No hay reservas con esos filtros.')
    const clear = wrapper.findAll('button').filter((b) => b.text() === 'Limpiar filtros')
    await clear[0]!.trigger('click')
    await flushPromises()

    expect(wrapper.findAll('ul[aria-label="Reservas del día"] a')).toHaveLength(5)
  })

  it('moves between days with Anterior, Siguiente and Hoy', async () => {
    const { wrapper, calls, router } = await open()
    const button = (text: string) => wrapper.findAll('button').find((b) => b.text() === text)!

    expect(button('Hoy').attributes('disabled')).toBeDefined()
    await button('Siguiente').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query.dia).toBe('2026-11-06')
    expect(calls.requests.some((r) => r.includes('dia=2026-11-06'))).toBe(true)
    expect(wrapper.text()).toContain('No hay reservas este día.')

    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Ir a hoy')!
      .trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query.dia).toBeUndefined()
    expect(wrapper.findAll('ul[aria-label="Reservas del día"] a')).toHaveLength(5)
  })

  it('does not offer «Bloqueos» nor «Reserva asistida» yet', async () => {
    const { wrapper } = await open()

    expect(wrapper.text()).not.toContain('Bloqueos')
    expect(wrapper.text()).not.toContain('Reserva asistida')
  })

  it('shows the empty state of the day', async () => {
    const { wrapper } = await open({ items: [] })

    expect(wrapper.text()).toContain('No hay reservas este día.')
    expect(wrapper.find('ul[aria-label="Reservas del día"]').exists()).toBe(false)
  })

  it('shows the error with «Reintentar», which asks again', async () => {
    const { wrapper, calls } = await open({ listStatus: 500 })

    expect(wrapper.get('[role="alert"]').text()).toContain(
      'No pudimos cargar la agenda del jue 5 nov.',
    )
    const before = calls.requests.length
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Reintentar')!
      .trigger('click')
    await flushPromises()

    expect(calls.requests.length).toBeGreaterThan(before)
  })

  it('shows the skeleton while it loads', async () => {
    const api = agendaApi()
    const mounted = await mountView(App, `/barberias/${api.shopId}/agenda`)

    expect(mounted.wrapper.get('section[aria-busy="true"]').text()).toContain('Cargando la agenda')
    await flushPromises()
  })
})

describe('StaffAgendaView: the collaborator', () => {
  it('sees «Mi agenda», no collaborator filter and no collaborator on the rows', async () => {
    const { wrapper } = await open({ role: 'colaborador' })

    expect(wrapper.get('h1').text()).toBe('Mi agenda')
    expect(wrapper.find('#agenda-collaborator').exists()).toBe(false)
    expect(wrapper.find('#agenda-status').exists()).toBe(true)
    const row = wrapper.get('ul[aria-label="Reservas del día"] a')
    expect(row.text()).toContain('Corte clásico')
    expect(row.text()).not.toContain('Mauricio')
  })

  it('ignores a collaborator asked in the address', async () => {
    const { wrapper } = await open({ role: 'colaborador' }, '?colaborador=b2')

    expect(wrapper.findAll('ul[aria-label="Reservas del día"] a')).toHaveLength(5)
  })
})

describe('StaffAgendaView: access', () => {
  it('never asks the agenda of a shop that is not theirs: it goes to the selector', async () => {
    const api = agendaApi()
    const foreign = '0190b1f0-0000-7000-8000-0000000000ff'
    const { router } = await mountView(App, `/barberias/${foreign}/agenda`)
    await flushPromises()

    // With a single shop the selector enters that one directly.
    expect(router.currentRoute.value.path).toBe(`/barberias/${api.shopId}/agenda`)
    expect(api.calls.requests.some((r) => r.includes(foreign))).toBe(false)
  })

  it('has a full-width panel and a hidden list on a phone when a reservation is open', async () => {
    const { wrapper, router } = await open({}, '')
    await wrapper.get('ul[aria-label="Reservas del día"] a').trigger('click')
    await flushPromises()
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('staff-reservation'))
    await flushPromises()

    expect(wrapper.get('aside').attributes('aria-label')).toBe('Detalle de la reserva')
    expect(wrapper.get('aside').classes()).toContain('w-full')
    expect(wrapper.get('aside').classes()).toContain('md:w-100')
    expect(wrapper.get('section[aria-labelledby="agenda-title"]').classes()).toContain(
      'max-md:hidden',
    )
    expect(DAY_ITEMS.length).toBeGreaterThan(0)
  })
})
