import { onBeforeUnmount, ref, watch, type Ref } from 'vue'

import { api, isApiError, type ApiError, type components } from '@/api'
import { networkError } from '@/api/errors'

export type AssistedClient = components['schemas']['ClienteAsistido']
export type AssistedBookingInput = components['schemas']['RegistrarReservaAsistida']
export type AssistedReservation = components['schemas']['ReservaAsistida']

/** The owner types and the search waits this long before asking, so each key is not a request. */
const SEARCH_DELAY_MS = 250

export type SearchStatus = 'idle' | 'loading' | 'ready' | 'error'

/** A space the API offered when the chosen one was taken (409 `espacio-no-disponible`). */
export interface OfferedSpace {
  barberId: string
  start: string
  end: string
}

/** The 409 members of the contract are not typed, so they are read defensively. */
export function readOfferedSpaces(raw: unknown[]): OfferedSpace[] {
  return raw.flatMap((item): OfferedSpace[] => {
    if (typeof item !== 'object' || item === null) return []
    const { barbero, inicio, fin } = item as Record<string, unknown>
    return typeof barbero === 'string' && typeof inicio === 'string' && typeof fin === 'string'
      ? [{ barberId: barbero, start: inicio, end: fin }]
      : []
  })
}

/**
 * Registers the reservation of a person without an account (RF-12). The shop travels in the route, never in
 * the body (RD-18); the answer is the reservation, already confirmed (RE-07). It throws the ApiError of the client.
 */
export async function registerAssistedReservation(
  barberia: string,
  input: AssistedBookingInput,
): Promise<AssistedReservation> {
  const { data } = await api.POST('/barberias/{barberia}/reservas/asistida', {
    params: { path: { barberia } },
    body: input,
  })
  return data!.data
}

/**
 * The search of assisted clients of the shop (RF-12, CL-06): it asks `delay` ms after the last key and drops the
 * answer of an older question. Without text it asks nothing (the first people of the shop are not listed until
 * the owner types), so the list starts empty.
 */
export function useAssistedClientSearch(shop: () => string | null, term: Ref<string>) {
  const results = ref<AssistedClient[]>([])
  const status = ref<SearchStatus>('idle')
  const error = ref<ApiError | null>(null)
  /** The text the current `results` answer. */
  const searched = ref('')
  const attempt = ref(0)

  let timer: ReturnType<typeof setTimeout> | undefined
  let generation = 0

  async function run(barberia: string, text: string): Promise<void> {
    const current = ++generation
    status.value = 'loading'
    error.value = null
    try {
      const { data } = await api.GET('/barberias/{barberia}/clientes-asistidos', {
        params: { path: { barberia }, query: { busqueda: text } },
      })
      if (current !== generation) return
      results.value = data!.data
      searched.value = text
      status.value = 'ready'
    } catch (caught) {
      if (current !== generation) return
      error.value = isApiError(caught) ? caught : networkError(caught)
      status.value = 'error'
    }
  }

  watch(
    () => [shop(), term.value.trim(), attempt.value] as const,
    ([barberia, text]) => {
      clearTimeout(timer)
      if (barberia === null || text === '') {
        generation++
        results.value = []
        searched.value = ''
        error.value = null
        status.value = 'idle'
        return
      }
      status.value = 'loading'
      timer = setTimeout(() => void run(barberia, text), SEARCH_DELAY_MS)
    },
    { immediate: true },
  )

  onBeforeUnmount(() => {
    clearTimeout(timer)
    generation++
  })

  function retry(): void {
    attempt.value += 1
  }

  return { results, status, error, searched, retry }
}
