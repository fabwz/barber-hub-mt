import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import BookingDetailView from '@/views/BookingDetailView.vue'
import { MAURICIO } from '../helpers/booking'
import { jsonResponse, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { DETAIL_ID, DETAIL_PAGE, mockBookingsFetch, signIn } from '../helpers/myBookings'

const mounted: VueWrapper[] = []

async function open(handlers: Parameters<typeof mockBookingsFetch>[0] = {}) {
  const mock = mockBookingsFetch(handlers)
  const view = await mountView(BookingDetailView, DETAIL_PAGE)
  mounted.push(view.wrapper)
  await flushPromises()
  await vi.waitFor(() => expect(view.wrapper.find('main[aria-busy="true"]').exists()).toBe(false))
  return { ...view, ...mock }
}

const button = (wrapper: VueWrapper, text: string) =>
  wrapper.findAll('button').find((b) => b.text() === text)

const chip = (wrapper: VueWrapper, time: string) =>
  wrapper.findAll('button[aria-pressed]').find((b) => b.text().startsWith(time))!

async function openReschedule(handlers: Parameters<typeof mockBookingsFetch>[0] = {}) {
  const view = await open(handlers)
  await button(view.wrapper, 'Reprogramar')!.trigger('click')
  await flushPromises()
  await vi.waitFor(() => expect(view.wrapper.find('[aria-busy="true"]').exists()).toBe(false))
  return view
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

describe('Reschedule', () => {
  it('asks for the spaces of the same service and barber, and moves the reservation', async () => {
    const view = await openReschedule()
    const { wrapper } = view

    expect(wrapper.get('h1').text()).toBe('Reprogramar reserva')
    expect(document.activeElement).toBe(wrapper.get('h1').element)
    expect(wrapper.text()).toContain('No se vuelve a cobrar el depósito')
    expect(wrapper.text()).toContain('hasta 4 horas antes de la cita')
    const query = view.calls.find((c) => c.path.endsWith('/disponibilidad'))!.search
    expect(query).toContain('servicio=s2')
    expect(query).toContain(`barbero=${MAURICIO}`)
    expect(button(wrapper, 'Confirmar nuevo horario')!.attributes('disabled')).toBeDefined()

    await chip(wrapper, '11:00').trigger('click')
    await button(wrapper, 'Confirmar nuevo horario')!.trigger('click')
    await flushPromises()

    const patch = view.calls.find((c) => c.method === 'PATCH')!
    expect(JSON.parse(patch.body)).toEqual({
      inicio: '2030-03-05T11:00:00-06:00',
      barbero: MAURICIO,
    })
    // Back to the reservation, with the new hour, a notice and no new charge.
    expect(wrapper.get('h1').text()).toBe('Su reserva')
    expect(wrapper.text()).toContain('11:00–11:45')
    expect(wrapper.text()).toContain('Su cita quedó para el mar 5 mar, a las 11:00.')
    expect(view.count('POST', '/pagos')).toBe(0)
  })

  it('leaves the original reservation as it was when the space is gone, and offers the alternatives', async () => {
    const view = await openReschedule({
      reschedule: () =>
        problem(409, {
          type: 'espacio-no-disponible',
          title: 'Espacio no disponible',
          detail: 'Ese espacio ya no está disponible.',
          alternativas: [
            {
              barbero: MAURICIO,
              inicio: '2030-03-05T11:30:00-06:00',
              fin: '2030-03-05T12:15:00-06:00',
            },
          ],
        }),
    })
    const { wrapper } = view

    await chip(wrapper, '11:00').trigger('click')
    await button(wrapper, 'Confirmar nuevo horario')!.trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('Ese espacio ya no está disponible.')
    expect(wrapper.get('[role="alert"]').text()).toContain('Su reserva actual no cambió.')
    const alternatives = wrapper.findAll('[aria-label="Otros espacios cercanos"] button')
    expect(alternatives.length).toBeGreaterThan(0)
    // Still on the reschedule screen: the reservation was not touched.
    expect(wrapper.get('h1').text()).toBe('Reprogramar reserva')

    await alternatives[0]!.trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Nueva cita')
    expect(wrapper.text()).toContain('11:30')

    await button(wrapper, 'Volver a la reserva')!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('10:30–11:15')
  })

  it('shows the error of the API when the limit of reschedules is exceeded, with a way back', async () => {
    const { wrapper } = await openReschedule({
      reschedule: () =>
        problem(409, {
          type: 'conflicto',
          title: 'Conflicto',
          detail: 'Esta reserva ya se reprogramó 2 veces.',
        }),
    })

    await chip(wrapper, '11:00').trigger('click')
    await button(wrapper, 'Confirmar nuevo horario')!.trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('Esta reserva ya se reprogramó 2 veces.')
    expect(wrapper.text()).toContain('Su reserva actual no cambió.')
    await button(wrapper, 'Volver a la reserva')!.trigger('click')
    await flushPromises()
    expect(wrapper.get('h1').text()).toBe('Su reserva')
    expect(wrapper.text()).toContain('10:30–11:15')
  })

  it('does not send the change twice on a double click', async () => {
    let release: () => void = () => {}
    const gate = new Promise<void>((resolve) => (release = resolve))
    const view = await openReschedule({
      reschedule: async () => {
        await gate
        return jsonResponse(200, {
          data: {
            id: DETAIL_ID,
            inicio: '2030-03-05T11:00:00-06:00',
            fin: '2030-03-05T11:45:00-06:00',
          },
        })
      },
    })
    await chip(view.wrapper, '11:00').trigger('click')
    const confirm = button(view.wrapper, 'Confirmar nuevo horario')!
    await confirm.trigger('click')
    await confirm.trigger('click')
    release()
    await flushPromises()

    expect(view.calls.filter((c) => c.method === 'PATCH')).toHaveLength(1)
  })
})

describe('Cancel', () => {
  async function openCancel(handlers: Parameters<typeof mockBookingsFetch>[0] = {}) {
    const view = await open(handlers)
    await button(view.wrapper, 'Cancelar')!.trigger('click')
    await flushPromises()
    return view
  }

  it('asks for confirmation in line, with the window, and keeps the reservation if declined', async () => {
    const view = await openCancel()
    const { wrapper } = view

    const group = wrapper.get('[role="group"][aria-labelledby="cancel-title"]')
    expect(group.text()).toContain('¿Cancelar esta reserva?')
    expect(group.text()).toContain('hasta 4 horas antes de la cita')
    expect(document.activeElement).toBe(wrapper.get('#cancel-title').element)
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)

    await button(wrapper, 'Mantener reserva')!.trigger('click')
    expect(wrapper.find('[role="group"][aria-labelledby="cancel-title"]').exists()).toBe(false)
    expect(view.count('POST', '/cancelacion')).toBe(0)
  })

  it.each([
    ['a_tiempo', 'Cancelación a tiempo: la hizo dentro del plazo.'],
    ['tardia', 'Cancelación tardía: la hizo después del plazo.'],
  ])('says in plain words that it was %s, and promises no money', async (clasificacion, text) => {
    const { wrapper } = await openCancel({
      cancel: () =>
        jsonResponse(200, {
          data: { id: DETAIL_ID, estado: 'cancelada_por_cliente', clasificacion },
        }),
    })

    await button(wrapper, 'Sí, cancelar reserva')!.trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="status"].grow, .grow').text()).toContain(
      'Su reserva quedó cancelada.',
    )
    expect(wrapper.text()).toContain(text)
    expect(wrapper.text()).toContain('Cancelada')
    expect(wrapper.text()).not.toMatch(/devol|reembols/i)
    expect(button(wrapper, 'Reprogramar')).toBeUndefined()
    expect(button(wrapper, 'Cancelar')).toBeUndefined()
  })

  it('treats a repeated cancellation (200) as a success', async () => {
    const view = await openCancel()
    await button(view.wrapper, 'Sí, cancelar reserva')!.trigger('click')
    await flushPromises()

    expect(view.wrapper.text()).toContain('Su reserva quedó cancelada.')
    expect(view.wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('blocks the button while the request is on its way (double click)', async () => {
    let release: () => void = () => {}
    const gate = new Promise<void>((resolve) => (release = resolve))
    const view = await openCancel({
      cancel: async () => {
        await gate
        return jsonResponse(200, {
          data: { id: DETAIL_ID, estado: 'cancelada_por_cliente', clasificacion: 'a_tiempo' },
        })
      },
    })
    const confirm = button(view.wrapper, 'Sí, cancelar reserva')!
    await confirm.trigger('click')
    await confirm.trigger('click')

    expect(view.wrapper.findAll('button[aria-busy="true"]')).toHaveLength(1)
    expect(view.count('POST', '/cancelacion')).toBe(1)
    release()
    await flushPromises()
    expect(view.count('POST', '/cancelacion')).toBe(1)
  })

  it('shows the error of the API and keeps the reservation when the cancellation fails', async () => {
    const { wrapper } = await openCancel({
      cancel: () =>
        problem(409, { type: 'conflicto', title: 'Conflicto', detail: 'No se puede cancelar.' }),
    })
    await button(wrapper, 'Sí, cancelar reserva')!.trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('No se puede cancelar.')
    expect(wrapper.text()).toContain('Confirmada')
  })
})
