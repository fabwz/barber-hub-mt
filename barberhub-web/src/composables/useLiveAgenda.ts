import { onScopeDispose, ref, watch } from 'vue'

import { openRealtime, type Realtime } from '@/api/realtime'
import { dateOf } from '@/composables/format'
import { useSessionStore } from '@/stores/session'
import type { StaffShop } from '@/stores/activeShop'

/**
 * What the indicator of the agenda shows. `off` and `connecting` show nothing: there is no live
 * agenda (no Reverb, no channel for this person) or it has not been reached yet.
 */
export type LiveState = 'off' | 'connecting' | 'live' | 'reconnecting' | 'syncing' | 'rejected'

/** The event `.reserva.actualizada` (ADR 0012): only identifiers, dates and states, never personal data. */
export interface LiveReservationEvent {
  reserva: string
  colaborador: string
  estado: string
  inicio: string
  fin: string
  cambio: string
  colaborador_anterior?: string
  inicio_anterior?: string
  ocurrio_en: string
}

/** Which reservations changed, for the panel that has one open; `all` after a resync. */
export interface AgendaChanges {
  seq: number
  ids: readonly string[] | 'all'
}

/** Events that arrive together are read as one: a single request for the day, not one per event. */
export const LIVE_BATCH_MS = 300

function isLiveEvent(value: unknown): value is LiveReservationEvent {
  if (typeof value !== 'object' || value === null) return false
  const event = value as Record<string, unknown>
  return (
    typeof event.reserva === 'string' &&
    typeof event.inicio === 'string' &&
    typeof event.ocurrio_en === 'string' &&
    !Number.isNaN(Date.parse(event.ocurrio_en))
  )
}

/** The private channel (without `private-`) of the person in a shop: the owner's agenda or the collaborator's own. */
export function liveChannelOf(shop: StaffShop | null): string | null {
  if (shop === null) return null
  if (shop.role === 'dueno') return `barberia.${shop.id}.agenda`
  return shop.collaborator === null
    ? null
    : `barberia.${shop.id}.colaboradores.${shop.collaborator}`
}

/** Whether a change touches a day: where the reservation is now or where it was before a reschedule. */
export function touchesDay(event: LiveReservationEvent, day: string): boolean {
  return (
    dateOf(event.inicio) === day ||
    (event.inicio_anterior !== undefined && dateOf(event.inicio_anterior) === day)
  )
}

interface Options {
  shop: () => StaffShop | null
  /** The day on screen, «AAAA-MM-DD». */
  day: () => string
  /** Reads the day again without clearing the list. */
  refresh: () => Promise<void>
}

/**
 * The live agenda (RF-36, #113): subscribes to the private channel of the person, reads the day
 * again when an event touches it and keeps the state of the connection for the indicator. The
 * event is only a signal: the API still decides what each role sees.
 *
 * Reverb does not repeat what was missed, so every (re)subscription and every return to the tab
 * reads the day again. An event older than the last one a read already covered (`ocurrio_en`
 * against `ocurrio_en`, the same clock) is dropped.
 */
export function useLiveAgenda(options: Options) {
  const session = useSessionStore()
  const state = ref<LiveState>('off')
  const changes = ref<AgendaChanges | null>(null)

  let realtime: Realtime | null = null
  let channel: string | null = null
  let unbindConnection: (() => void) | null = null
  let everLive = false
  let generation = 0
  let seq = 0
  let timer: ReturnType<typeof setTimeout> | undefined
  /** `ocurrio_en` of the newest event that a finished read already covered. */
  let covered = Number.NEGATIVE_INFINITY
  let pending: LiveReservationEvent[] = []

  function announce(ids: readonly string[] | 'all'): void {
    seq += 1
    changes.value = { seq, ids }
  }

  function flush(): void {
    timer = undefined
    const batch = pending
    pending = []
    if (batch.length === 0) return
    const current = generation
    const day = options.day()
    const newest = Math.max(...batch.map((event) => Date.parse(event.ocurrio_en)))
    announce(batch.map((event) => event.reserva))
    if (!batch.some((event) => touchesDay(event, day))) return
    void options.refresh().then(() => {
      if (current === generation) covered = Math.max(covered, newest)
    })
  }

  function onEvent(payload: unknown): void {
    if (!isLiveEvent(payload)) return
    if (Date.parse(payload.ocurrio_en) < covered) return
    pending.push(payload)
    timer ??= setTimeout(flush, LIVE_BATCH_MS)
  }

  /** Reads the day again and tells the open panel to do the same. */
  async function resync(): Promise<void> {
    announce('all')
    await options.refresh()
  }

  function onSubscribed(): void {
    const current = generation
    if (!everLive) {
      everLive = true
      state.value = 'live'
      // The day was read before the subscription existed: whatever changed in between is read now.
      void resync()
      return
    }
    state.value = 'syncing'
    void resync().then(() => {
      if (current === generation && state.value === 'syncing') state.value = 'live'
    })
  }

  function onConnection(status: string): void {
    if (state.value === 'rejected' || state.value === 'off') return
    if (status === 'connected') return
    // Before the first subscription the screen only waits; afterwards the data may be old.
    if (everLive || status === 'failed') state.value = 'reconnecting'
  }

  function onRejected(): void {
    state.value = 'rejected'
  }

  function onVisible(): void {
    if (document.visibilityState === 'visible' && state.value !== 'off') {
      void resync()
    }
  }

  function close(): void {
    generation += 1
    clearTimeout(timer)
    timer = undefined
    pending = []
    document.removeEventListener('visibilitychange', onVisible)
    unbindConnection?.()
    unbindConnection = null
    if (realtime !== null) {
      if (channel !== null) realtime.leave(channel)
      realtime.close()
    }
    realtime = null
    channel = null
    everLive = false
    covered = Number.NEGATIVE_INFINITY
    state.value = 'off'
  }

  function open(name: string): void {
    const connection = openRealtime(() => ({ Authorization: `Bearer ${session.token ?? ''}` }))
    if (connection === null) return
    realtime = connection
    channel = name
    state.value = 'connecting'
    unbindConnection = connection.onConnection(onConnection)
    document.addEventListener('visibilitychange', onVisible)
    connection.listen(name, { onEvent, onSubscribed, onRejected })
  }

  watch(
    () => [session.isAuthenticated, liveChannelOf(options.shop())] as const,
    ([authenticated, name]) => {
      close()
      if (authenticated && name !== null) open(name)
    },
    { immediate: true },
  )

  onScopeDispose(close)

  return { state, changes }
}
