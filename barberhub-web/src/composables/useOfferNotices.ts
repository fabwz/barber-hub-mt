import { onScopeDispose, watch } from 'vue'

import { openRealtime, type Realtime } from '@/api/realtime'
import { useAccountStore } from '@/stores/account'
import { useOffersStore } from '@/stores/offers'
import { useSessionStore } from '@/stores/session'

/** The events of the private channel of the person (RF-35, ADR #98). */
export const OFFER_EVENTS = ['.oferta.creada', '.oferta.cerrada'] as const

/** The `id` of the pop-up, which the «Ir al aviso» link moves the focus to. */
export const OFFER_PROMPT_ID = 'oferta'

/** The name of the private channel (without `private-`) of an account. */
export function userChannelOf(userId: string): string {
  return `usuario.${userId}`
}

/**
 * The pop-up of the offers (RF-35): subscribes to the private channel of the person while there is a
 * session, hands every event to the store and reads the offers again on every (re)subscription and
 * every return to the tab, because Reverb does not repeat what was missed.
 *
 * The connection is closed and the store emptied when the session ends (logout, a 401) and also when
 * the account changes, so no subscription is left behind and one person never sees the offers of the
 * other. A refusal of the channel (403 or 404) is silent: the offers are still read from the API.
 */
export function useOfferNotices(): void {
  const session = useSessionStore()
  const account = useAccountStore()
  const offers = useOffersStore()

  let realtime: Realtime | null = null
  let channel: string | null = null
  let generation = 0

  function onVisible(): void {
    if (document.visibilityState === 'visible') void offers.resync()
  }

  function close(): void {
    generation += 1
    document.removeEventListener('visibilitychange', onVisible)
    if (realtime !== null) {
      if (channel !== null) realtime.leave(channel)
      realtime.close()
    }
    realtime = null
    channel = null
    offers.reset()
  }

  function open(name: string): void {
    const connection = openRealtime(() => ({ Authorization: `Bearer ${session.token ?? ''}` }))
    // Without the Reverb variables there is no pop-up; «Mis filas» still reads the offers.
    if (connection === null) return
    const current = generation
    realtime = connection
    channel = name
    document.addEventListener('visibilitychange', onVisible)
    connection.listen(name, {
      events: OFFER_EVENTS,
      onEvent: (payload, event) => {
        if (current === generation) void offers.handleEvent(event, payload)
      },
      onSubscribed: () => {
        if (current === generation) void offers.resync()
      },
      onRejected: () => {},
    })
  }

  watch(
    () => [session.token, session.userId] as const,
    ([token, userId]) => {
      close()
      if (token === null) return
      // The id of the account comes from `GET /cuenta`, read once per session.
      if (userId === null) void account.ensureLoaded()
      else open(userChannelOf(userId))
    },
    { immediate: true },
  )

  onScopeDispose(close)
}
