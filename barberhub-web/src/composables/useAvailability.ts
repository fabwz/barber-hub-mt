import { ref, watch } from 'vue'

import { api, isApiError, type ApiError } from '@/api'
import { networkError } from '@/api/errors'
import { addDays } from '@/composables/format'

/** A free space of one barber. `start` and `end` are the ISO 8601 strings of the API. */
export interface AvailableSlot {
  start: string
  end: string
  barberId: string
  barberName: string
}

export interface NextDay {
  /** «AAAA-MM-DD». */
  date: string
  slots: AvailableSlot[]
}

export interface KnownBarber {
  id: string
  name: string
}

/** What is known about a day: it has spaces, or it was asked and has none. */
export type DayState = 'open' | 'closed'

export interface AvailabilitySource {
  shopId: () => string | null
  serviceId: () => string | null
  /** `null` is «Sin preferencia»: the spaces of every barber that offers the service. */
  barberId: () => string | null
  date: () => string | null
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/** The contract types the groups as `unknown[]`, so they are read defensively. */
function parseGroups(raw: unknown): { barbers: KnownBarber[]; slots: AvailableSlot[] } {
  const barbers: KnownBarber[] = []
  const slots: AvailableSlot[] = []
  if (!Array.isArray(raw)) return { barbers, slots }
  for (const group of raw) {
    if (!isRecord(group) || typeof group.id !== 'string') continue
    const name = typeof group.nombre === 'string' ? group.nombre : ''
    barbers.push({ id: group.id, name })
    if (!Array.isArray(group.espacios)) continue
    for (const space of group.espacios) {
      if (!isRecord(space) || typeof space.inicio !== 'string' || typeof space.fin !== 'string') {
        continue
      }
      slots.push({
        start: space.inicio,
        end: space.fin,
        barberId: typeof space.barbero === 'string' ? space.barbero : group.id,
        barberName: name,
      })
    }
  }
  return { barbers, slots }
}

/**
 * Una sola vez por hora: con varios barberos libres a la misma hora se queda el
 * primero que entrega la API (DI-04 asigna en el servidor, que aún no lo ofrece;
 * el cliente no inventa un criterio). Ordenado por hora.
 */
export function unifySlots(slots: AvailableSlot[]): AvailableSlot[] {
  const byStart = new Map<string, AvailableSlot>()
  for (const slot of slots) {
    if (!byStart.has(slot.start)) byStart.set(slot.start, slot)
  }
  return [...byStart.values()].sort((a, b) => a.start.localeCompare(b.start))
}

/**
 * Pide la disponibilidad (RF-10) cuando cambian la barbería, el servicio, el
 * barbero o la fecha. Descarta las respuestas atrasadas: una petición vieja que
 * termina después de una nueva se ignora (y se aborta al cambiar).
 */
export function useAvailability(source: AvailabilitySource) {
  const loading = ref(false)
  const error = ref<ApiError | null>(null)
  /** Spaces of the day, one per hour (see `unifySlots`). */
  const slots = ref<AvailableSlot[]>([])
  /** Every space of every barber, with no merging; used to validate a chosen space. */
  const allSlots = ref<AvailableSlot[]>([])
  const nextDays = ref<NextDay[]>([])
  /** Barbers seen for the current service; the API has no list of its own. */
  const barbers = ref<KnownBarber[]>([])
  const days = ref<Record<string, DayState>>({})
  /** True once a response for the current inputs arrived. */
  const loaded = ref(false)
  const attempt = ref(0)

  let lastShop: string | null = null
  let lastService: string | null = null
  let lastBarber: string | null = null

  function mark(date: string, state: DayState): void {
    days.value = { ...days.value, [date]: state }
  }

  function rememberBarbers(found: KnownBarber[]): void {
    const known = new Map(barbers.value.map((barber) => [barber.id, barber]))
    for (const barber of found) known.set(barber.id, barber)
    barbers.value = [...known.values()]
  }

  watch(
    () =>
      [
        source.shopId(),
        source.serviceId(),
        source.barberId(),
        source.date(),
        attempt.value,
      ] as const,
    async ([shop, service, barber, date], _previous, onCleanup) => {
      let current = true
      const controller = new AbortController()
      onCleanup(() => {
        current = false
        controller.abort()
      })

      if (shop !== lastShop || service !== lastService) {
        barbers.value = []
        days.value = {}
      } else if (barber !== lastBarber) {
        days.value = {}
      }
      lastShop = shop
      lastService = service
      lastBarber = barber

      slots.value = []
      allSlots.value = []
      nextDays.value = []
      error.value = null
      loaded.value = false
      if (shop === null || service === null || date === null) {
        loading.value = false
        return
      }

      loading.value = true
      try {
        const { data } = await api.GET('/barberias/{barberia}/disponibilidad', {
          params: {
            path: { barberia: shop },
            query: { servicio: service, barbero: barber ?? undefined, fecha: date },
          },
          signal: controller.signal,
        })
        if (!current || !data) return

        const today = parseGroups(data.data.barberos)
        const following = data.data.dias_siguientes.flatMap((raw): NextDay[] => {
          if (!isRecord(raw) || typeof raw.fecha !== 'string') return []
          const parsed = parseGroups(raw.barberos)
          return [{ date: raw.fecha, slots: parsed.slots }]
        })
        const followingBarbers = data.data.dias_siguientes.flatMap((raw) =>
          isRecord(raw) ? parseGroups(raw.barberos).barbers : [],
        )

        allSlots.value = today.slots
        slots.value = unifySlots(today.slots)
        nextDays.value = following.map((day) => ({ date: day.date, slots: unifySlots(day.slots) }))
        rememberBarbers([...today.barbers, ...followingBarbers])

        mark(date, slots.value.length > 0 ? 'open' : 'closed')
        // The API lists the nearest days that have spaces: the ones before them have none.
        let from = date
        for (const day of following) {
          for (let d = addDays(from, 1); d < day.date; d = addDays(d, 1)) mark(d, 'closed')
          mark(day.date, 'open')
          from = day.date
        }
        loaded.value = true
      } catch (caught) {
        if (!current) return
        error.value = isApiError(caught) ? caught : networkError(caught)
      } finally {
        if (current) loading.value = false
      }
    },
    { immediate: true },
  )

  function retry(): void {
    attempt.value += 1
  }

  return { loading, error, slots, allSlots, nextDays, barbers, days, loaded, retry }
}
