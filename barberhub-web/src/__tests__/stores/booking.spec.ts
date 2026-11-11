import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { BOOKING_STORAGE_KEY, SLOT_GONE_NOTICE, useBookingStore } from '@/stores/booking'
import { jsonResponse, mockFetch } from '../helpers/http'
import { MAURICIO, RESERVATION, conflict } from '../helpers/booking'
import { SERVICES, SHOP } from '../helpers/shop'

const START = '2030-03-05T10:00:00-06:00'
const LABELS = { serviceName: 'Corte clásico', barberName: 'Mauricio' }

beforeEach(() => {
  sessionStorage.clear()
  setActivePinia(createPinia())
})
afterEach(() => vi.unstubAllGlobals())

function stored() {
  const raw = sessionStorage.getItem(BOOKING_STORAGE_KEY)
  return raw === null ? null : JSON.parse(raw)
}

describe('release', () => {
  const RESERVED = { ...RESERVATION, id: 'r1', estado: 'temporal' }

  it('cancels the temporary reservation to free its space', async () => {
    const fetchMock = mockFetch(() =>
      jsonResponse(200, { data: { estado: 'cancelada_por_cliente' } }),
    )
    const booking = useBookingStore()

    await expect(booking.release(SHOP.id, RESERVED.id)).resolves.toBe(true)

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('POST')
    expect(new URL(request.url).pathname).toBe(
      `/api/v1/barberias/${SHOP.id}/reservas/${RESERVED.id}/cancelacion`,
    )
    expect(booking.releasing).toBe(false)
    expect(booking.releaseError).toBeNull()
  })

  it('counts a 409 (already expired) as released, without an error', async () => {
    mockFetch(() => jsonResponse(409, { type: 'conflicto', title: 'Conflicto' }))
    const booking = useBookingStore()

    await expect(booking.release(SHOP.id, RESERVED.id)).resolves.toBe(true)
    expect(booking.releaseError).toBeNull()
    expect(booking.releasing).toBe(false)
  })

  it('keeps the error and answers false when the API fails or there is no connection', async () => {
    mockFetch(() => jsonResponse(500, { type: 'error-interno', title: 'Error', detail: 'Falló.' }))
    const booking = useBookingStore()

    await expect(booking.release(SHOP.id, RESERVED.id)).resolves.toBe(false)
    expect(booking.releaseError?.status).toBe(500)
    expect(booking.releasing).toBe(false)

    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')))
    await expect(booking.release(SHOP.id, RESERVED.id)).resolves.toBe(false)
    expect(booking.releaseError).not.toBeNull()
  })

  it('does not cancel twice at once', async () => {
    let release: (response: Response) => void = () => {}
    const fetchMock = mockFetch(() => new Promise<Response>((resolve) => (release = resolve)))
    const booking = useBookingStore()

    const first = booking.release(SHOP.id, RESERVED.id)
    const second = booking.release(SHOP.id, RESERVED.id)
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))
    release(jsonResponse(200, { data: {} }))
    await Promise.all([first, second])

    expect(fetchMock).toHaveBeenCalledTimes(1)
  })
})

describe('restore', () => {
  const BACK = { serviceId: SERVICES[0]!.id, barberId: MAURICIO, date: '2030-03-05' }

  it('puts the selection back, forgets the reservation and mirrors it in sessionStorage', async () => {
    mockFetch(() => jsonResponse(201, { data: RESERVATION }))
    const booking = chosen()
    await booking.reserve(LABELS)
    expect(booking.reservation).not.toBeNull()

    booking.restore({ ...BACK, slot: { start: START, barberId: MAURICIO } })

    expect(booking.reservation).toBeNull()
    expect(booking.serviceId).toBe(BACK.serviceId)
    expect(booking.barberId).toBe(MAURICIO)
    expect(booking.date).toBe('2030-03-05')
    expect(booking.slot).toEqual({ start: START, barberId: MAURICIO })
    expect(booking.returned).toBe(true)
    expect(stored()).toEqual({
      shopId: SHOP.id,
      ...BACK,
      slot: { start: START, barberId: MAURICIO },
    })
  })

  it('keeps the suggested hour only if the availability lists it again', () => {
    const kept = restored()
    kept.restore({ ...BACK, slot: { start: START, barberId: MAURICIO } })
    kept.revalidate([{ start: START, barberId: MAURICIO }])
    expect(kept.slot).toEqual({ start: START, barberId: MAURICIO })
    expect(kept.notice).toBeNull()

    const taken = restored()
    taken.restore({ ...BACK, slot: { start: START, barberId: MAURICIO } })
    taken.revalidate([{ start: '2030-03-05T11:00:00-06:00', barberId: MAURICIO }])
    expect(taken.slot).toBeNull()
    expect(taken.notice).toBe(SLOT_GONE_NOTICE)
    expect(taken.serviceId).toBe(BACK.serviceId)
  })
})

function chosen() {
  const booking = useBookingStore()
  booking.enter(SHOP.id)
  booking.selectService(SERVICES[0]!.id)
  booking.selectBarber(MAURICIO)
  booking.selectDate('2030-03-05')
  booking.selectSlot({ start: START, barberId: MAURICIO })
  return booking
}

/** A new store, as after the login redirect, restored from sessionStorage. */
function restored() {
  setActivePinia(createPinia())
  const booking = useBookingStore()
  booking.enter(SHOP.id)
  return booking
}

describe('useBookingStore', () => {
  it('mirrors the selection in sessionStorage under barberhub.booking', () => {
    chosen()

    expect(stored()).toEqual({
      shopId: SHOP.id,
      serviceId: SERVICES[0]!.id,
      barberId: MAURICIO,
      date: '2030-03-05',
      slot: { start: START, barberId: MAURICIO },
    })
  })

  it('never stores payment data or the reservation', async () => {
    mockFetch(() => jsonResponse(201, { data: RESERVATION }))
    const booking = chosen()
    await booking.reserve(LABELS)
    booking.selectService(SERVICES[1]!.id)

    expect(Object.keys(stored()).sort()).toEqual(
      ['barberId', 'date', 'serviceId', 'shopId', 'slot'].sort(),
    )
    expect(sessionStorage.getItem(BOOKING_STORAGE_KEY)).not.toContain('r1')
  })

  it('restores the selection of the same shop in a new store (after the login)', () => {
    chosen()
    const booking = restored()

    expect(booking.serviceId).toBe(SERVICES[0]!.id)
    expect(booking.barberId).toBe(MAURICIO)
    expect(booking.date).toBe('2030-03-05')
    expect(booking.slot).toEqual({ start: START, barberId: MAURICIO })
  })

  it('does not restore the selection of another shop', () => {
    chosen()
    setActivePinia(createPinia())
    const other = useBookingStore()
    other.enter('otra-barberia')

    expect(other.serviceId).toBeNull()
    expect(other.slot).toBeNull()
    expect(stored()).toBeNull()
  })

  it('clears the selection when the shop changes', () => {
    const booking = chosen()
    booking.enter('otra-barberia')

    expect(booking.serviceId).toBeNull()
    expect(booking.slot).toBeNull()
    expect(stored()).toBeNull()
  })

  it('ignores a stored value that is corrupt, and a storage that throws', () => {
    sessionStorage.setItem(BOOKING_STORAGE_KEY, '{no es json')
    const booking = useBookingStore()
    booking.enter(SHOP.id)
    expect(booking.serviceId).toBeNull()

    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    expect(() => booking.selectService('x')).not.toThrow()
    expect(booking.serviceId).toBe('x')
    vi.restoreAllMocks()
  })

  it('keeps a restored space that the availability still lists', () => {
    chosen()
    const booking = restored()

    booking.revalidate([{ start: START, barberId: MAURICIO }])

    expect(booking.slot).toEqual({ start: START, barberId: MAURICIO })
    expect(booking.notice).toBeNull()
  })

  it('warns and drops a restored space that no longer exists, keeping the rest', () => {
    chosen()
    const booking = restored()

    booking.revalidate([{ start: '2030-03-05T11:00:00-06:00', barberId: MAURICIO }])

    expect(booking.slot).toBeNull()
    expect(booking.notice).toBe(SLOT_GONE_NOTICE)
    expect(stored().slot).toBeNull()
    expect(booking.serviceId).toBe(SERVICES[0]!.id)
    expect(booking.date).toBe('2030-03-05')
  })

  it('revalidates only what was restored', () => {
    const booking = chosen()
    booking.revalidate([])
    expect(booking.slot).not.toBeNull()
  })

  it('clears the selection and the storage when the reservation is created', async () => {
    mockFetch(() => jsonResponse(201, { data: RESERVATION }))
    const booking = chosen()

    expect(await booking.reserve(LABELS)).toBe(true)

    expect(booking.reservation?.reservation.id).toBe('r1')
    expect(booking.serviceId).toBeNull()
    expect(booking.slot).toBeNull()
    expect(stored()).toBeNull()
  })

  it('sends the service, the barber and the start of the space', async () => {
    const fetchMock = mockFetch(() => jsonResponse(201, { data: RESERVATION }))
    await chosen().reserve(LABELS)

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(new URL(request.url).pathname).toBe(`/api/v1/barberias/${SHOP.id}/reservas`)
    expect(await request.json()).toEqual({
      servicio: SERVICES[0]!.id,
      barbero: MAURICIO,
      inicio: START,
    })
  })

  it('keeps the selection on a 409 and exposes the alternatives', async () => {
    mockFetch(() =>
      conflict([
        {
          barbero: MAURICIO,
          inicio: '2030-03-05T10:15:00-06:00',
          fin: '2030-03-05T10:45:00-06:00',
        },
        { sin: 'forma' },
      ]),
    )
    const booking = chosen()

    expect(await booking.reserve(LABELS)).toBe(false)

    expect(booking.error?.status).toBe(409)
    expect(booking.alternatives).toEqual([
      {
        barberId: MAURICIO,
        start: '2030-03-05T10:15:00-06:00',
        end: '2030-03-05T10:45:00-06:00',
      },
    ])
    expect(booking.slot).toEqual({ start: START, barberId: MAURICIO })
    expect(booking.serviceId).toBe(SERVICES[0]!.id)
  })

  it('replaces only the space when an alternative is picked', async () => {
    mockFetch(() =>
      conflict([
        {
          barbero: MAURICIO,
          inicio: '2030-03-05T10:15:00-06:00',
          fin: '2030-03-05T10:45:00-06:00',
        },
      ]),
    )
    const booking = chosen()
    await booking.reserve(LABELS)

    booking.pickAlternative(booking.alternatives[0]!)

    expect(booking.slot).toEqual({ start: '2030-03-05T10:15:00-06:00', barberId: MAURICIO })
    expect(booking.date).toBe('2030-03-05')
    expect(booking.barberId).toBe(MAURICIO)
    expect(booking.error).toBeNull()
    expect(booking.alternatives).toEqual([])
  })

  it('keeps the selection on any other error', async () => {
    mockFetch(() => {
      throw new TypeError('Failed to fetch')
    })
    const booking = chosen()

    expect(await booking.reserve(LABELS)).toBe(false)

    expect(booking.error?.type).toBe('red')
    expect(booking.alternatives).toEqual([])
    expect(booking.slot).not.toBeNull()
  })

  it('clear() forgets everything', () => {
    const booking = chosen()
    booking.clear()

    expect(booking.serviceId).toBeNull()
    expect(stored()).toBeNull()
  })
})
