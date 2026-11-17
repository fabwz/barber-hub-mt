import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { addDays, todayInCostaRica } from '@/composables/format'
import { useAiBookingStore } from '@/stores/aiBooking'
import { useBookingStore } from '@/stores/booking'
import { SESSION_STORAGE_KEY } from '@/stores/session'
import ShopView from '@/views/ShopView.vue'
import { jsonResponse, mockFetch, problem } from '../helpers/http'
import {
  DANIELA,
  MAURICIO,
  availabilityBody,
  bookingHandler,
  group,
  proposalBody,
  slot,
} from '../helpers/booking'
import { SERVICES } from '../helpers/shop'
import { mountView } from '../helpers/mountView'

const TODAY = todayInCostaRica()
const DAY = addDays(TODAY, 1)
const at = (time: string, day = DAY) => `${day}T${time}:00-06:00`

/** Mauricio 09:00 and 10:30; Daniela 09:00 and 11:00. */
function twoBarbers(request: Request) {
  const params = new URL(request.url).searchParams
  const date = params.get('fecha')!
  const barber = params.get('barbero')
  const mauricio = group(
    MAURICIO,
    'Mauricio',
    slot(MAURICIO, at('09:00', date), at('09:30', date)),
    slot(MAURICIO, at('10:30', date), at('11:00', date)),
  )
  const daniela = group(
    DANIELA,
    'Daniela',
    slot(DANIELA, at('09:00', date), at('09:30', date)),
    slot(DANIELA, at('11:00', date), at('11:30', date)),
  )
  const groups =
    barber === MAURICIO ? [mauricio] : barber === DANIELA ? [daniela] : [mauricio, daniela]
  return jsonResponse(200, availabilityBody(date, groups))
}

/** The morning of tomorrow with Mauricio: a valid proposal with two spaces. */
const VALID = proposalBody({
  servicio: 'corte',
  barbero: 'mauricio',
  fecha: DAY,
  franja: { nombre: 'manana', desde: '06:00', hasta: '11:59' },
  validada: true,
  estado: 'valida',
  servicio_id: SERVICES[0]!.id,
  barbero_id: MAURICIO,
  precio: 7000,
  espacios: [
    { barbero: MAURICIO, inicio: at('09:00'), fin: at('09:30') },
    { barbero: MAURICIO, inicio: at('10:30'), fin: at('11:00') },
  ],
})

function signIn() {
  sessionStorage.setItem(
    SESSION_STORAGE_KEY,
    JSON.stringify({ token: 'token', person: { email: 'ana@correo.cr' } }),
  )
}

async function open(handlers = {}) {
  const fetchMock = mockFetch(bookingHandler({ availability: twoBarbers, ...handlers }))
  const mounted = await mountView(ShopView, '/b/pura-navaja')
  await flushPromises()
  return { ...mounted, fetchMock }
}

type Wrapper = Awaited<ReturnType<typeof open>>['wrapper']

const block = (wrapper: Wrapper) => wrapper.get('section[aria-label="Describa su cita"]')
const generate = (wrapper: Wrapper) =>
  block(wrapper)
    .findAll('button')
    .find((b) => b.text() === 'Generar propuesta' || b.text() === 'Generando propuesta…')!

async function ask(wrapper: Wrapper, phrase = 'un corte mañana en la mañana con Mauricio') {
  await block(wrapper).get('button[aria-expanded]').trigger('click')
  await block(wrapper).get('textarea').setValue(phrase)
  await generate(wrapper).trigger('click')
  await flushPromises()
}

const aiChips = (wrapper: Wrapper) =>
  block(wrapper).get('[role="group"][aria-label="Espacios disponibles"]').findAll('button')

const hourChip = (wrapper: Wrapper, time: string) =>
  wrapper
    .get('[role="group"][aria-label="Hora"]')
    .findAll('button')
    .find((b) => b.text().startsWith(time))!

const requests = (fetchMock: ReturnType<typeof mockFetch>, method: string, suffix: string) =>
  fetchMock.mock.calls
    .map(([request]) => request as Request)
    .filter((r) => r.method === method && new URL(r.url).pathname.endsWith(suffix))

beforeEach(() => {
  sessionStorage.clear()
  signIn()
})
afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('ShopView · reserve with the assistant', () => {
  it('starts folded on a phone, opens by the header and keeps the manual choices', async () => {
    const { wrapper } = await open()
    const header = block(wrapper).get('button[aria-expanded]')
    expect(header.attributes('aria-expanded')).toBe('false')
    expect(wrapper.text()).toContain('O elija a mano')
    expect(wrapper.findAll('[role="radio"]').length).toBe(SERVICES.length)

    await header.trigger('click')
    expect(header.attributes('aria-expanded')).toBe('true')
    expect(block(wrapper).get('textarea').attributes('maxlength')).toBe('300')
    expect(generate(wrapper).attributes('disabled')).toBeDefined()
  })

  it('sends only the phrase and shows the proposal with its real spaces', async () => {
    const proposal = vi.fn(() => jsonResponse(200, VALID))
    const { wrapper, fetchMock } = await open({ proposal })
    await ask(wrapper)

    const [sent] = requests(fetchMock, 'POST', '/reservas/propuesta')
    expect(sent).toBeDefined()
    expect(await sent!.clone().json()).toEqual({
      frase: 'un corte mañana en la mañana con Mauricio',
    })

    expect(block(wrapper).text()).toContain('Solicitud')
    expect(block(wrapper).text()).toContain('Corte clásico con Mauricio')
    expect(aiChips(wrapper).map((c) => c.text())).toEqual(['09:00', '10:30'])
    expect(aiChips(wrapper).every((c) => c.attributes('aria-pressed') === 'false')).toBe(true)
  })

  it('fills service, barber and day below, and books nothing until the person continues', async () => {
    const { wrapper, fetchMock } = await open({ proposal: () => jsonResponse(200, VALID) })
    await ask(wrapper)

    const booking = useBookingStore()
    expect(booking.serviceId).toBe(SERVICES[0]!.id)
    expect(booking.barberId).toBe(MAURICIO)
    expect(booking.date).toBe(DAY)
    expect(booking.slot).toBeNull()
    expect(wrapper.findAll('[role="radio"]')[0]!.attributes('aria-checked')).toBe('true')
    expect(wrapper.get('select').element.value).toBe(MAURICIO)

    await aiChips(wrapper)[1]!.trigger('click')
    await flushPromises()

    expect(booking.slot).toEqual({ start: at('10:30'), barberId: MAURICIO })
    expect(aiChips(wrapper)[1]!.attributes('aria-pressed')).toBe('true')
    expect(hourChip(wrapper, '10:30').attributes('aria-pressed')).toBe('true')
    expect(requests(fetchMock, 'POST', '/reservas')).toHaveLength(0)

    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Reservar')!
      .trigger('click')
    await flushPromises()
    const [reserve] = requests(fetchMock, 'POST', '/reservas')
    expect(await reserve!.clone().json()).toEqual({
      servicio: SERVICES[0]!.id,
      barbero: MAURICIO,
      inicio: at('10:30'),
    })
  })

  it('shows what is missing for the person to choose, and invents nothing', async () => {
    const { wrapper } = await open({
      proposal: () =>
        jsonResponse(
          200,
          proposalBody({
            fecha: DAY,
            campos_faltantes: ['servicio', 'hora'],
            estado: 'incompleta',
            campo: 'servicio',
          }),
        ),
    })
    await ask(wrapper, 'quiero algo mañana')

    expect(block(wrapper).text()).toContain('Faltan datos')
    expect(block(wrapper).text()).toContain('No pudimos saber el servicio ni la hora.')
    expect(block(wrapper).text()).toContain('Una cita, el ')
    expect(useBookingStore().serviceId).toBeNull()
    expect(wrapper.text()).toContain('Falta elegir')

    // Choosing the service takes the mark away.
    await wrapper.findAll('[role="radio"]')[0]!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).not.toContain('Falta elegir')
  })

  it('tells a service the shop does not have, marks it and keeps the rest', async () => {
    const { wrapper } = await open({
      proposal: () =>
        jsonResponse(
          200,
          proposalBody({
            servicio: 'tinte',
            fecha: DAY,
            estado: 'no_reconocida',
            campo: 'servicio',
          }),
        ),
    })
    await ask(wrapper, 'un tinte mañana')

    expect(block(wrapper).text()).toContain('No disponible en esta barbería')
    expect(block(wrapper).text()).toContain('Esta barbería no ofrece «tinte».')
    expect(useBookingStore().serviceId).toBeNull()
    expect(useBookingStore().date).toBe(DAY)
    expect(wrapper.text()).toContain('Falta elegir')
  })

  it('tells a barber the shop does not have, keeps the service and marks the barber', async () => {
    const { wrapper } = await open({
      proposal: () =>
        jsonResponse(
          200,
          proposalBody({
            servicio: 'corte',
            barbero: 'esteban',
            fecha: DAY,
            estado: 'no_reconocida',
            campo: 'barbero',
            servicio_id: SERVICES[0]!.id,
          }),
        ),
    })
    await ask(wrapper, 'un corte con Esteban mañana')

    expect(block(wrapper).text()).toContain('un barbero llamado «esteban»')
    expect(useBookingStore().serviceId).toBe(SERVICES[0]!.id)
    expect(useBookingStore().barberId).toBeNull()
    expect(wrapper.text()).toContain('Falta elegir')
  })

  it('offers the candidates of an ambiguous phrase and lets each space say with whom', async () => {
    const { wrapper } = await open({
      proposal: () =>
        jsonResponse(
          200,
          proposalBody({
            servicio: 'corte',
            fecha: DAY,
            hora: '09:00',
            estado: 'ambigua',
            campo: 'barbero',
            servicio_id: SERVICES[0]!.id,
            candidatos: [
              { id: MAURICIO, nombre: 'Mauricio' },
              { id: DANIELA, nombre: 'Daniela' },
            ],
            espacios: [
              { barbero: MAURICIO, inicio: at('09:00'), fin: at('09:30') },
              { barbero: DANIELA, inicio: at('09:00'), fin: at('09:30') },
            ],
          }),
        ),
    })
    await ask(wrapper, 'un corte mañana a las 9')

    expect(block(wrapper).text()).toContain('«Mauricio», «Daniela»')
    expect(aiChips(wrapper).map((c) => c.attributes('aria-label'))).toEqual([
      '09:00 con Mauricio',
      '09:00 con Daniela',
    ])
    expect(useBookingStore().barberId).toBeNull()
  })

  it('says there are no spaces that day when the API has none to offer', async () => {
    const { wrapper } = await open({
      proposal: () =>
        jsonResponse(
          200,
          proposalBody({
            servicio: 'corte',
            barbero: 'mauricio',
            fecha: DAY,
            hora: '10:00',
            estado: 'no_disponible',
            campo: 'hora',
            servicio_id: SERVICES[0]!.id,
            barbero_id: MAURICIO,
          }),
        ),
    })
    await ask(wrapper)

    expect(block(wrapper).text()).toContain('No quedan espacios el ')
    expect(block(wrapper).text()).toContain('Pruebe otro día')
    expect(block(wrapper).find('[role="group"]').exists()).toBe(false)
  })

  it('offers the nearest spaces when the one asked for is taken', async () => {
    const { wrapper } = await open({
      proposal: () =>
        jsonResponse(
          200,
          proposalBody({
            servicio: 'corte',
            barbero: 'mauricio',
            fecha: DAY,
            hora: '10:00',
            estado: 'no_disponible',
            campo: 'hora',
            servicio_id: SERVICES[0]!.id,
            barbero_id: MAURICIO,
            alternativas: [{ barbero: MAURICIO, inicio: at('10:30'), fin: at('11:00') }],
          }),
        ),
    })
    await ask(wrapper)

    expect(block(wrapper).text()).toContain('Ese espacio no está disponible.')
    expect(aiChips(wrapper).map((c) => c.text())).toEqual(['10:30'])
  })

  it('offers to book by hand when the assistant fails, and the manual form keeps working', async () => {
    const { wrapper, fetchMock } = await open({
      proposal: () =>
        problem(503, {
          type: 'servicio-no-disponible',
          title: 'Servicio no disponible',
          detail: 'El asistente de reservas no está disponible en este momento.',
        }),
    })
    await ask(wrapper)

    const alert = block(wrapper).get('[role="alert"]')
    expect(alert.text()).toContain('No pudimos procesar su solicitud.')
    expect(alert.text()).toContain('Puede continuar a mano')
    expect(alert.text()).toContain('Su frase se conserva')
    expect(block(wrapper).get('textarea').element.value).toBe(
      'un corte mañana en la mañana con Mauricio',
    )

    // The manual choices, the availability and the reservation do not depend on the assistant.
    await wrapper.findAll('[role="radio"]')[0]!.trigger('click')
    await flushPromises()
    await hourChip(wrapper, '09:00').trigger('click')
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Reservar')!
      .trigger('click')
    await flushPromises()
    expect(requests(fetchMock, 'POST', '/reservas')).toHaveLength(1)
  })

  it('fails the same way with no connection, and retrying asks again', async () => {
    let calls = 0
    const { wrapper, fetchMock } = await open({
      proposal: () => {
        calls += 1
        if (calls === 1) throw new TypeError('Failed to fetch')
        return jsonResponse(200, VALID)
      },
    })
    await ask(wrapper)
    expect(block(wrapper).find('[role="alert"]').exists()).toBe(true)

    await block(wrapper)
      .findAll('button')
      .find((b) => b.text() === 'Reintentar')!
      .trigger('click')
    await flushPromises()

    expect(requests(fetchMock, 'POST', '/reservas/propuesta')).toHaveLength(2)
    expect(block(wrapper).find('[role="alert"]').exists()).toBe(false)
    expect(aiChips(wrapper)).toHaveLength(2)
  })

  it('waits what Retry-After says after too many requests', async () => {
    const { wrapper } = await open({
      proposal: () =>
        problem(
          429,
          { type: 'demasiadas-solicitudes', title: 'Demasiadas solicitudes' },
          {
            'Retry-After': '30',
          },
        ),
    })
    await ask(wrapper)

    expect(block(wrapper).text()).toContain('Hizo muchas solicitudes seguidas')
    expect(block(wrapper).text()).toContain('Podrá intentar de nuevo en 30 s.')
    expect(
      block(wrapper)
        .findAll('button')
        .find((b) => b.text() === 'Reintentar')!
        .attributes('disabled'),
    ).toBeDefined()
  })

  it('shows the skeleton and a disabled button while it interprets', async () => {
    let answer: (response: Response) => void = () => {}
    const { wrapper } = await open({
      proposal: () => new Promise<Response>((resolve) => (answer = resolve)),
    })
    await ask(wrapper)

    expect(generate(wrapper).text()).toBe('Generando propuesta…')
    expect(generate(wrapper).attributes('disabled')).toBeDefined()
    expect(block(wrapper).get('textarea').attributes('disabled')).toBeDefined()
    expect(block(wrapper).text()).toContain('Procesando su solicitud…')
    expect(block(wrapper).findAll('.animate-pulse').length).toBeGreaterThan(0)
    expect(block(wrapper).get('[role="status"]').text()).toBe('Generando propuesta.')

    answer(jsonResponse(200, VALID))
    await flushPromises()
    expect(generate(wrapper).text()).toBe('Generar propuesta')
    expect(aiChips(wrapper)).toHaveLength(2)
  })

  it('sends a person without a session to the login and keeps the phrase', async () => {
    sessionStorage.clear()
    const { wrapper, router, fetchMock } = await open()
    await ask(wrapper, 'un corte mañana')

    // The login view loads lazily.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
    expect(requests(fetchMock, 'POST', '/reservas/propuesta')).toHaveLength(0)
    expect(useAiBookingStore().phrase).toBe('un corte mañana')
  })
})
