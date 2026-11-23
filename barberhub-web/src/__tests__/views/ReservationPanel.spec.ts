import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { mountView } from '../helpers/mountView'
import { signIn } from '../helpers/account'
import { jsonResponse, problem } from '../helpers/http'
import { agendaApi, freezeClock } from '../helpers/agenda'

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

async function openPanel(
  reservation: string,
  options: Parameters<typeof agendaApi>[0] = {},
  query = '',
) {
  const api = agendaApi(options)
  const mounted = await mountView(App, `/barberias/${api.shopId}/agenda/${reservation}${query}`)
  await flushPromises()
  return { ...mounted, ...api }
}

interface Clickable {
  attributes: (name?: string) => string | undefined
  trigger: (event: string) => Promise<void>
  exists: () => boolean
}

/** The button that starts with `text`; a missing one answers `exists() === false`. */
function button(
  wrapper: { findAll: (selector: string) => Array<Clickable & { text: () => string }> },
  text: string,
): Clickable {
  const found = wrapper.findAll('button').find((b) => b.text().startsWith(text))
  return found ?? { exists: () => false, attributes: () => undefined, trigger: async () => {} }
}

describe('ReservationPanel: the owner', () => {
  it('shows the reservation, the total, the pending balance and the history, newest first', async () => {
    const { wrapper } = await openPanel('r4')

    const panel = wrapper.get('aside')
    expect(panel.get('h2').text()).toBe('Corte + barba')
    expect(panel.text()).toContain('Confirmada')
    expect(panel.text()).toContain('Kevin')
    expect(panel.text()).toContain('Javier Araya')
    expect(panel.text()).toContain('jue 5 nov 2026, 11:30–12:15')
    expect(panel.text()).toContain('Total')
    expect(panel.text()).toContain('₡10.000')
    expect(panel.text()).toContain('Saldo pendiente')
    expect(panel.text()).toContain('₡7.000')
    const history = panel.findAll('section[aria-labelledby="panel-history"] li')
    expect(history).toHaveLength(2)
    expect(history[0]!.text()).toContain('Confirmada')
    expect(history[0]!.text()).toContain('Sistema')
    expect(history[1]!.text()).toContain('Pendiente de pago')
    expect(history[1]!.text()).toContain('Javier Araya')
  })

  it('does not show the payment nor the deposit: the API does not deliver them yet', async () => {
    const { wrapper } = await openPanel('r4')

    expect(wrapper.text()).not.toContain('Depósito')
    expect(wrapper.text()).not.toContain('Pago aprobado')
    expect(wrapper.text()).not.toContain('Método')
  })

  it('offers every action to the owner', async () => {
    const { wrapper } = await openPanel('r4')

    for (const label of ['Marcar atendida', 'Marcar ausente', 'Reprogramar', 'Cancelar reserva']) {
      expect(button(wrapper, label).exists(), label).toBe(true)
    }
  })

  it('comes back to the agenda keeping the filters', async () => {
    const { wrapper } = await openPanel('r4', {}, '?estado=confirmada')

    const back = wrapper.get('aside a')
    expect(back.text()).toContain('Volver a la agenda')
    expect(back.attributes('href')).toContain('estado=confirmada')
    expect(back.attributes('href')).not.toContain('/agenda/r4')
  })

  it('says so when the reservation does not exist, with the way back', async () => {
    const { wrapper } = await openPanel('nope')

    expect(wrapper.get('aside [role="alert"]').text()).toContain('No encontramos la reserva.')
  })

  it('shows the error with «Reintentar» when the detail fails', async () => {
    const { wrapper, calls } = await openPanel('r4', { detailStatus: 500 })

    expect(wrapper.get('aside [role="alert"]').text()).toContain('No pudimos cargar la reserva.')
    const before = calls.requests.length
    await button(wrapper, 'Reintentar').trigger('click')
    await flushPromises()
    expect(calls.requests.length).toBeGreaterThan(before)
  })

  it('never promises an email to the customer (it arrives with #96)', async () => {
    const { wrapper } = await openPanel('r4')
    await button(wrapper, 'Cancelar reserva').trigger('click')

    expect(wrapper.text()).not.toContain('correo')
    await button(wrapper, 'No, mantenerla').trigger('click')
    await button(wrapper, 'Reprogramar').trigger('click')
    await flushPromises()
    expect(wrapper.text()).not.toContain('correo')
  })
})

describe('ReservationPanel: the collaborator', () => {
  it('sees the pending balance, but no total, no history and no payment', async () => {
    const { wrapper } = await openPanel('r4', { role: 'colaborador' })

    const panel = wrapper.get('aside')
    expect(panel.text()).toContain('Saldo pendiente')
    expect(panel.text()).toContain('₡7.000')
    expect(panel.text()).not.toContain('Total')
    expect(panel.text()).not.toContain('₡10.000')
    expect(panel.text()).not.toContain('Historial')
    expect(panel.text()).not.toContain('Depósito')
  })

  it('can only mark the reservation, not move nor cancel it', async () => {
    const { wrapper } = await openPanel('r4', { role: 'colaborador' })

    expect(button(wrapper, 'Marcar atendida').exists()).toBe(true)
    expect(button(wrapper, 'Marcar ausente').exists()).toBe(true)
    expect(button(wrapper, 'Reprogramar').exists()).toBe(false)
    expect(button(wrapper, 'Cancelar reserva').exists()).toBe(false)
  })
})

describe('ReservationPanel: closing (RF-18, CO-05)', () => {
  it('before the start both marks are disabled, with the reason', async () => {
    const { wrapper } = await openPanel('r4')

    expect(button(wrapper, 'Marcar atendida').attributes('disabled')).toBeDefined()
    expect(button(wrapper, 'Marcar ausente').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain(
      'Podrá marcarla como atendida desde las 11:30 y como ausente desde las 11:40, diez minutos después de la hora de inicio.',
    )
  })

  it('after the start only «atendida» is enabled; after the tolerance, both', async () => {
    vi.setSystemTime(new Date('2026-11-05T17:35:00Z'))
    const first = await openPanel('r4')
    expect(button(first.wrapper, 'Marcar atendida').attributes('disabled')).toBeUndefined()
    expect(button(first.wrapper, 'Marcar ausente').attributes('disabled')).toBeDefined()
    first.wrapper.unmount()

    vi.setSystemTime(new Date('2026-11-05T17:42:00Z'))
    const second = await openPanel('r4')
    expect(button(second.wrapper, 'Marcar atendida').attributes('disabled')).toBeUndefined()
    expect(button(second.wrapper, 'Marcar ausente').attributes('disabled')).toBeUndefined()
    expect(second.wrapper.text()).not.toContain('Podrá marcarla')
  })

  it('marks the reservation as attended and tells it', async () => {
    vi.setSystemTime(new Date('2026-11-05T17:45:00Z'))
    const { wrapper, calls } = await openPanel('r4')

    await button(wrapper, 'Marcar atendida').trigger('click')
    await flushPromises()

    expect(calls.closing).toEqual([{ id: 'r4', body: { estado: 'atendida' } }])
    expect(wrapper.get('[role="status"]').text()).toContain('Reserva marcada como atendida.')
  })

  it('marks an absence with its own outcome', async () => {
    vi.setSystemTime(new Date('2026-11-05T17:45:00Z'))
    const { wrapper, calls } = await openPanel('r4')

    await button(wrapper, 'Marcar ausente').trigger('click')
    await flushPromises()

    expect(calls.closing).toEqual([{ id: 'r4', body: { estado: 'ausente' } }])
  })

  it('shows what the API says when it refuses (409)', async () => {
    vi.setSystemTime(new Date('2026-11-05T17:45:00Z'))
    const { wrapper } = await openPanel('r4', {
      actionResponse: () =>
        problem(409, {
          type: 'http://localhost/problemas/conflicto',
          title: 'Conflicto',
          detail: 'Esta reserva ya está cerrada y su estado no se puede cambiar.',
        }),
    })

    await button(wrapper, 'Marcar atendida').trigger('click')
    await flushPromises()

    const alert = wrapper.get('[data-testid="action-error"]')
    expect(alert.text()).toContain('No pudimos completar la acción.')
    expect(alert.text()).toContain('Esta reserva ya está cerrada')
  })

  it('a closed reservation shows no action, only why', async () => {
    const { wrapper } = await openPanel('r1')

    expect(wrapper.get('[data-testid="closed-note"]').text()).toBe(
      'Esta reserva ya fue marcada como atendida.',
    )
    expect(button(wrapper, 'Marcar atendida').exists()).toBe(false)
    expect(button(wrapper, 'Cancelar reserva').exists()).toBe(false)
  })
})

describe('ReservationPanel: cancelling (RF-16)', () => {
  it('asks first, and «No, mantenerla» changes nothing', async () => {
    const { wrapper, calls } = await openPanel('r4')

    await button(wrapper, 'Cancelar reserva').trigger('click')
    expect(wrapper.text()).toContain('¿Cancelar la reserva de Javier Araya?')
    expect(wrapper.text()).toContain('«cancelada por la barbería»')

    await button(wrapper, 'No, mantenerla').trigger('click')
    expect(calls.cancellations).toEqual([])
    expect(button(wrapper, 'Cancelar reserva').exists()).toBe(true)
  })

  it('cancels with «Sí, cancelar reserva» and refreshes the agenda', async () => {
    const { wrapper, calls } = await openPanel('r4')
    await button(wrapper, 'Cancelar reserva').trigger('click')
    const before = calls.requests.filter((r) => r.startsWith('GET') && r.includes('dia=')).length

    await button(wrapper, 'Sí, cancelar reserva').trigger('click')
    await flushPromises()

    expect(calls.cancellations).toEqual(['r4'])
    expect(wrapper.get('[role="status"]').text()).toContain('Reserva cancelada.')
    const after = calls.requests.filter((r) => r.startsWith('GET') && r.includes('dia=')).length
    expect(after).toBeGreaterThan(before)
  })

  it('keeps the reservation and says why when the API refuses', async () => {
    const { wrapper } = await openPanel('r4', {
      actionResponse: () => jsonResponse(409, { status: 409, detail: 'No se puede cancelar.' }),
    })
    await button(wrapper, 'Cancelar reserva').trigger('click')

    await button(wrapper, 'Sí, cancelar reserva').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="action-error"]').text()).toContain('No se puede cancelar.')
  })
})

describe('ReservationPanel: rescheduling (RF-15)', () => {
  it('opens the form in the panel without a deadline, and «Volver a la reserva» closes it', async () => {
    const { wrapper } = await openPanel('r4')

    await button(wrapper, 'Reprogramar').trigger('click')
    await flushPromises()

    expect(wrapper.get('aside h2#reschedule-title').text()).toBe('Reprogramar reserva')
    expect(wrapper.get('aside').text()).not.toContain('sin costo hasta')
    await button(wrapper, 'Volver a la reserva').trigger('click')
    await flushPromises()
    expect(wrapper.find('#reschedule-title').exists()).toBe(false)
    expect(button(wrapper, 'Reprogramar').exists()).toBe(true)
  })
})
