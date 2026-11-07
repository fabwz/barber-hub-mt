import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { effectScope, nextTick, ref } from 'vue'

import { unifySlots, useAvailability } from '@/composables/useAvailability'
import { jsonResponse, mockFetch } from '../helpers/http'
import { DANIELA, MAURICIO, availabilityBody, group, slot } from '../helpers/booking'
import { SERVICES, SHOP } from '../helpers/shop'

afterEach(() => vi.unstubAllGlobals())

const DAY = '2030-03-05'
const T = (time: string, day = DAY) => `${day}T${time}:00-06:00`

function setup(initial: { barber?: string | null; date?: string | null } = {}) {
  const inputs = {
    service: ref<string | null>(SERVICES[0]!.id),
    barber: ref<string | null>(initial.barber ?? null),
    date: ref<string | null>(initial.date === undefined ? DAY : initial.date),
  }
  const scope = effectScope()
  const availability = scope.run(() =>
    useAvailability({
      shopId: () => SHOP.id,
      serviceId: () => inputs.service.value,
      barberId: () => inputs.barber.value,
      date: () => inputs.date.value,
    }),
  )!
  return { inputs, availability, scope }
}

const query = (request: Request) => new URL(request.url).searchParams
const oneSlot = (date = DAY) =>
  availabilityBody(date, [
    group(MAURICIO, 'Mauricio', slot(MAURICIO, T('09:00', date), T('09:30', date))),
  ])

describe('useAvailability', () => {
  it('asks with the service and the date, and no barber for «Sin preferencia»', async () => {
    const fetchMock = mockFetch(() => jsonResponse(200, availabilityBody(DAY, [])))
    const { availability } = setup()
    expect(availability.loading.value).toBe(true)
    await flushPromises()

    const params = query(fetchMock.mock.calls[0]![0] as Request)
    expect(params.get('servicio')).toBe(SERVICES[0]!.id)
    expect(params.get('fecha')).toBe(DAY)
    expect(params.has('barbero')).toBe(false)
    expect(availability.loading.value).toBe(false)
    expect(availability.loaded.value).toBe(true)
  })

  it('reloads when the barber, the date or the service change', async () => {
    const fetchMock = mockFetch(() => jsonResponse(200, availabilityBody(DAY, [])))
    const { inputs } = setup()
    await flushPromises()
    expect(fetchMock).toHaveBeenCalledTimes(1)

    inputs.barber.value = MAURICIO
    await flushPromises()
    inputs.date.value = '2030-03-06'
    await flushPromises()
    inputs.service.value = SERVICES[1]!.id
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledTimes(4)
    const last = query(fetchMock.mock.calls[3]![0] as Request)
    expect(last.get('barbero')).toBe(MAURICIO)
    expect(last.get('servicio')).toBe(SERVICES[1]!.id)
    expect(last.get('fecha')).toBe('2030-03-06')
  })

  it('does not ask without a service or a date', async () => {
    const fetchMock = mockFetch(() => jsonResponse(200, availabilityBody(DAY, [])))
    const { inputs } = setup({ date: null })
    inputs.service.value = null
    await flushPromises()

    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('ignores an old response that arrives after a newer one', async () => {
    const gates: Record<string, () => void> = {}
    mockFetch(async (request) => {
      const date = query(request).get('fecha')!
      if (date === DAY) await new Promise<void>((resolve) => (gates[date] = resolve))
      return jsonResponse(200, oneSlot(date))
    })
    const { inputs, availability } = setup()
    await nextTick()

    inputs.date.value = '2030-03-06' // newer request, answers at once
    await flushPromises()
    expect(availability.slots.value[0]?.start).toBe(T('09:00', '2030-03-06'))

    gates[DAY]!() // the old one finally answers
    await flushPromises()

    expect(availability.slots.value).toHaveLength(1)
    expect(availability.slots.value[0]?.start).toBe(T('09:00', '2030-03-06'))
    expect(availability.loading.value).toBe(false)
  })

  it('exposes the error and asks again with retry()', async () => {
    let fail = true
    const fetchMock = mockFetch(() => {
      if (fail) throw new TypeError('Failed to fetch')
      return jsonResponse(200, oneSlot())
    })
    const { availability } = setup()
    await flushPromises()

    expect(availability.error.value?.type).toBe('red')
    expect(availability.loaded.value).toBe(false)
    expect(availability.slots.value).toEqual([])

    fail = false
    availability.retry()
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(availability.error.value).toBeNull()
    expect(availability.slots.value).toHaveLength(1)
  })

  it('shows every barber once per hour, in order, with who stays', async () => {
    mockFetch(() =>
      jsonResponse(
        200,
        availabilityBody(DAY, [
          group(
            MAURICIO,
            'Mauricio',
            slot(MAURICIO, T('10:00'), T('10:30')),
            slot(MAURICIO, T('11:00'), T('11:30')),
          ),
          group(
            DANIELA,
            'Daniela',
            slot(DANIELA, T('09:30'), T('10:00')),
            slot(DANIELA, T('10:00'), T('10:30')),
          ),
        ]),
      ),
    )
    const { availability } = setup()
    await flushPromises()

    expect(availability.slots.value.map((s) => [s.start.slice(11, 16), s.barberName])).toEqual([
      ['09:30', 'Daniela'],
      ['10:00', 'Mauricio'],
      ['11:00', 'Mauricio'],
    ])
    // The raw list keeps both barbers at 10:00, to validate a chosen space.
    expect(availability.allSlots.value).toHaveLength(4)
    expect(availability.barbers.value.map((b) => b.name)).toEqual(['Mauricio', 'Daniela'])
  })

  it('marks as closed the empty day and the days before the next one with hours', async () => {
    mockFetch(() =>
      jsonResponse(
        200,
        availabilityBody(
          '2030-03-03',
          [],
          [
            {
              fecha: '2030-03-06',
              barberos: [
                group(
                  MAURICIO,
                  'Mauricio',
                  slot(MAURICIO, T('09:00', '2030-03-06'), T('09:30', '2030-03-06')),
                ),
              ],
            },
          ],
        ),
      ),
    )
    const { availability } = setup({ date: '2030-03-03' })
    await flushPromises()

    expect(availability.days.value).toEqual({
      '2030-03-03': 'closed',
      '2030-03-04': 'closed',
      '2030-03-05': 'closed',
      '2030-03-06': 'open',
    })
    expect(availability.nextDays.value.map((d) => d.date)).toEqual(['2030-03-06'])
    expect(availability.barbers.value.map((b) => b.name)).toEqual(['Mauricio'])
  })

  it('unifySlots keeps the first barber the API lists', () => {
    const first = { start: T('10:00'), end: T('10:30'), barberId: 'a', barberName: 'A' }
    const second = { ...first, barberId: 'b', barberName: 'B' }
    expect(unifySlots([first, second])).toEqual([first])
  })
})
