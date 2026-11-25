import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { api, isApiError, type components } from '@/api'
import { dateOf } from '@/composables/format'

export type Offer = components['schemas']['OfertaDeEspera']

/**
 * Where an offer stands on this device:
 * - `open`: it can be answered;
 * - `accepting` and `rejecting`: this device sent the answer and waits for the API;
 * - `paying`: accepted with a payment still to be made (the offer stays pending on the API until the
 *   gateway confirms it, PA-06), so the pop-up has nothing more to say;
 * - `closed`: it is over, and `reason` says how.
 */
export type OfferPhase = 'open' | 'accepting' | 'rejecting' | 'paying' | 'closed'

export type CloseReason = 'vencida' | 'aceptada' | 'rechazada' | 'ya-no-disponible'

export interface TrackedOffer {
  offer: Offer
  shopId: string
  phase: OfferPhase
  reason: CloseReason | null
  /** This device sent the answer that closed it: there is nothing to tell the person. */
  local: boolean
  /** Why the last answer failed, when it did and the offer is still open. */
  error: string | null
  /** The person hid the pop-up (Escape or «Cerrar»); the offer is still answerable in «Mis filas». */
  hidden: boolean
}

/** A shop of the person (any role), with what is needed to name it and to reach its screens. */
export interface OfferShop {
  id: string
  name: string
  link: string
  role: 'dueno' | 'colaborador' | 'cliente'
}

/** What the screen does next after answering. */
export type AnswerOutcome =
  | { kind: 'confirmed'; reservation: string }
  | { kind: 'payment'; reservation: string }
  | { kind: 'rejected' }
  | { kind: 'closed' }
  | { kind: 'failed' }

/** How a read of the offers of one shop ended. A 403 or 404 is `forbidden`: not a customer there. */
export type OffersResult = 'ready' | 'forbidden' | 'error'

export const OFFER_ERRORS = {
  voucherBalance:
    'Su bono ya no tiene sesiones disponibles. Puede aceptar y pagar con tarjeta o SINPE Móvil.',
  voucherExpired:
    'Su bono vence antes de la fecha de la cita. Puede aceptar y pagar con tarjeta o SINPE Móvil.',
  taken: 'Ese espacio ya no está disponible.',
  generic: 'No pudimos completar la acción. Revise su conexión e intente de nuevo.',
} as const

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isPast(moment: string): boolean {
  const time = Date.parse(moment)
  return Number.isNaN(time) || time <= Date.now()
}

export const useOffersStore = defineStore('offers', () => {
  const offers = ref<TrackedOffer[]>([])
  const shops = ref<OfferShop[]>([])
  const services = ref<Record<string, Record<string, string>>>({})
  const barbers = ref<Record<string, string>>({})
  /** Goes up on every change of an offer, so a screen that shows the lists can read them again. */
  const version = ref(0)

  // `reset()` bumps the generation so an answer that arrives after it (a logout, another account) is dropped.
  let generation = 0
  let pendingShops: Promise<void> | null = null
  const askedBarbers = new Set<string>()

  /** The offer that the pop-up shows: the first one that is not hidden nor waiting for a payment. */
  const current = computed(
    () => offers.value.find((item) => !item.hidden && item.phase !== 'paying') ?? null,
  )
  /** How many more are waiting behind the one on screen. */
  const waiting = computed(
    () =>
      offers.value.filter(
        (item) => !item.hidden && item.phase !== 'paying' && item !== current.value,
      ).length,
  )

  function bump(): void {
    version.value += 1
  }

  function find(id: string): TrackedOffer | undefined {
    return offers.value.find((item) => item.offer.id === id)
  }

  function offersOf(shopId: string): TrackedOffer[] {
    return offers.value.filter((item) => item.shopId === shopId)
  }

  function shopOf(id: string): OfferShop | undefined {
    return shops.value.find((item) => item.id === id)
  }

  function close(tracked: TrackedOffer, reason: CloseReason, local = false): void {
    tracked.phase = 'closed'
    tracked.reason = reason
    tracked.local = local
    tracked.error = null
    // A closing that the person caused has nothing to say; any other one is told once.
    tracked.hidden = local && reason !== 'vencida'
    bump()
  }

  function remove(id: string): void {
    offers.value = offers.value.filter((item) => item.offer.id !== id)
    bump()
  }

  /** Hides the pop-up of an offer; a finished one is forgotten. */
  function dismiss(id: string): void {
    const tracked = find(id)
    if (tracked === undefined) return
    if (tracked.phase === 'closed') remove(id)
    else {
      tracked.hidden = true
      bump()
    }
  }

  /** Forgets everything: the person who leaves or changes must never see the offers of the other. */
  function reset(): void {
    generation += 1
    pendingShops = null
    offers.value = []
    shops.value = []
    services.value = {}
    barbers.value = {}
    askedBarbers.clear()
    bump()
  }

  // —— Shops and names ——

  async function fetchShops(current: number): Promise<void> {
    try {
      const { data } = await api.GET('/mis-barberias')
      if (current !== generation) return
      shops.value = data!.data.map((item) => ({
        id: item.id,
        name: item.nombre,
        link: item.enlace,
        role: item.rol,
      }))
    } catch {
      // Quiet: without the list nothing can be named, and the next event or return asks again.
    }
  }

  /** Reads `/mis-barberias` (every role). Two calls at once share one request. */
  function loadShops(): Promise<void> {
    if (pendingShops !== null) return pendingShops
    const request = fetchShops(generation).finally(() => {
      if (pendingShops === request) pendingShops = null
    })
    pendingShops = request
    return request
  }

  /** The shop of an event. One that is not known is asked for once before it is given up. */
  async function ensureShop(id: string): Promise<OfferShop | null> {
    const known = shopOf(id)
    if (known !== undefined) return known
    await loadShops()
    return shopOf(id) ?? null
  }

  /** Names of the services of a shop, read once; a failure leaves the offer without them. */
  async function loadServices(shopId: string): Promise<void> {
    if (services.value[shopId] !== undefined) return
    const current = generation
    try {
      const { data } = await api.GET('/barberias/{barberia}/servicios', {
        params: { path: { barberia: shopId } },
      })
      if (current !== generation) return
      services.value = {
        ...services.value,
        [shopId]: Object.fromEntries(data!.data.map((item) => [item.id, item.nombre])),
      }
    } catch {
      // Quiet.
    }
  }

  /**
   * The name of the barber, when it can be known. The API has no list of barbers: the only names that
   * reach the client are those of the barbers that have spaces in a day, so this is best effort and the
   * offer is shown without the name when it is not found.
   */
  async function loadBarberNames(shopId: string, serviceId: string, date: string): Promise<void> {
    const key = `${shopId}|${serviceId}|${date}`
    if (askedBarbers.has(key)) return
    askedBarbers.add(key)
    const current = generation
    try {
      const { data } = await api.GET('/barberias/{barberia}/disponibilidad', {
        params: { path: { barberia: shopId }, query: { servicio: serviceId, fecha: date } },
      })
      if (current !== generation) return
      const groups = (data?.data as { barberos?: unknown } | undefined)?.barberos
      if (!Array.isArray(groups)) return
      const found: Record<string, string> = {}
      for (const group of groups) {
        if (isRecord(group) && typeof group.id === 'string' && typeof group.nombre === 'string') {
          found[group.id] = group.nombre
        }
      }
      barbers.value = { ...barbers.value, ...found }
    } catch {
      askedBarbers.delete(key)
    }
  }

  /**
   * Loads the names that an offer or a row of the list needs. The API names the barber itself
   * (`barbero_nombre`); the day is read for the name only when it comes `null` while there is a barber.
   */
  async function ensureNames(
    shopId: string,
    serviceId: string,
    date: string,
    lookForBarber = true,
  ): Promise<void> {
    await Promise.all([
      loadServices(shopId),
      lookForBarber ? loadBarberNames(shopId, serviceId, dateOf(date)) : Promise.resolve(),
    ])
  }

  /** Whether a name has to be looked for: there is a barber and the API did not name them. */
  function missesBarberName(item: {
    barbero: string | null
    barbero_nombre: string | null
  }): boolean {
    return item.barbero !== null && item.barbero_nombre === null
  }

  /** The name of the barber of an offer or a row: the one of the API and, only when it is `null`, the one the day lists. */
  function barberOf(item: { barbero: string | null; barbero_nombre: string | null }): string {
    return item.barbero_nombre ?? barberName(item.barbero)
  }

  function serviceName(shopId: string, serviceId: string): string {
    return services.value[shopId]?.[serviceId] ?? ''
  }

  function barberName(barberId: string | null): string {
    return barberId === null ? '' : (barbers.value[barberId] ?? '')
  }

  // —— Reading the offers ——

  function track(shopId: string, offer: Offer): TrackedOffer {
    return {
      offer,
      shopId,
      // Accepted with a payment still to be made: the pop-up has no more to say.
      phase: offer.reserva !== null ? 'paying' : 'open',
      reason: null,
      local: false,
      error: null,
      hidden: false,
    }
  }

  /**
   * Reads `GET /barberias/{barberia}/mis-ofertas` and brings the offers of that shop up to date. What
   * the person did on this device is never overwritten by what the read says; an offer that is open
   * here and is not in the list any more is over, and the pop-up says so.
   */
  async function refreshShop(shopId: string): Promise<OffersResult> {
    const current = generation
    try {
      const { data } = await api.GET('/barberias/{barberia}/mis-ofertas', {
        params: { path: { barberia: shopId } },
      })
      if (current !== generation) return 'ready'
      const fresh = data!.data
      const ids = new Set(fresh.map((item) => item.id))
      for (const offer of fresh) {
        const known = find(offer.id)
        if (known === undefined) {
          offers.value.push(track(shopId, offer))
        } else if (known.phase === 'open' || known.phase === 'paying') {
          known.offer = offer
          if (offer.reserva !== null) known.phase = 'paying'
        }
      }
      for (const tracked of offersOf(shopId)) {
        if (ids.has(tracked.offer.id)) continue
        if (tracked.phase === 'open') {
          close(tracked, isPast(tracked.offer.vence_en) ? 'vencida' : 'ya-no-disponible')
        } else if (tracked.phase === 'paying') {
          // Paid or lost: the screens that list the rows read the result.
          remove(tracked.offer.id)
        }
      }
      // What this device closed itself has nothing left to tell (it was kept for this read, so a late
      // list cannot bring it back as new).
      offers.value = offers.value.filter(
        (item) => !(item.phase === 'closed' && item.local && item.hidden),
      )
      bump()
      return 'ready'
    } catch (error) {
      if (current !== generation) return 'ready'
      // Not a customer of that shop (owner or collaborator, or no role): nothing to tell.
      return isApiError(error) && (error.status === 403 || error.status === 404)
        ? 'forbidden'
        : 'error'
    }
  }

  /** Reads again the offers of every shop where the person is a customer (a reconnection, a return to the tab). */
  async function resync(): Promise<void> {
    await loadShops()
    await Promise.all(
      shops.value.filter((item) => item.role === 'cliente').map((item) => refreshShop(item.id)),
    )
  }

  // —— Events of the channel ——

  async function onCreated(payload: Record<string, unknown>): Promise<void> {
    const { barberia, vence_en: deadline } = payload
    if (typeof barberia !== 'string' || typeof deadline !== 'string') return
    // An offer that is already over is not worth a read.
    if (isPast(deadline)) return
    const current = generation
    const shop = await ensureShop(barberia)
    if (shop === null || current !== generation) return
    await refreshShop(shop.id)
  }

  function onClosed(payload: Record<string, unknown>): void {
    const { oferta: id, motivo } = payload
    if (typeof id !== 'string') return
    const tracked = find(id)
    // Not seen on this device: nothing to close, and the next read will not list it.
    if (tracked === undefined) return
    // This device is answering: the answer of the API decides, and the same event does not repeat it.
    if (
      tracked.phase === 'accepting' ||
      tracked.phase === 'rejecting' ||
      tracked.phase === 'closed'
    ) {
      return
    }
    if (motivo === 'vencida') {
      if (tracked.phase === 'paying') remove(id)
      else close(tracked, 'vencida')
    } else if (motivo === 'rechazada') {
      if (tracked.phase === 'paying') remove(id)
      else close(tracked, 'rechazada')
    } else if (motivo === 'aceptada') {
      // Accepted here (to be paid) it is the confirmation of the payment: the screens of the payment
      // already say so, and it never replaces what this device is doing. Elsewhere it is news.
      if (tracked.phase === 'paying') remove(id)
      else close(tracked, 'aceptada')
    }
  }

  /** Handles an event of the private channel of the person. */
  function handleEvent(event: string, payload: unknown): Promise<void> {
    if (!isRecord(payload)) return Promise.resolve()
    if (event === '.oferta.creada') return onCreated(payload)
    if (event === '.oferta.cerrada') onClosed(payload)
    return Promise.resolve()
  }

  // —— Answers ——

  function failure(tracked: TrackedOffer, type: string, detail: string): void {
    tracked.phase = 'open'
    if (type.endsWith('saldo-insuficiente')) tracked.error = OFFER_ERRORS.voucherBalance
    else if (type.endsWith('bono-vencido')) tracked.error = OFFER_ERRORS.voucherExpired
    else if (type.endsWith('espacio-no-disponible')) tracked.error = OFFER_ERRORS.taken
    else tracked.error = detail
    bump()
  }

  /**
   * Accepts an offer (`con_bono` to pay with a session of a voucher). The answer of the API says what
   * comes next: a confirmed reservation, or a reservation to pay before `vence_en`. The pop-up does not
   * wait for `.oferta.cerrada` to leave.
   */
  async function accept(id: string, withVoucher: boolean): Promise<AnswerOutcome> {
    const tracked = find(id)
    if (tracked === undefined || tracked.phase !== 'open') return { kind: 'failed' }
    const current = generation
    tracked.phase = 'accepting'
    tracked.error = null
    bump()
    try {
      const { data } = await api.POST('/barberias/{barberia}/ofertas/{oferta}/aceptacion', {
        params: { path: { barberia: tracked.shopId, oferta: id } },
        body: { con_bono: withVoucher },
      })
      if (current !== generation) return { kind: 'failed' }
      const result = data!.data
      tracked.offer = result
      if (result.reserva === null) {
        failure(tracked, '', OFFER_ERRORS.generic)
        return { kind: 'failed' }
      }
      if (result.estado === 'aceptada') {
        close(tracked, 'aceptada', true)
        return { kind: 'confirmed', reservation: result.reserva }
      }
      tracked.phase = 'paying'
      bump()
      return { kind: 'payment', reservation: result.reserva }
    } catch (error) {
      if (current !== generation) return { kind: 'failed' }
      if (isApiError(error) && error.type.endsWith('oferta-vencida')) {
        close(tracked, 'vencida', true)
        return { kind: 'closed' }
      }
      failure(
        tracked,
        isApiError(error) ? error.type : '',
        isApiError(error) ? error.detail : OFFER_ERRORS.generic,
      )
      // The space or the offer may be gone: the read brings the truth.
      if (isApiError(error) && error.status === 409) void refreshShop(tracked.shopId)
      return { kind: 'failed' }
    }
  }

  /** Rejects an offer: it goes to the next person and the row keeps its place. */
  async function reject(id: string): Promise<AnswerOutcome> {
    const tracked = find(id)
    if (tracked === undefined || tracked.phase !== 'open') return { kind: 'failed' }
    const current = generation
    tracked.phase = 'rejecting'
    tracked.error = null
    bump()
    try {
      await api.POST('/barberias/{barberia}/ofertas/{oferta}/rechazo', {
        params: { path: { barberia: tracked.shopId, oferta: id } },
      })
      if (current !== generation) return { kind: 'failed' }
      close(tracked, 'rechazada', true)
      return { kind: 'rejected' }
    } catch (error) {
      if (current !== generation) return { kind: 'failed' }
      if (isApiError(error) && error.type.endsWith('oferta-vencida')) {
        close(tracked, 'vencida', true)
        return { kind: 'closed' }
      }
      failure(
        tracked,
        isApiError(error) ? error.type : '',
        isApiError(error) ? error.detail : OFFER_ERRORS.generic,
      )
      return { kind: 'failed' }
    }
  }

  return {
    offers,
    shops,
    version,
    current,
    waiting,
    find,
    offersOf,
    shopOf,
    dismiss,
    reset,
    loadShops,
    ensureShop,
    refreshShop,
    resync,
    handleEvent,
    ensureNames,
    missesBarberName,
    barberOf,
    serviceName,
    barberName,
    accept,
    reject,
  }
})
