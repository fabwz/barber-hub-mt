import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { absence, absencesApi, conflict } from '../helpers/absences'
import { signIn } from '../helpers/account'
import { mountView } from '../helpers/mountView'

beforeEach(() => signIn('marco@example.test'))
afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

async function open(options: Parameters<typeof absencesApi>[0] = {}) {
  const api = absencesApi(options)
  const mounted = await mountView(App, `/barberias/${api.shopId}/equipo/bloqueos`)
  await flushPromises()
  return { ...mounted, ...api }
}

async function fill(
  wrapper: Awaited<ReturnType<typeof open>>['wrapper'],
  values: { collaborator?: string; date?: string; from?: string; to?: string },
): Promise<void> {
  if (values.collaborator !== undefined) {
    await wrapper.get('#absence-collaborator').setValue(values.collaborator)
  }
  if (values.date !== undefined) await wrapper.get('input[type="date"]').setValue(values.date)
  const hours = wrapper.findAll('input[inputmode="numeric"]')
  if (values.from !== undefined) await hours[0]!.setValue(values.from)
  if (values.to !== undefined) await hours[1]!.setValue(values.to)
}

const saveButton = (wrapper: Awaited<ReturnType<typeof open>>['wrapper']) =>
  wrapper.findAll('button').find((b) => b.text().includes('Guardar bloqueo'))!

describe('TeamAbsencesView: the current blocks', () => {
  it('lists each block with the collaborator and the period, hours or days', async () => {
    const { wrapper } = await open({
      absences: [
        absence({ id: 'a1' }),
        absence({
          id: 'a2',
          colaborador: 'andres-id',
          inicio: '2030-03-11T00:00:00-06:00',
          fin: '2030-03-16T00:00:00-06:00',
        }),
      ],
    })

    const rows = wrapper.findAll('ul[aria-label="Bloqueos vigentes"] > li')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('Kevin Araya')
    expect(rows[0]!.text()).toContain('mar 5 mar, 14:00–16:00')
    expect(rows[1]!.text()).toContain('Andrés Mora')
    expect(rows[1]!.text()).toContain('lun 11 mar – vie 15 mar')
    expect(wrapper.get('a[aria-current="page"]').text()).toBe('Bloqueos de agenda')
  })

  it('shows the skeleton while it loads', async () => {
    const api = absencesApi()
    const { wrapper } = await mountView(App, `/barberias/${api.shopId}/equipo/bloqueos`)
    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true)
  })

  it('shows the empty state', async () => {
    const { wrapper } = await open()
    expect(wrapper.text()).toContain('No hay bloqueos vigentes.')
    expect(wrapper.text()).toContain(
      'Cuando bloquee horas o días de un colaborador, aparecerán aquí.',
    )
  })

  it('shows the error and reads again', async () => {
    const api = absencesApi({ listFails: true, absences: [absence()] })
    const { wrapper } = await mountView(App, `/barberias/${api.shopId}/equipo/bloqueos`)
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar los bloqueos')
  })

  it('asks in the row before removing, and keeps the block if the owner says no', async () => {
    const { wrapper, blocks, calls } = await open({ absences: [absence({ id: 'a1' })] })

    await wrapper.get('button[aria-label^="Quitar el bloqueo de Kevin Araya"]').trigger('click')
    expect(wrapper.text()).toContain(
      '¿Quitar el bloqueo de Kevin Araya? Esos espacios volverán a estar disponibles.',
    )
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'No, dejarlo')!
      .trigger('click')

    expect(blocks()).toHaveLength(1)
    expect(calls.some((call) => call.startsWith('DELETE'))).toBe(false)
  })

  it('removes the block when the owner confirms', async () => {
    const { wrapper, blocks } = await open({ absences: [absence({ id: 'a1' })] })

    await wrapper.get('button[aria-label^="Quitar el bloqueo de Kevin Araya"]').trigger('click')
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Sí, quitar bloqueo')!
      .trigger('click')
    await flushPromises()

    expect(blocks()).toHaveLength(0)
    expect(wrapper.text()).toContain('Bloqueo quitado. Esos espacios vuelven a ofrecerse.')
    expect(wrapper.text()).toContain('No hay bloqueos vigentes.')
  })
})

describe('TeamAbsencesView: the form', () => {
  it('offers only active collaborators', async () => {
    const { wrapper } = await open()
    const options = wrapper.findAll('#absence-collaborator option').map((o) => o.text())
    expect(options).toEqual(['Elija un colaborador', 'Kevin Araya', 'Andrés Mora'])
  })

  it('saves hours of one day as a range of one date and says so', async () => {
    const { wrapper, saved } = await open()
    await fill(wrapper, { collaborator: 'kevin-id', date: '2030-03-05', from: '1400', to: '1600' })
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(saved).toEqual([
      {
        colaborador: 'kevin-id',
        fecha_desde: '2030-03-05',
        fecha_hasta: '2030-03-05',
        hora_desde: '14:00',
        hora_hasta: '16:00',
      },
    ])
    expect(wrapper.get('[role="status"]').text()).toContain(
      'Bloqueo guardado. Esos espacios ya no se ofrecen.',
    )
    expect(wrapper.findAll('ul[aria-label="Bloqueos vigentes"] > li')).toHaveLength(1)
  })

  it('saves several days without hours', async () => {
    const { wrapper, saved } = await open()
    await wrapper.get('#absence-collaborator').setValue('andres-id')
    await wrapper
      .findAll('[role="radio"]')
      .find((r) => r.text() === 'Varios días')!
      .trigger('click')
    const dates = wrapper.findAll('input[type="date"]')
    await dates[0]!.setValue('2030-03-11')
    await dates[1]!.setValue('2030-03-15')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(saved).toEqual([
      { colaborador: 'andres-id', fecha_desde: '2030-03-11', fecha_hasta: '2030-03-15' },
    ])
    expect(wrapper.text()).toContain('lun 11 mar – vie 15 mar')
  })

  it('names each field that is missing and sends nothing', async () => {
    const { wrapper, saved } = await open()
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Elija el colaborador.')
    expect(wrapper.text()).toContain('Elija la fecha.')
    expect(wrapper.text()).toContain('Escriba las horas como HH:MM, por ejemplo 14:00.')
    expect(wrapper.get('#absence-collaborator').attributes('aria-invalid')).toBe('true')
    expect(saved).toEqual([])
  })

  it('refuses hours in the wrong order before asking the API', async () => {
    const { wrapper, saved } = await open()
    await fill(wrapper, { collaborator: 'kevin-id', date: '2030-03-05', from: '1600', to: '1400' })
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('La hora final debe ser posterior a la inicial.')
    expect(saved).toEqual([])
  })

  it('shows the error the API puts on a field (422)', async () => {
    const { wrapper } = await open({
      saveStatus: {
        status: 422,
        body: {
          type: 'datos-invalidos',
          errores: {
            fecha_hasta: ['El bloqueo ya terminó: indique una fecha de hoy o posterior.'],
          },
        },
      },
    })
    await fill(wrapper, { collaborator: 'kevin-id', date: '2030-03-05', from: '1400', to: '1600' })
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('El bloqueo ya terminó: indique una fecha de hoy o posterior.')
  })

  it('says an inactive collaborator cannot be blocked (409 conflicto)', async () => {
    const { wrapper } = await open({ saveStatus: { status: 409, body: { type: 'conflicto' } } })
    await fill(wrapper, { collaborator: 'kevin-id', date: '2030-03-05', from: '1400', to: '1600' })
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Ese colaborador está inactivo')
  })
})

describe('TeamAbsencesView: the reservations in the way (CO-06)', () => {
  const ANA = conflict()
  const LUIS = conflict({
    id: 'res-luis',
    inicio: '2030-03-05T15:00:00-06:00',
    fin: '2030-03-05T15:45:00-06:00',
    cliente: 'Luis Mora',
    servicio: 'Corte + barba',
  })

  async function trySave(options: Parameters<typeof absencesApi>[0]) {
    const opened = await open(options)
    await fill(opened.wrapper, {
      collaborator: 'kevin-id',
      date: '2030-03-05',
      from: '1400',
      to: '1600',
    })
    await opened.wrapper.get('form').trigger('submit')
    await flushPromises()
    return opened
  }

  it('lists the reservations, offers to reschedule or cancel each one and holds «Guardar»', async () => {
    const { wrapper, blocks } = await trySave({ conflicts: [ANA, LUIS] })

    expect(wrapper.text()).toContain('Hay 2 reservas en ese rango')
    expect(wrapper.text()).toContain(
      'Resuélvalas antes de guardar el bloqueo. Puede reprogramarlas o cancelarlas.',
    )
    const rows = wrapper.findAll('ul[aria-label="Reservas por resolver"] > li')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('mar 5 mar, 14:00–14:30 · Ana Quirós')
    expect(rows[0]!.text()).toContain('Corte clásico')
    expect(rows[0]!.text()).toContain('Reprogramar')
    expect(rows[0]!.text()).toContain('Cancelar')
    expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Guardar se habilita cuando no queden reservas por resolver.')
    expect(blocks()).toHaveLength(0)
  })

  it('cancelling for the barbershop resolves a reservation and, with none left, enables «Guardar» and saves', async () => {
    const { wrapper, cancelled, blocks } = await trySave({ conflicts: [ANA] })

    await wrapper.get('button[aria-label^="Cancelar la reserva de Ana Quirós"]').trigger('click')
    const dialog = document.body.querySelector('[role="alertdialog"]')!
    expect(dialog.textContent).toContain('¿Cancelar la reserva de Ana Quirós?')
    expect(dialog.textContent).toContain('El cliente recupera todo lo que pagó y recibe un aviso.')
    Array.from(dialog.querySelectorAll('button'))
      .find((b) => b.textContent?.includes('Sí, cancelar reserva'))!
      .click()
    await flushPromises()

    expect(cancelled).toEqual(['res-ana'])
    expect(wrapper.text()).toContain('Cancelada por la barbería')
    expect(wrapper.text()).toContain('Ya no quedan reservas por resolver en ese rango.')
    expect(saveButton(wrapper).attributes('disabled')).toBeUndefined()

    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(blocks()).toHaveLength(1)
    expect(wrapper.text()).toContain('Bloqueo guardado.')
    expect(wrapper.find('ul[aria-label="Reservas por resolver"]').exists()).toBe(false)
  })

  it('with one of two resolved, «Guardar» stays held and the count goes down', async () => {
    const { wrapper } = await trySave({ conflicts: [ANA, LUIS] })

    await wrapper.get('button[aria-label^="Cancelar la reserva de Ana Quirós"]').trigger('click')
    Array.from(document.body.querySelectorAll('[role="alertdialog"] button'))
      .find((b) => b.textContent?.includes('Sí, cancelar'))!
      .dispatchEvent(new Event('click'))
    await flushPromises()

    expect(wrapper.text()).toContain('Hay 1 reserva en ese rango')
    expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
  })

  it('a reservation waiting for its payment can only be cancelled, and says why', async () => {
    const { wrapper } = await trySave({ conflicts: [conflict({ estado: 'temporal' })] })

    const row = wrapper.get('[data-testid="conflict-res-ana"]')
    expect(row.text()).toContain('Espera el pago')
    expect(row.text()).not.toContain('Reprogramar')
    expect(row.text()).toContain('Cancelar')
  })

  it('says how many more there are when the list is shorter than the total', async () => {
    const { wrapper } = await trySave({ conflicts: [ANA], total: 4 })

    expect(wrapper.text()).toContain('Hay 4 reservas en ese rango')
    expect(wrapper.text()).toContain('y 3 más.')
  })

  it('changing the range drops the reservations found for the old one', async () => {
    const { wrapper } = await trySave({ conflicts: [ANA] })
    expect(wrapper.find('ul[aria-label="Reservas por resolver"]').exists()).toBe(true)

    await fill(wrapper, { to: '1530' })

    expect(wrapper.find('ul[aria-label="Reservas por resolver"]').exists()).toBe(false)
    expect(saveButton(wrapper).attributes('disabled')).toBeUndefined()
  })

  it('«Reprogramar» reads the reservation and opens the form to move it', async () => {
    const detail = {
      id: 'res-ana',
      servicio: 'svc-clasico',
      barbero: 'kevin-id',
      barbero_nombre: 'Kevin Araya',
      inicio: '2030-03-05T14:00:00-06:00',
      plazo_cancelacion_horas: 4,
    }
    const { wrapper, fetchMock } = await trySave({
      conflicts: [ANA],
      reservationDetail: detail,
    })

    await wrapper.get('button[aria-label^="Reprogramar la reserva de Ana Quirós"]').trigger('click')
    await flushPromises()

    expect(
      fetchMock.mock.calls.some(([request]) =>
        (request as Request).url.endsWith('/reservas/res-ana'),
      ),
    ).toBe(true)
    expect(wrapper.get('#reschedule-title').text()).toBe('Reprogramar reserva')
    expect(wrapper.get('[data-testid="conflict-res-ana"]').text()).toContain('Kevin Araya')
  })
})
