import { computed, onScopeDispose, ref } from 'vue'

import { api, type components } from '@/api'
import { statusKeyOf } from '@/composables/useMyBookings'

export type AgendaReservation = components['schemas']['ReservaDeAgenda']

/** Largest page the API allows. */
const PAGE_SIZE = 50
/** Safety stop: 20 pages of 50 reservations in one day. */
const MAX_PAGES = 20

/** The status filter of the screen; «Cancelada» joins both cancellations, so it is filtered here. */
export const STATUS_FILTERS = [
  { value: 'temporal', label: 'Pendiente de pago' },
  { value: 'confirmada', label: 'Confirmada' },
  { value: 'completada', label: 'Atendida' },
  { value: 'ausente', label: 'Ausente' },
  { value: 'cancelada', label: 'Cancelada' },
] as const

export type StatusFilter = (typeof STATUS_FILTERS)[number]['value']

export function isStatusFilter(value: unknown): value is StatusFilter {
  return STATUS_FILTERS.some((filter) => filter.value === value)
}

export interface AgendaFilters {
  status: StatusFilter | null
  collaborator: string | null
}

/** «Expirada» never shows in the agenda: the space is already free. */
export function isShownInAgenda(item: AgendaReservation): boolean {
  return statusKeyOf(item.estado) !== 'expired'
}

function matchesStatus(item: AgendaReservation, status: StatusFilter): boolean {
  if (status === 'cancelada') {
    return item.estado === 'cancelada_por_cliente' || item.estado === 'cancelada_por_barberia'
  }
  return item.estado === status
}

/** The reservations of the day without the expired ones, after the filters of the screen. */
export function filterAgenda(
  items: AgendaReservation[],
  filters: AgendaFilters,
): AgendaReservation[] {
  return items.filter(
    (item) =>
      isShownInAgenda(item) &&
      (filters.status === null || matchesStatus(item, filters.status)) &&
      (filters.collaborator === null || item.barbero === filters.collaborator),
  )
}

export interface CollaboratorOption {
  id: string
  name: string
}

/** The collaborators with a reservation that day, by name: the only ones a filter can show something for. */
export function collaboratorOptions(items: AgendaReservation[]): CollaboratorOption[] {
  const seen = new Map<string, string>()
  for (const item of items.filter(isShownInAgenda)) seen.set(item.barbero, item.barbero_nombre)
  return [...seen].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name))
}

/**
 * Where the mark of the current time goes in a day list sorted by start: before the first
 * reservation that has not started, or at the end when every one has. `now` enters as a parameter.
 */
export function currentTimeIndex(items: AgendaReservation[], now: number): number {
  const index = items.findIndex((item) => Date.parse(item.inicio) > now)
  return index === -1 ? items.length : index
}

export type AgendaStatus = 'loading' | 'ready' | 'error'

/**
 * The whole day of the agenda (RF-13): every page of `GET /reservas` with `dia`, always. The filters
 * are applied by the screen on what is loaded, so changing one does not ask the API again.
 */
export function useStaffAgenda() {
  const items = ref<AgendaReservation[]>([])
  const status = ref<AgendaStatus>('loading')
  // Only the last call may write: an older one that ends later is dropped.
  let call = 0

  /** `quiet` keeps what is on screen while it refreshes (after an action in the panel). */
  async function load(shopId: string, day: string, quiet = false): Promise<void> {
    const current = ++call
    if (!quiet) status.value = 'loading'
    try {
      const all: AgendaReservation[] = []
      let page = 1
      let last = 1
      do {
        const { data } = await api.GET('/barberias/{barberia}/reservas', {
          params: {
            path: { barberia: shopId },
            query: { dia: day, pagina: page, por_pagina: PAGE_SIZE },
          },
        })
        all.push(...data!.data)
        last = data!.meta.ultima_pagina
        page += 1
      } while (page <= last && page <= MAX_PAGES)
      if (current !== call) return
      items.value = all
      status.value = 'ready'
    } catch {
      if (current !== call) return
      if (!quiet) status.value = 'error'
    }
  }

  onScopeDispose(() => {
    call += 1
  })

  const options = computed(() => collaboratorOptions(items.value))

  return { items, status, options, load }
}
