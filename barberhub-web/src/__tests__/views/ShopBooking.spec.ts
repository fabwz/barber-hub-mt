import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { todayInCostaRica, addDays } from '@/composables/format'
import { SESSION_STORAGE_KEY } from '@/stores/session'
import { BOOKING_STORAGE_KEY, SLOT_GONE_NOTICE, useBookingStore } from '@/stores/booking'
import ShopView from '@/views/ShopView.vue'
import { jsonResponse, mockFetch } from '../helpers/http'
import {
  DANIELA,
  MAURICIO,
  RESERVATION,
  availabilityBody,
  bookingHandler,
  conflict,
  group,
  slot,
} from '../helpers/booking'
import { SERVICES } from '../helpers/shop'
import { mountView } from '../helpers/mountView'

const TODAY = todayInCostaRica()
const at = (time: string, day = TODAY) => `${day}T${time}:00-06:00`

/** Mauricio 09:00 and 10:30; Daniela 09:00 and 11:00: three hours in «Sin preferencia». */
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

async function chooseService(wrapper: Awaited<ReturnType<typeof open>>['wrapper'], index = 0) {
  await wrapper.findAll('[role="radio"]')[index]!.trigger('click')
  await flushPromises()
}

const chip = (wrapper: Awaited<ReturnType<typeof open>>['wrapper'], time: string) =>
  wrapper.findAll('button[aria-pressed]').find((b) => b.text().startsWith(time))!

const button = (wrapper: Awaited<ReturnType<typeof open>>['wrapper'], text: string) =>
  wrapper.findAll('button').find((b) => b.text() === text)!

const requests = (fetchMock: ReturnType<typeof mockFetch>, method: string, suffix: string) =>
  fetchMock.mock.calls
    .map(([request]) => request as Request)
    .filter((r) => r.method === method && new URL(r.url).pathname.endsWith(suffix))

beforeEach(() => sessionStorage.clear())
afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('ShopView · booking', () => {
  it('chooses a service in the list and asks for today in Costa Rica', async () => {
    const { wrapper, fetchMock } = await open()
    expect(wrapper.text()).toContain('Elija un servicio')

    await chooseService(wrapper, 1)

    const radios = wrapper.findAll('[role="radio"]')
    expect(radios[1]!.attributes('aria-checked')).toBe('true')
    expect(radios[0]!.attributes('aria-checked')).toBe('false')
    expect(wrapper.findAll('h3').map((h) => h.text())).toEqual([
      'Servicio',
      'Barbero',
      'Fecha',
      'Hora',
    ])
    const params = new URL(requests(fetchMock, 'GET', '/disponibilidad')[0]!.url).searchParams
    expect(params.get('servicio')).toBe(SERVICES[1]!.id)
    expect(params.get('fecha')).toBe(TODAY)
    expect(params.has('barbero')).toBe(false)
  })

  it('«Sin preferencia» shows each hour once and with whom it stays', async () => {
    const { wrapper } = await open()
    await chooseService(wrapper)

    const chips = wrapper.findAll('button[aria-pressed]').filter((b) => /^\d\d:\d\d/.test(b.text()))
    expect(chips.map((c) => c.text().replace(/\s+/g, ' '))).toEqual([
      '09:00 con Mauricio',
      '10:30 con Mauricio',
      '11:00 con Daniela',
    ])
    expect(
      wrapper
        .get('select')
        .findAll('option')
        .map((o) => o.text()),
    ).toEqual(['Sin preferencia', 'Mauricio', 'Daniela'])
  })

  it('choosing a barber asks only for that barber and drops the chosen hour', async () => {
    const { wrapper, fetchMock } = await open()
    await chooseService(wrapper)
    await chip(wrapper, '09:00').trigger('click')
    expect(chip(wrapper, '09:00').attributes('aria-pressed')).toBe('true')

    await wrapper.get('select').setValue(DANIELA)
    await flushPromises()

    const last = requests(fetchMock, 'GET', '/disponibilidad').at(-1)!
    expect(new URL(last.url).searchParams.get('barbero')).toBe(DANIELA)
    expect(wrapper.text()).not.toContain('con Daniela')
    expect(chip(wrapper, '09:00').attributes('aria-pressed')).toBe('false')
    expect(button(wrapper, 'Reservar').attributes('disabled')).toBeDefined()
  })

  it('shows the summary with the barber assigned to the chosen hour', async () => {
    const { wrapper } = await open()
    await chooseService(wrapper)

    await chip(wrapper, '11:00').trigger('click')

    const bar = wrapper.get('section > div:last-child')
    expect(bar.text()).toContain('Corte clásico')
    expect(bar.text()).toContain('11:00')
    expect(bar.text()).toContain('con Daniela')
    expect(bar.text()).toContain('₡7.000')
  })

  it('shows skeletons while the hours load', async () => {
    let release: () => void = () => {}
    const gate = new Promise<void>((resolve) => (release = resolve))
    const { wrapper } = await open({
      availability: async (request: Request) => {
        await gate
        return twoBarbers(request)
      },
    })
    await chooseService(wrapper)

    expect(wrapper.find('[aria-busy="true"] .animate-pulse').exists()).toBe(true)

    release()
    await flushPromises()
    expect(wrapper.find('[aria-busy="true"] .animate-pulse').exists()).toBe(false)
    expect(chip(wrapper, '09:00').exists()).toBe(true)
  })

  it('shows the error with «Reintentar», keeps the choice and asks again', async () => {
    let fail = true
    const { wrapper, fetchMock } = await open({
      availability: (request: Request) => {
        if (fail) throw new TypeError('Failed to fetch')
        return twoBarbers(request)
      },
    })
    await chooseService(wrapper)

    expect(wrapper.text()).toContain('No pudimos cargar los horarios.')
    expect(wrapper.findAll('[role="radio"]')[0]!.attributes('aria-checked')).toBe('true')

    fail = false
    const calls = requests(fetchMock, 'GET', '/disponibilidad').length
    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()

    expect(requests(fetchMock, 'GET', '/disponibilidad').length).toBe(calls + 1)
    expect(chip(wrapper, '09:00').exists()).toBe(true)
  })

  it('offers the next days with hours when the day is empty, and changes the date', async () => {
    const next = addDays(TODAY, 2)
    const { wrapper, fetchMock } = await open({
      availability: (request: Request) => {
        const date = new URL(request.url).searchParams.get('fecha')!
        return date === TODAY
          ? jsonResponse(
              200,
              availabilityBody(
                date,
                [],
                [
                  {
                    fecha: next,
                    barberos: [
                      group(
                        MAURICIO,
                        'Mauricio',
                        slot(MAURICIO, at('09:00', next), at('09:30', next)),
                      ),
                    ],
                  },
                ],
              ),
            )
          : twoBarbers(request)
      },
    })
    await chooseService(wrapper)

    expect(wrapper.text()).toContain('No quedan horas el')
    const days = wrapper.findAll('button[aria-label]').filter((b) => b.text().match(/\d|–/))
    // Today is empty; the day between today and the next with hours is empty too.
    expect(days[0]!.text()).toContain('–')
    expect(days[1]!.text()).toContain('–')
    expect(days[1]!.attributes('aria-label')).toContain('sin horas')
    expect(days[2]!.text()).not.toContain('–')

    await wrapper
      .findAll('button')
      .find((b) => b.text().startsWith('Ver '))!
      .trigger('click')
    await flushPromises()

    const last = requests(fetchMock, 'GET', '/disponibilidad').at(-1)!
    expect(new URL(last.url).searchParams.get('fecha')).toBe(next)
    expect(chip(wrapper, '09:00').exists()).toBe(true)
  })

  it('goes to /ingresar with the redirect and keeps the selection when there is no session', async () => {
    const { wrapper, router, fetchMock } = await open()
    await chooseService(wrapper)
    await chip(wrapper, '09:00').trigger('click')

    await button(wrapper, 'Reservar').trigger('click')
    // The login view loads lazily.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))

    expect(router.currentRoute.value.query.redirect).toBe('/b/pura-navaja')
    expect(requests(fetchMock, 'POST', '/reservas')).toHaveLength(0)
    expect(JSON.parse(sessionStorage.getItem(BOOKING_STORAGE_KEY)!)).toMatchObject({
      serviceId: SERVICES[0]!.id,
      slot: { start: at('09:00'), barberId: MAURICIO },
    })
  })

  it('restores the selection after the login and revalidates it against the availability', async () => {
    const first = await open()
    await chooseService(first.wrapper)
    await chip(first.wrapper, '09:00').trigger('click')
    first.wrapper.unmount()

    signIn()
    const { wrapper } = await open()

    expect(wrapper.findAll('[role="radio"]')[0]!.attributes('aria-checked')).toBe('true')
    expect(chip(wrapper, '09:00').attributes('aria-pressed')).toBe('true')
    expect(wrapper.find('[role="note"]').exists()).toBe(false)
  })

  it('warns and drops a restored space that no longer exists', async () => {
    const first = await open()
    await chooseService(first.wrapper)
    await chip(first.wrapper, '09:00').trigger('click')
    first.wrapper.unmount()

    const { wrapper } = await open({
      availability: (request: Request) =>
        jsonResponse(
          200,
          availabilityBody(new URL(request.url).searchParams.get('fecha')!, [
            group(MAURICIO, 'Mauricio', slot(MAURICIO, at('15:00'), at('15:30'))),
          ]),
        ),
    })

    expect(wrapper.get('[role="note"]').text()).toContain('ya no está disponible')
    expect(wrapper.findAll('[role="radio"]')[0]!.attributes('aria-checked')).toBe('true')
    expect(chip(wrapper, '15:00').attributes('aria-pressed')).toBe('false')
    expect(button(wrapper, 'Reservar').attributes('disabled')).toBeDefined()
  })

  it('creates the temporary reservation and shows the summary, the countdown and the payment button', async () => {
    signIn()
    const { wrapper, fetchMock } = await open({
      reserve: () =>
        jsonResponse(201, {
          data: { ...RESERVATION, inicio: at('09:00'), fin: at('09:30'), expira_en: null },
        }),
    })
    await chooseService(wrapper)
    await chip(wrapper, '09:00').trigger('click')

    await button(wrapper, 'Reservar').trigger('click')
    await flushPromises()

    const body = await requests(fetchMock, 'POST', '/reservas')[0]!.json()
    expect(body).toEqual({ servicio: SERVICES[0]!.id, barbero: MAURICIO, inicio: at('09:00') })
    expect(wrapper.text()).toContain('Su reserva')
    expect(wrapper.text()).not.toContain('Pendiente de pago')
    expect(wrapper.get('[role="timer"]').text()).toContain('10:00')
    expect(button(wrapper, 'Continuar al pago').attributes('disabled')).toBeUndefined()
    expect(wrapper.text()).not.toContain('el pago llega con el siguiente issue')
    // The selection is cleared once the reservation exists.
    expect(
      wrapper.findAll('[role="radio"]').every((r) => r.attributes('aria-checked') === 'false'),
    ).toBe(true)
    expect(sessionStorage.getItem(BOOKING_STORAGE_KEY)).toBeNull()
  })

  it('offers the alternatives of a 409 and replaces only the space', async () => {
    signIn()
    const { wrapper, fetchMock } = await open({
      reserve: () =>
        conflict([
          { barbero: MAURICIO, inicio: at('09:15'), fin: at('09:45') },
          { barbero: MAURICIO, inicio: at('09:30'), fin: at('10:00') },
        ]),
    })
    await chooseService(wrapper)
    await chip(wrapper, '09:00').trigger('click')

    await button(wrapper, 'Reservar').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Ese espacio ya no está disponible.')
    expect(wrapper.text()).toContain('Otros espacios cercanos')
    expect(wrapper.findAll('[role="radio"]')[0]!.attributes('aria-checked')).toBe('true')

    const alt = wrapper
      .findAll('[aria-label="Otros espacios cercanos"] button')
      .find((b) => b.text() === '09:15')!
    await alt.trigger('click')
    await flushPromises()

    expect(wrapper.text()).not.toContain('Otros espacios cercanos')
    expect(wrapper.findAll('[role="radio"]')[0]!.attributes('aria-checked')).toBe('true')
    expect(wrapper.get('section > div:last-child').text()).toContain('09:15')

    await button(wrapper, 'Reservar').trigger('click')
    await flushPromises()
    const second = await requests(fetchMock, 'POST', '/reservas')[1]!.json()
    expect(second).toEqual({ servicio: SERVICES[0]!.id, barbero: MAURICIO, inicio: at('09:15') })
  })

  it('shows any other error with its way out and keeps what was chosen', async () => {
    signIn()
    const { wrapper } = await open({
      reserve: () => {
        throw new TypeError('Failed to fetch')
      },
    })
    await chooseService(wrapper)
    await chip(wrapper, '09:00').trigger('click')

    await button(wrapper, 'Reservar').trigger('click')
    await flushPromises()

    const alert = wrapper
      .findAll('[role="alert"]')
      .find((a) => a.text().includes('crear la reserva'))!
    expect(alert.text()).toContain('No se pudo comunicar')
    expect(alert.find('button').text()).toBe('Reintentar')
    expect(chip(wrapper, '09:00').attributes('aria-pressed')).toBe('true')
    expect(wrapper.findAll('[role="radio"]')[0]!.attributes('aria-checked')).toBe('true')
  })

  it('goes to the login if the API answers 401 when reserving', async () => {
    signIn()
    const { wrapper, router } = await open({
      reserve: () => jsonResponse(401, { type: 'no-autenticado', title: 'No autenticado' }),
    })
    await chooseService(wrapper)
    await chip(wrapper, '09:00').trigger('click')

    await button(wrapper, 'Reservar').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/b/pura-navaja')
  })

  it('offers only «Continuar al pago» once the temporary reservation is created', async () => {
    signIn()
    const { wrapper, fetchMock } = await open({
      reserve: () =>
        jsonResponse(201, {
          data: { ...RESERVATION, inicio: at('09:00'), fin: at('09:30'), expira_en: null },
        }),
    })
    await chooseService(wrapper)
    await chip(wrapper, '09:00').trigger('click')
    await button(wrapper, 'Reservar').trigger('click')
    await flushPromises()

    expect(wrapper.findAll('[role="radio"]')).toHaveLength(0)
    const labels = wrapper.findAll('button').map((b) => b.text())
    expect(labels).toContain('Continuar al pago')
    expect(labels).not.toContain('Editar reserva')
    expect(labels).not.toContain('Cambiar reserva')
    expect(requests(fetchMock, 'POST', '/cancelacion')).toHaveLength(0)
  })

  it('tells a payment is in progress and cancels nothing', async () => {
    signIn()
    const { wrapper, fetchMock } = await open({
      reserve: () =>
        jsonResponse(201, {
          data: { ...RESERVATION, inicio: at('09:00'), fin: at('09:30'), expira_en: null },
        }),
    })
    await chooseService(wrapper)
    await chip(wrapper, '09:00').trigger('click')
    sessionStorage.setItem(
      'barberhub.payment',
      JSON.stringify({
        enlace: 'pura-navaja',
        reserva: RESERVATION.id,
        pago: 'p1',
        metodo: 'sinpe_movil',
      }),
    )
    await button(wrapper, 'Reservar').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Tiene un pago en proceso.')
    expect(wrapper.findAll('button').map((b) => b.text())).not.toContain('Cambiar reserva')
    expect(requests(fetchMock, 'POST', '/cancelacion')).toHaveLength(0)
  })

  it('shows the temporary reservation as confirmed when the shop does not charge', async () => {
    signIn()
    const { wrapper } = await open({
      reserve: () =>
        jsonResponse(201, {
          data: { ...RESERVATION, estado: 'confirmada', inicio: at('09:00'), fin: at('09:30') },
        }),
    })
    await chooseService(wrapper)
    await chip(wrapper, '09:00').trigger('click')

    await button(wrapper, 'Reservar').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Confirmada')
    expect(wrapper.find('[role="timer"]').exists()).toBe(false)
  })
})

describe('ShopView · back from «Cambiar reserva»', () => {
  /** What PaymentView hands over once the temporary reservation is cancelled. */
  function comeBack(time: string, barberId = MAURICIO) {
    const booking = useBookingStore()
    booking.restore({
      serviceId: SERVICES[0]!.id,
      barberId,
      date: TODAY,
      slot: { start: at(time), barberId },
    })
  }

  it('keeps the service, the barber and the day, and suggests the hour while it is still free', async () => {
    const { wrapper } = await open()
    comeBack('09:00')
    await flushPromises()

    expect(wrapper.findAll('[role="radio"]')[0]!.attributes('aria-checked')).toBe('true')
    expect(wrapper.get('select').element.value).toBe(MAURICIO)
    expect(chip(wrapper, '09:00').attributes('aria-pressed')).toBe('true')
    expect(wrapper.text()).not.toContain(SLOT_GONE_NOTICE)
    expect(button(wrapper, 'Reservar').attributes('disabled')).toBeUndefined()
  })

  it('leaves the hour for the person to choose when the availability no longer lists it', async () => {
    const { wrapper } = await open()
    comeBack('11:00')
    await flushPromises()

    expect(wrapper.findAll('[role="radio"]')[0]!.attributes('aria-checked')).toBe('true')
    const pressedHours = wrapper
      .findAll('button[aria-pressed="true"]')
      .filter((b) => /^\d\d:\d\d/.test(b.text()))
    expect(pressedHours).toHaveLength(0)
    expect(wrapper.text()).toContain(SLOT_GONE_NOTICE)
    expect(button(wrapper, 'Reservar').attributes('disabled')).toBeDefined()
  })

  it('puts the focus on the title of the screen and asks for it only once', async () => {
    const { wrapper } = await open()
    comeBack('09:00')
    await flushPromises()

    expect(document.activeElement?.id).toBe('booking-title')
    expect(useBookingStore().returned).toBe(false)
    expect(wrapper.get('#booking-title').text()).toBe('Reservar cita')
  })

  it('creates another reservation with its own countdown when the person books again', async () => {
    signIn()
    const { wrapper, fetchMock } = await open({
      reserve: () =>
        jsonResponse(201, {
          data: { ...RESERVATION, id: 'r2', inicio: at('09:00'), fin: at('09:30') },
        }),
    })
    comeBack('09:00')
    await flushPromises()

    await button(wrapper, 'Reservar').trigger('click')
    await flushPromises()

    expect(requests(fetchMock, 'POST', '/reservas')).toHaveLength(1)
    expect(useBookingStore().reservation?.reservation.id).toBe('r2')
    expect(wrapper.get('[role="timer"]').text()).toContain('10:00')
    expect(wrapper.text()).toContain('Su reserva')
  })
})
