import { ref } from 'vue'

import { api, isApiError, type components } from '@/api'

export type CustomerBooking = components['schemas']['ReservaDeCliente']

/** Where the list stands once loaded: with its reservations (maybe none), or why it could not be. */
export type BookingListResult = 'ready' | 'forbidden' | 'error'

export type BookingStatusKey =
  | 'temporary'
  | 'confirmed'
  | 'completed'
  | 'no_show'
  | 'cancelled_by_customer'
  | 'cancelled_by_barbershop'
  | 'expired'

/** States of the API (`estado`) and their keys in the status label. */
const STATUS_KEYS: Record<string, BookingStatusKey> = {
  temporal: 'temporary',
  confirmada: 'confirmed',
  completada: 'completed',
  ausente: 'no_show',
  cancelada_por_cliente: 'cancelled_by_customer',
  cancelada_por_barberia: 'cancelled_by_barbershop',
  expirada: 'expired',
}

/** Key of the status label for a state of the API; an unknown one is shown as expired (inert). */
export function statusKeyOf(state: string): BookingStatusKey {
  return STATUS_KEYS[state] ?? 'expired'
}

/** Largest page the API allows; the list asks for every page so the grouping sees them all. */
const PAGE_SIZE = 50
/** Safety stop: 20 pages of 50 reservations in one barbershop. */
const MAX_PAGES = 20

/**
 * An upcoming reservation still waits for its day: it is temporary or confirmed and has not ended.
 * Everything else (attended, absent, cancelled, expired or already over) is past. `now` enters as a
 * parameter so the grouping is testable.
 */
export function isUpcoming(booking: CustomerBooking, now: number = Date.now()): boolean {
  return (
    (booking.estado === 'temporal' || booking.estado === 'confirmada') &&
    Date.parse(booking.fin) > now
  )
}

/**
 * The reservations of the person in one barbershop (RF-13), split in upcoming (nearest first, as the
 * API sorts them) and past (the most recent first). The route of the API is tied to one barbershop.
 */
export function useMyBookings() {
  const upcoming = ref<CustomerBooking[]>([])
  const past = ref<CustomerBooking[]>([])
  // Only the last call may write: an older one that ends later is dropped.
  let call = 0

  async function load(shopId: string): Promise<BookingListResult> {
    const current = ++call
    try {
      const all: CustomerBooking[] = []
      let page = 1
      let last = 1
      do {
        const { data } = await api.GET('/barberias/{barberia}/mis-reservas', {
          params: { path: { barberia: shopId }, query: { pagina: page, por_pagina: PAGE_SIZE } },
        })
        all.push(...data!.data)
        last = data!.meta.ultima_pagina
        page += 1
      } while (page <= last && page <= MAX_PAGES)
      if (current !== call) return 'ready'
      const now = Date.now()
      upcoming.value = all.filter((item) => isUpcoming(item, now))
      past.value = all
        .filter((item) => !isUpcoming(item, now))
        .sort((a, b) => b.inicio.localeCompare(a.inicio))
      return 'ready'
    } catch (caught) {
      if (current !== call) return 'ready'
      return isApiError(caught) && caught.status === 403 ? 'forbidden' : 'error'
    }
  }

  return { upcoming, past, load }
}
