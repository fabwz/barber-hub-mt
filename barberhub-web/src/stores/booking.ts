import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api, isApiError, type ApiError, type components } from '@/api'
import { networkError } from '@/api/errors'

export type Reservation = components['schemas']['Reserva']

/** The selection lives in sessionStorage under this key so it survives the login. Never payment data. */
export const BOOKING_STORAGE_KEY = 'barberhub.booking'

export const SLOT_GONE_NOTICE = 'El espacio que había elegido ya no está disponible.'

export interface ChosenSlot {
  /** ISO 8601 start of the space, as the API delivers it. */
  start: string
  barberId: string
}

/** A space the API offers when the one asked for is taken (409 `espacio-no-disponible`). */
export interface SlotAlternative {
  start: string
  end: string
  barberId: string
}

/** The reservation just created with the names needed to show it. */
export interface CreatedReservation {
  reservation: Reservation
  serviceName: string
  barberName: string
}

interface StoredSelection {
  shopId: string
  serviceId: string | null
  barberId: string | null
  date: string | null
  slot: ChosenSlot | null
}

function text(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

function readStored(): StoredSelection | null {
  try {
    const raw = globalThis.sessionStorage.getItem(BOOKING_STORAGE_KEY)
    if (raw === null) return null
    const data: unknown = JSON.parse(raw)
    if (typeof data !== 'object' || data === null) return null
    const record = data as Record<string, unknown>
    const shopId = text(record.shopId)
    if (shopId === null) return null
    const slot = record.slot as Record<string, unknown> | null | undefined
    const start = slot ? text(slot.start) : null
    const slotBarber = slot ? text(slot.barberId) : null
    return {
      shopId,
      serviceId: text(record.serviceId),
      barberId: text(record.barberId),
      date: text(record.date),
      slot: start !== null && slotBarber !== null ? { start, barberId: slotBarber } : null,
    }
  } catch {
    return null
  }
}

function writeStored(value: StoredSelection | null): void {
  try {
    if (value === null) globalThis.sessionStorage.removeItem(BOOKING_STORAGE_KEY)
    else globalThis.sessionStorage.setItem(BOOKING_STORAGE_KEY, JSON.stringify(value))
  } catch {
    // No storage (blocked, private window): the selection lives only in memory.
  }
}

/** The 409 members of the contract are not typed, so they are read defensively. */
function readAlternatives(raw: unknown[]): SlotAlternative[] {
  return raw.flatMap((item): SlotAlternative[] => {
    if (typeof item !== 'object' || item === null) return []
    const { barbero, inicio, fin } = item as Record<string, unknown>
    return typeof barbero === 'string' && typeof inicio === 'string' && typeof fin === 'string'
      ? [{ barberId: barbero, start: inicio, end: fin }]
      : []
  })
}

export const useBookingStore = defineStore('booking', () => {
  const shopId = ref<string | null>(null)
  const serviceId = ref<string | null>(null)
  /** `null` is «Sin preferencia». */
  const barberId = ref<string | null>(null)
  const date = ref<string | null>(null)
  const slot = ref<ChosenSlot | null>(null)
  const reservation = ref<CreatedReservation | null>(null)
  const notice = ref<string | null>(null)
  const submitting = ref(false)
  const error = ref<ApiError | null>(null)
  const alternatives = ref<SlotAlternative[]>([])
  /** A temporary reservation is being cancelled to edit it. */
  const releasing = ref(false)
  // A selection restored from the storage must be checked against the availability once.
  let pendingRevalidation = false

  function persist(): void {
    if (shopId.value === null) return
    const empty =
      serviceId.value === null &&
      barberId.value === null &&
      date.value === null &&
      slot.value === null
    writeStored(
      empty
        ? null
        : {
            shopId: shopId.value,
            serviceId: serviceId.value,
            barberId: barberId.value,
            date: date.value,
            slot: slot.value,
          },
    )
  }

  function clearSelection(): void {
    serviceId.value = null
    barberId.value = null
    date.value = null
    slot.value = null
    notice.value = null
    error.value = null
    alternatives.value = []
    pendingRevalidation = false
  }

  /** Forgets everything, including the stored copy. */
  function clear(): void {
    clearSelection()
    reservation.value = null
    persist()
  }

  /** Sets the shop being viewed: another shop starts clean; the first one restores the stored selection. */
  function enter(id: string): void {
    if (shopId.value === id) return
    const changed = shopId.value !== null
    clearSelection()
    reservation.value = null
    shopId.value = id
    if (changed) {
      persist()
      return
    }
    const stored = readStored()
    if (stored === null || stored.shopId !== id) {
      persist()
      return
    }
    serviceId.value = stored.serviceId
    barberId.value = stored.barberId
    date.value = stored.date
    slot.value = stored.slot
    pendingRevalidation = stored.slot !== null
  }

  function touch(): void {
    reservation.value = null
    notice.value = null
    error.value = null
    alternatives.value = []
    pendingRevalidation = false
  }

  function selectService(id: string): void {
    if (serviceId.value === id) return
    touch()
    serviceId.value = id
    barberId.value = null
    slot.value = null
    persist()
  }

  function selectBarber(id: string | null): void {
    if (barberId.value === id) return
    touch()
    barberId.value = id
    slot.value = null
    persist()
  }

  function selectDate(value: string): void {
    if (date.value === value) return
    touch()
    date.value = value
    slot.value = null
    persist()
  }

  function selectSlot(value: ChosenSlot | null): void {
    notice.value = null
    error.value = null
    alternatives.value = []
    pendingRevalidation = false
    slot.value = value
    persist()
  }

  /** Checks a restored space against the spaces the API lists now; if it is gone, warns and drops it. */
  function revalidate(available: ChosenSlot[]): void {
    if (!pendingRevalidation) return
    pendingRevalidation = false
    const chosen = slot.value
    if (chosen === null) return
    const found = available.some(
      (item) => item.start === chosen.start && item.barberId === chosen.barberId,
    )
    if (!found) {
      slot.value = null
      notice.value = SLOT_GONE_NOTICE
      persist()
    }
  }

  /** Takes one of the spaces the API offered: it replaces the space and keeps the rest. */
  function pickAlternative(alternative: SlotAlternative): void {
    selectSlot({ start: alternative.start, barberId: alternative.barberId })
  }

  /** Creates the temporary reservation (RF-11). The caller checks the session first. */
  async function reserve(labels: { serviceName: string; barberName: string }): Promise<boolean> {
    if (shopId.value === null || serviceId.value === null || slot.value === null) return false
    submitting.value = true
    error.value = null
    alternatives.value = []
    try {
      const { data } = await api.POST('/barberias/{barberia}/reservas', {
        params: { path: { barberia: shopId.value } },
        body: { servicio: serviceId.value, barbero: slot.value.barberId, inicio: slot.value.start },
      })
      reservation.value = { reservation: data!.data, ...labels }
      clearSelection()
      persist()
      return true
    } catch (caught) {
      const failure = isApiError(caught) ? caught : networkError(caught)
      error.value = failure
      if (failure.status === 409 && failure.type.endsWith('espacio-no-disponible')) {
        alternatives.value = readAlternatives(failure.alternatives)
      }
      return false
    } finally {
      submitting.value = false
    }
  }

  /**
   * Cancels a temporary reservation of the customer so its space is free again (RF-16, RE-04): going
   * back to edit it must not collide with its own hold. The caller never offers it with a payment in
   * progress. A failure changes nothing: the reservation expires by itself at its deadline.
   */
  async function release(barberia: string, reserva: string): Promise<void> {
    if (releasing.value) return
    releasing.value = true
    try {
      await api.POST('/barberias/{barberia}/reservas/{reserva}/cancelacion', {
        params: { path: { barberia, reserva } },
      })
    } catch {
      // Expired, already confirmed or no connection: the way back goes on.
    } finally {
      releasing.value = false
    }
  }

  return {
    shopId,
    serviceId,
    barberId,
    date,
    slot,
    reservation,
    notice,
    submitting,
    error,
    alternatives,
    releasing,
    enter,
    clear,
    selectService,
    selectBarber,
    selectDate,
    selectSlot,
    revalidate,
    pickAlternative,
    reserve,
    release,
  }
})
