import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { OFFER_ERRORS, useOffersStore } from '@/stores/offers'
import { jsonResponse, problem } from '../helpers/http'
import {
  BASE,
  OFFER_ID,
  OTHER_SHOP,
  RESERVATION_ID,
  closed,
  conflictError,
  created,
  expiredOfferError,
  membership,
  mockOffersApi,
  offer,
} from '../helpers/offers'
import { SHOP } from '../helpers/shop'

beforeEach(() => {
  setActivePinia(createPinia())
})
afterEach(() => {
  vi.unstubAllGlobals()
})

/** The store after an offer arrived: the shop is known and the offer is on screen. */
async function withOffer(api: Parameters<typeof mockOffersApi>[0] = {}) {
  const mocked = mockOffersApi({ offers: () => [offer()], ...api })
  const store = useOffersStore()
  await store.handleEvent('.oferta.creada', created())
  return { store, ...mocked }
}

describe('offers store: an offer arrives (.oferta.creada)', () => {
  it('reads the offer from the API: the event carries only identifiers', async () => {
    const { store, requests } = await withOffer()

    expect(requests).toContain('GET /api/v1/mis-barberias')
    expect(requests).toContain(`GET ${BASE}/mis-ofertas`)
    expect(store.current?.offer.id).toBe(OFFER_ID)
    expect(store.current?.phase).toBe('open')
    expect(store.current?.shopId).toBe(SHOP.id)
  })

  it('asks for the shops once before it gives up an unknown shop, and shows nothing', async () => {
    const { requests } = mockOffersApi({ memberships: [membership({ id: OTHER_SHOP })] })
    const store = useOffersStore()

    await store.handleEvent('.oferta.creada', created())

    expect(requests.filter((item) => item === 'GET /api/v1/mis-barberias')).toHaveLength(1)
    expect(requests).not.toContain(`GET ${BASE}/mis-ofertas`)
    expect(store.current).toBeNull()
  })

  it('does not ask for the shops again when the shop is already known', async () => {
    const { store, requests } = await withOffer()

    await store.handleEvent('.oferta.creada', created())

    expect(requests.filter((item) => item === 'GET /api/v1/mis-barberias')).toHaveLength(1)
  })

  it('ignores an offer whose deadline has passed and an event that is not understood', async () => {
    const { requests } = mockOffersApi({ offers: () => [offer()] })
    const store = useOffersStore()

    await store.handleEvent('.oferta.creada', created({ vence_en: '2020-01-01T00:00:00Z' }))
    await store.handleEvent('.oferta.creada', { oferta: OFFER_ID })
    await store.handleEvent('.oferta.creada', 'texto')
    await store.handleEvent('.otra.cosa', created())

    expect(requests).toEqual([])
    expect(store.current).toBeNull()
  })

  it('keeps an accepted offer that waits for its payment out of the pop-up', async () => {
    const { store } = await withOffer({ offers: () => [offer({ reserva: RESERVATION_ID })] })

    expect(store.offers[0]?.phase).toBe('paying')
    expect(store.current).toBeNull()
  })

  it('a 403 or 404 of the offers is silent: it is not an error for the person', async () => {
    mockOffersApi({ offers: () => problem(403, { type: 'prohibido' }) })
    const store = useOffersStore()
    await store.loadShops()

    expect(await store.refreshShop(SHOP.id)).toBe('forbidden')
    expect(store.current).toBeNull()
  })
})

describe('offers store: the offer closes (.oferta.cerrada)', () => {
  it.each([
    ['vencida', 'vencida'],
    ['rechazada', 'rechazada'],
    ['aceptada', 'aceptada'],
  ] as const)('closes an open offer for «%s» and tells it once', async (motivo, reason) => {
    const { store } = await withOffer()

    await store.handleEvent('.oferta.cerrada', closed(motivo))

    expect(store.current?.phase).toBe('closed')
    expect(store.current?.reason).toBe(reason)
    // The same event again is the same state, not another one.
    await store.handleEvent('.oferta.cerrada', closed(motivo))
    expect(store.offers).toHaveLength(1)
    expect(store.current?.reason).toBe(reason)
  })

  it('an event of an offer that was never seen here changes nothing', async () => {
    const { store } = await withOffer()

    await store.handleEvent('.oferta.cerrada', closed('vencida', 'otra'))
    await store.handleEvent('.oferta.cerrada', { motivo: 'vencida' })

    expect(store.current?.phase).toBe('open')
  })

  it('«aceptada» never replaces what this device is doing while the answer is in flight', async () => {
    let release: (response: Response) => void = () => {}
    const { store } = await withOffer({
      accept: () => new Promise<Response>((resolve) => (release = resolve)),
    })

    const answer = store.accept(OFFER_ID, true)
    await flushPromises()
    expect(store.find(OFFER_ID)?.phase).toBe('accepting')
    await store.handleEvent('.oferta.cerrada', closed('aceptada'))
    expect(store.find(OFFER_ID)?.phase).toBe('accepting')

    release(jsonResponse(200, { data: offer({ estado: 'aceptada', reserva: RESERVATION_ID }) }))
    expect(await answer).toEqual({ kind: 'confirmed', reservation: RESERVATION_ID })
    // The event that comes after the answer finds it closed and leaves it as it is.
    await store.handleEvent('.oferta.cerrada', closed('aceptada'))
    expect(store.find(OFFER_ID)?.local).toBe(true)
  })

  it('the confirmation of the payment («aceptada») removes the offer that waited for it', async () => {
    const { store } = await withOffer({
      accept: () => jsonResponse(200, { data: offer({ reserva: RESERVATION_ID }) }),
    })
    await store.accept(OFFER_ID, false)
    expect(store.find(OFFER_ID)?.phase).toBe('paying')

    await store.handleEvent('.oferta.cerrada', closed('aceptada'))

    expect(store.find(OFFER_ID)).toBeUndefined()
  })

  it('an offer lost while it waited for its payment («vencida») is forgotten too', async () => {
    const { store } = await withOffer({
      accept: () => jsonResponse(200, { data: offer({ reserva: RESERVATION_ID }) }),
    })
    await store.accept(OFFER_ID, false)

    await store.handleEvent('.oferta.cerrada', closed('vencida'))

    expect(store.find(OFFER_ID)).toBeUndefined()
  })

  it('hiding the pop-up keeps the offer, and dismissing a finished one forgets it', async () => {
    const { store } = await withOffer()

    store.dismiss(OFFER_ID)
    expect(store.current).toBeNull()
    expect(store.find(OFFER_ID)?.hidden).toBe(true)

    await store.handleEvent('.oferta.cerrada', closed('vencida'))
    store.dismiss(OFFER_ID)
    expect(store.find(OFFER_ID)).toBeUndefined()
  })
})

describe('offers store: answering', () => {
  it('accepting with a voucher sends `con_bono` and a confirmed reservation closes the offer', async () => {
    const { store, fetchMock } = await withOffer()

    const outcome = await store.accept(OFFER_ID, true)

    expect(outcome).toEqual({ kind: 'confirmed', reservation: RESERVATION_ID })
    const request = fetchMock.mock.calls.find(([r]) => r.url.endsWith('/aceptacion'))![0]
    expect(await request.clone().json()).toEqual({ con_bono: true })
    // Nothing is left to show: the pop-up does not wait for `.oferta.cerrada` to leave.
    expect(store.current).toBeNull()
    expect(store.find(OFFER_ID)?.phase).toBe('closed')
  })

  it('accepting a reservation to pay leaves the pop-up and keeps the offer pending', async () => {
    const { store } = await withOffer({
      accept: () => jsonResponse(200, { data: offer({ reserva: RESERVATION_ID }) }),
    })

    const outcome = await store.accept(OFFER_ID, false)

    expect(outcome).toEqual({ kind: 'payment', reservation: RESERVATION_ID })
    expect(store.current).toBeNull()
    expect(store.find(OFFER_ID)?.phase).toBe('paying')
  })

  it('an offer that is not open cannot be answered twice', async () => {
    const { store, requests } = await withOffer()
    await store.accept(OFFER_ID, false)
    const before = requests.length

    expect(await store.accept(OFFER_ID, false)).toEqual({ kind: 'failed' })
    expect(await store.reject(OFFER_ID)).toEqual({ kind: 'failed' })
    expect(requests).toHaveLength(before)
  })

  it('an expired offer closes with its message', async () => {
    const { store } = await withOffer({ accept: expiredOfferError })

    expect(await store.accept(OFFER_ID, false)).toEqual({ kind: 'closed' })

    expect(store.current?.reason).toBe('vencida')
    expect(store.current?.hidden).toBe(false)
  })

  it.each([
    ['saldo-insuficiente', OFFER_ERRORS.voucherBalance],
    ['bono-vencido', OFFER_ERRORS.voucherExpired],
    ['espacio-no-disponible', OFFER_ERRORS.taken],
  ])('the error «%s» keeps the offer open with a clear message', async (type, message) => {
    const { store } = await withOffer({
      accept: () => problem(409, { type: `http://localhost/problemas/${type}`, detail: 'texto' }),
    })

    expect(await store.accept(OFFER_ID, true)).toEqual({ kind: 'failed' })

    expect(store.current?.phase).toBe('open')
    expect(store.current?.error).toBe(message)
  })

  it('another conflict shows the text of the API and reads the offers again', async () => {
    let reads = 0
    const { store } = await withOffer({
      offers: () => {
        reads += 1
        return [offer()]
      },
      accept: () => conflictError('La oferta ya no se puede aceptar.'),
    })

    await store.accept(OFFER_ID, false)
    await flushPromises()

    expect(store.current?.error).toBe('La oferta ya no se puede aceptar.')
    expect(reads).toBe(2)
  })

  it('rejecting closes the offer for this device and the event that follows changes nothing', async () => {
    const { store } = await withOffer()

    expect(await store.reject(OFFER_ID)).toEqual({ kind: 'rejected' })
    await store.handleEvent('.oferta.cerrada', closed('rechazada'))

    expect(store.current).toBeNull()
    expect(store.find(OFFER_ID)?.local).toBe(true)
  })

  it('rejecting an expired offer says it expired', async () => {
    const { store } = await withOffer({ reject: expiredOfferError })

    expect(await store.reject(OFFER_ID)).toEqual({ kind: 'closed' })
    expect(store.current?.reason).toBe('vencida')
  })

  it('a failed rejection leaves the offer open with the text of the API', async () => {
    const { store } = await withOffer({
      reject: () => conflictError('Primero cancele la reserva.'),
    })

    await store.reject(OFFER_ID)

    expect(store.current?.phase).toBe('open')
    expect(store.current?.error).toBe('Primero cancele la reserva.')
  })
})

describe('offers store: reading again', () => {
  it('an open offer that the list no longer has is over: «vencida» when its time is up', async () => {
    let listed = [offer({}, -1)]
    const { store } = await withOffer({ offers: () => listed })
    listed = []

    await store.refreshShop(SHOP.id)

    expect(store.current?.reason).toBe('vencida')
  })

  it('an offer that the list no longer has, with time left, «ya no está disponible»', async () => {
    let listed = [offer()]
    const { store } = await withOffer({ offers: () => listed })
    listed = []

    await store.refreshShop(SHOP.id)

    expect(store.current?.reason).toBe('ya-no-disponible')
  })

  it('what this device did is not overwritten by a read', async () => {
    const { store } = await withOffer()
    await store.accept(OFFER_ID, true)

    await store.refreshShop(SHOP.id)

    // A list that still names the offer does not bring it back as new in the same read.
    expect(store.find(OFFER_ID)).toBeUndefined()
    expect(store.current).toBeNull()
  })

  it('resync reads the offers of the shops where the person is a customer only', async () => {
    const { requests } = mockOffersApi({
      memberships: [membership(), membership({ id: OTHER_SHOP, rol: 'dueno' })],
      offers: () => [offer()],
    })
    const store = useOffersStore()

    await store.resync()

    expect(requests.filter((item) => item.endsWith('/mis-ofertas'))).toEqual([
      `GET ${BASE}/mis-ofertas`,
    ])
    expect(store.current?.offer.id).toBe(OFFER_ID)
  })

  it('reset forgets everything and drops the answers that arrive after it', async () => {
    let release: (response: Response) => void = () => {}
    const { store } = await withOffer({
      accept: () => new Promise<Response>((resolve) => (release = resolve)),
    })
    const answer = store.accept(OFFER_ID, false)
    await flushPromises()

    store.reset()
    release(jsonResponse(200, { data: offer({ estado: 'aceptada', reserva: RESERVATION_ID }) }))

    expect(await answer).toEqual({ kind: 'failed' })
    expect(store.offers).toEqual([])
    expect(store.shops).toEqual([])
    expect(store.current).toBeNull()
  })
})

describe('offers store: names', () => {
  it('takes the name of the barber from the API and does not read the day for it', async () => {
    const { store, requests } = await withOffer()
    const named = offer()

    await store.ensureNames(SHOP.id, 's1', named.inicio, store.missesBarberName(named))

    expect(store.serviceName(SHOP.id, 's1')).toBe('Corte clásico')
    expect(store.barberOf(named)).toBe('Mauricio')
    expect(requests.filter((item) => item.endsWith('/disponibilidad'))).toEqual([])
  })

  it('falls back to the barber that the day lists only when the API sends null', async () => {
    const { store, requests } = await withOffer()
    const unnamed = offer({ barbero_nombre: null })

    expect(store.missesBarberName(unnamed)).toBe(true)
    await store.ensureNames(SHOP.id, 's1', unnamed.inicio, store.missesBarberName(unnamed))

    expect(requests.filter((item) => item.endsWith('/disponibilidad'))).toHaveLength(1)
    expect(store.barberOf(unnamed)).toBe('Mauricio')
  })

  it('a row for any barber has no name to look for', async () => {
    const { store } = await withOffer()

    expect(store.missesBarberName({ barbero: null, barbero_nombre: null })).toBe(false)
    expect(store.barberOf({ barbero: null, barbero_nombre: null })).toBe('')
  })

  it('without a name anywhere the offer is shown without it', async () => {
    const { store } = await withOffer({ barbers: [] })
    const unnamed = offer({ barbero_nombre: null })

    await store.ensureNames(SHOP.id, 's1', unnamed.inicio, true)

    expect(store.barberOf(unnamed)).toBe('')
  })
})
