import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

import { userChannelOf, useOfferNotices } from '@/composables/useOfferNotices'
import { useOffersStore } from '@/stores/offers'
import { useSessionStore } from '@/stores/session'
import { installFakeEcho, resetFakeEcho, type FakeEcho } from '../helpers/echo'
import { problem } from '../helpers/http'
import {
  BASE,
  OFFER_ID,
  OTHER_USER_ID,
  USER_ID,
  closed,
  created,
  mockOffersApi,
  offer,
  signInAs,
} from '../helpers/offers'

const host = defineComponent({
  setup() {
    useOfferNotices()
    return () => h('p')
  },
})

let echo: FakeEcho
const mounted: Array<{ unmount: () => void }> = []

beforeEach(() => {
  setActivePinia(createPinia())
  signInAs()
  echo = installFakeEcho()
})
afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount())
  resetFakeEcho()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  sessionStorage.clear()
})

/** Mounts the pop-up logic with a session that already knows its account id. */
async function open(
  api: Parameters<typeof mockOffersApi>[0] = {},
  userId: string | null = USER_ID,
) {
  const mocked = mockOffersApi({ offers: () => [offer()], ...api })
  const session = useSessionStore()
  if (userId !== null) session.setUserId(userId)
  const wrapper = mount(host)
  mounted.push(wrapper)
  await flushPromises()
  return { wrapper, session, store: useOffersStore(), ...mocked }
}

/** The channel accepted the subscription and the first read of the offers is done. */
async function goLive() {
  echo.subscribe()
  await flushPromises()
}

describe('the subscription', () => {
  it('listens to the private channel of the account with the bearer of the session', async () => {
    await open()

    expect(echo.subscriptions).toEqual([userChannelOf(USER_ID)])
    expect(userChannelOf(USER_ID)).toBe(`usuario.${USER_ID}`)
    expect(echo.authHeaders!()).toEqual({ Authorization: 'Bearer tok' })
    const events = echo.channels[0]!.events
    expect([...events.keys()].sort()).toEqual(['.oferta.cerrada', '.oferta.creada'])
  })

  it('reads the account id from the API when the session was restored without it', async () => {
    const { requests, session } = await open({}, null)

    expect(requests).toContain('GET /api/v1/cuenta')
    expect(session.userId).toBe(USER_ID)
    expect(echo.subscriptions).toEqual([userChannelOf(USER_ID)])
  })

  it('does not subscribe without a session', async () => {
    sessionStorage.clear()
    setActivePinia(createPinia())
    await open({}, null)

    expect(echo.subscriptions).toEqual([])
  })

  it('does nothing when the Reverb variables are not set', async () => {
    resetFakeEcho()
    const { store } = await open()

    expect(echo.subscriptions).toEqual([])
    expect(store.current).toBeNull()
  })

  it('a refusal of the channel (403 or 404) is silent and the offers can still be read', async () => {
    const { store } = await open()

    echo.reject(403)
    echo.reject(404)
    await flushPromises()
    await store.resync()

    expect(store.current?.offer.id).toBe(OFFER_ID)
  })
})

describe('the events', () => {
  it('an offer that arrives is read from the API and shown', async () => {
    const { store, requests } = await open({ offers: () => [offer()] })

    echo.emitEvent('.oferta.creada', created())
    await flushPromises()

    expect(requests).toContain(`GET ${BASE}/mis-ofertas`)
    expect(store.current?.offer.id).toBe(OFFER_ID)
  })

  it('the closing of an offer is applied, and the same event again changes nothing', async () => {
    const { store } = await open()
    echo.emitEvent('.oferta.creada', created())
    await flushPromises()

    echo.emitEvent('.oferta.cerrada', closed('vencida'))
    echo.emitEvent('.oferta.cerrada', closed('vencida'))
    await flushPromises()

    expect(store.offers).toHaveLength(1)
    expect(store.current?.reason).toBe('vencida')
  })

  it('an event of another name is ignored', async () => {
    const { store, requests } = await open()
    const before = requests.length

    echo.emit({ reserva: 'r1' })
    await flushPromises()

    expect(requests).toHaveLength(before)
    expect(store.current).toBeNull()
  })
})

describe('reading again', () => {
  it('reads the offers on every (re)subscription: Reverb does not repeat what was missed', async () => {
    const { store, requests } = await open()

    await goLive()
    expect(store.current?.offer.id).toBe(OFFER_ID)
    const first = requests.filter((item) => item.endsWith('/mis-ofertas')).length

    await goLive()
    expect(requests.filter((item) => item.endsWith('/mis-ofertas')).length).toBe(first + 1)
  })

  it('reads them again when the person returns to the tab, not when it is hidden', async () => {
    const { requests } = await open()
    await goLive()
    const before = requests.filter((item) => item.endsWith('/mis-ofertas')).length

    const visibility = vi.spyOn(document, 'visibilityState', 'get')
    visibility.mockReturnValue('hidden')
    document.dispatchEvent(new Event('visibilitychange'))
    await flushPromises()
    expect(requests.filter((item) => item.endsWith('/mis-ofertas')).length).toBe(before)

    visibility.mockReturnValue('visible')
    document.dispatchEvent(new Event('visibilitychange'))
    await flushPromises()
    expect(requests.filter((item) => item.endsWith('/mis-ofertas')).length).toBe(before + 1)
  })
})

describe('the end of the session and the change of account', () => {
  it('leaves the channel, disconnects Echo and empties the store when the session ends', async () => {
    const { store, session } = await open()
    await goLive()
    expect(store.current).not.toBeNull()

    session.clear()
    await flushPromises()

    expect(echo.left).toEqual([userChannelOf(USER_ID)])
    expect(echo.disconnected).toBe(true)
    expect(echo.listening).toBe(0)
    expect(store.offers).toEqual([])
    expect(store.shops).toEqual([])
  })

  it('a 401 closes the session and with it the subscription', async () => {
    const { store, session } = await open({
      offers: () => [offer()],
    })
    await goLive()
    mockOffersApi({ offers: () => problem(401, { type: 'no-autenticado' }) })

    session.clear()
    await flushPromises()

    expect(echo.disconnected).toBe(true)
    expect(store.current).toBeNull()
  })

  it('another account leaves the old channel, empties the store and subscribes to its own', async () => {
    const { store, session } = await open()
    await goLive()
    expect(store.current).not.toBeNull()

    session.setUserId(OTHER_USER_ID)
    await flushPromises()

    expect(echo.left).toEqual([userChannelOf(USER_ID)])
    expect(echo.subscriptions).toEqual([userChannelOf(USER_ID), userChannelOf(OTHER_USER_ID)])
    // What the other person had is gone; events of the old channel cannot reach the store.
    expect(store.offers).toEqual([])
  })

  it('events that arrive after the account changed are dropped', async () => {
    const { store, session } = await open()
    const stale = echo.channels[0]!
    session.setUserId(OTHER_USER_ID)
    await flushPromises()

    stale.events.get('.oferta.creada')?.forEach((callback) => callback(created()))
    await flushPromises()

    expect(store.offers).toEqual([])
  })

  it('closes the connection when the owner goes away', async () => {
    const { wrapper } = await open()

    wrapper.unmount()

    expect(echo.left).toEqual([userChannelOf(USER_ID)])
    expect(echo.disconnected).toBe(true)
  })
})
