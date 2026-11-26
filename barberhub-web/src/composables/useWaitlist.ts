import { computed, ref } from 'vue'

import { api, isApiError, type ApiError, type components } from '@/api'
import { dateOf } from '@/composables/format'
import { networkError } from '@/api/errors'

export type WaitlistEntry = components['schemas']['FilaDeEspera']

/** The most rows of a person that wait at once in one shop (LE-01). */
export const MAX_WAITLIST_ENTRIES = 3

/** How the list of rows stands once loaded: with them (maybe none), or why it could not be. */
export type WaitlistResult = 'ready' | 'forbidden' | 'error'

/**
 * The rows of the person in one barbershop (RF-30): at most three, never paged. The route of the API is
 * tied to one shop, and owner and collaborator get 403.
 */
export function useWaitlistEntries() {
  const entries = ref<WaitlistEntry[]>([])
  const result = ref<WaitlistResult | null>(null)
  // Only the last call may write: an older one that ends later is dropped.
  let call = 0

  async function load(shopId: string): Promise<WaitlistResult> {
    const current = ++call
    try {
      const { data } = await api.GET('/barberias/{barberia}/mis-filas', {
        params: { path: { barberia: shopId } },
      })
      if (current !== call) return result.value ?? 'ready'
      entries.value = data!.data
      result.value = 'ready'
    } catch (caught) {
      if (current !== call) return result.value ?? 'ready'
      result.value = isApiError(caught) && caught.status === 403 ? 'forbidden' : 'error'
    }
    return result.value
  }

  /** Leaves a row (204, idempotent). Throws an ApiError; the list is read again by the caller. */
  async function leave(shopId: string, id: string): Promise<void> {
    await api.DELETE('/barberias/{barberia}/filas/{fila}', {
      params: { path: { barberia: shopId, fila: id } },
    })
    entries.value = entries.value.filter((item) => item.id !== id)
  }

  return { entries, result, load, leave }
}

/** A space that the API names when there is room for the combination: reserving beats waiting. */
export interface WaitlistSpace {
  start: string
  end: string
  barberId: string
}

function readSpaces(raw: unknown[]): WaitlistSpace[] {
  const found: WaitlistSpace[] = []
  for (const item of raw) {
    if (typeof item !== 'object' || item === null) continue
    const { inicio, fin, barbero } = item as Record<string, unknown>
    if (typeof inicio === 'string' && typeof fin === 'string' && typeof barbero === 'string') {
      found.push({ start: inicio, end: fin, barberId: barbero })
    }
  }
  return found
}

/** What the person sees after asking to join. */
export type JoinState =
  | { kind: 'idle' }
  | { kind: 'joining' }
  | { kind: 'joined' }
  | { kind: 'blocked'; message: string }
  | { kind: 'spaces'; spaces: WaitlistSpace[] }
  | { kind: 'error'; message: string }

export const JOIN_TEXT = {
  full: 'Ya tiene 3 filas activas en esta barbería. Salga de una para anotarse en otra.',
  duplicate: 'Ya está en la fila para ese servicio, día y barbero.',
  staff: 'El equipo de la barbería no puede anotarse en la fila.',
  generic: 'No pudimos anotarlo en la fila. Intente de nuevo.',
} as const

export interface JoinInput {
  shopId: string
  service: string
  /** «AAAA-MM-DD». */
  date: string
  /** `null` is «cualquiera». */
  barber: string | null
}

/**
 * Joins the row for a service, a day and a barber (RF-30). It checks first with the rows of the person
 * (at most three, no repeats); the text of the API is the fallback for what that cannot tell (the
 * waitlist turned off, a race). When the API answers with spaces, they are shown instead of a row.
 */
export function useWaitlistJoin() {
  const state = ref<JoinState>({ kind: 'idle' })
  const mine = useWaitlistEntries()
  const busy = computed(() => state.value.kind === 'joining')

  function reset(): void {
    if (!busy.value) state.value = { kind: 'idle' }
  }

  async function join(input: JoinInput): Promise<void> {
    if (busy.value) return
    state.value = { kind: 'joining' }
    if ((await mine.load(input.shopId)) === 'ready') {
      if (mine.entries.value.length >= MAX_WAITLIST_ENTRIES) {
        state.value = { kind: 'blocked', message: JOIN_TEXT.full }
        return
      }
      const repeated = mine.entries.value.some(
        (item) =>
          item.servicio === input.service &&
          dateOf(item.fecha) === input.date &&
          (item.barbero ?? null) === input.barber,
      )
      if (repeated) {
        state.value = { kind: 'blocked', message: JOIN_TEXT.duplicate }
        return
      }
    }
    try {
      await api.POST('/barberias/{barberia}/filas', {
        params: { path: { barberia: input.shopId } },
        body: { servicio: input.service, fecha: input.date, barbero: input.barber },
      })
      state.value = { kind: 'joined' }
    } catch (caught) {
      const error: ApiError = isApiError(caught) ? caught : networkError(caught)
      const spaces = error.status === 409 ? readSpaces(error.spaces) : []
      if (spaces.length > 0) state.value = { kind: 'spaces', spaces }
      else if (error.status === 403) state.value = { kind: 'blocked', message: JOIN_TEXT.staff }
      else state.value = { kind: 'error', message: error.detail || JOIN_TEXT.generic }
    }
  }

  return { state, busy, join, reset }
}
