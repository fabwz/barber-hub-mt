import { flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { LIVE_BATCH_MS } from '@/composables/useLiveAgenda'
import { useActiveShopStore } from '@/stores/activeShop'
import { useSessionStore } from '@/stores/session'
import { signIn } from '../helpers/account'
import { DAY, NOW_ISO, agendaApi } from '../helpers/agenda'
import { installFakeEcho, liveEvent, resetFakeEcho, type FakeEcho } from '../helpers/echo'
import { mountView } from '../helpers/mountView'

const COLLABORATOR = '0190b1f0-0000-7000-8000-0000000000c1'

let echo: FakeEcho
/** Every app mounted by a test; they are unmounted after it so no listener of the document survives. */
const mounted: Array<{ unmount: () => void }> = []

beforeEach(() => {
  signIn('marco@example.test')
  // Only these are faked, so promises and the Vue scheduler keep running.
  vi.useFakeTimers({ toFake: ['Date', 'setTimeout', 'clearTimeout'] })
  vi.setSystemTime(new Date(NOW_ISO))
  echo = installFakeEcho()
})
afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount())
  resetFakeEcho()
  vi.restoreAllMocks()
  vi.useRealTimers()
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

async function open(options: Parameters<typeof agendaApi>[0] = {}, path = '') {
  const api = agendaApi(options)
  const view = await mountView(App, `/barberias/${api.shopId}/agenda${path}`)
  mounted.push(view.wrapper)
  await flushPromises()
  return { ...view, ...api }
}

/** Lets the batch window of the events pass and the request that follows finish. */
async function settle(): Promise<void> {
  await vi.advanceTimersByTimeAsync(LIVE_BATCH_MS)
  await flushPromises()
}

function dayReads(requests: string[]): number {
  return requests.filter((r) => r.startsWith('GET ') && r.includes('/reservas?')).length
}

function detailReads(requests: string[], id: string): number {
  return requests.filter((r) => r.endsWith(`/reservas/${id}`)).length
}

/** The screen is subscribed and the first sync of the subscription is done. */
async function goLive() {
  echo.subscribe()
  await flushPromises()
}

describe('live agenda: the subscription', () => {
  it('the owner listens to the agenda channel of the shop with the bearer of the session', async () => {
    const { shopId } = await open()

    expect(echo.subscriptions).toEqual([`barberia.${shopId}.agenda`])
    expect(echo.authHeaders!()).toEqual({ Authorization: 'Bearer tok' })
    expect(echo.settings).toEqual({
      key: 'local-key',
      host: 'reverb.test',
      port: 8080,
      secure: false,
    })
  })

  it('the collaborator listens only to their own channel, with the id of `/mis-barberias`', async () => {
    const { shopId } = await open({ role: 'colaborador', collaborator: COLLABORATOR })

    expect(echo.subscriptions).toEqual([`barberia.${shopId}.colaboradores.${COLLABORATOR}`])
  })

  it('a collaborator without a profile sees the agenda without «En vivo» and nothing breaks', async () => {
    const { wrapper } = await open({ role: 'colaborador', collaborator: null })

    expect(echo.subscriptions).toEqual([])
    expect(wrapper.get('h1').text()).toBe('Mi agenda')
    expect(wrapper.findAll('ul[aria-label="Reservas del día"] a')).toHaveLength(5)
    expect(wrapper.text()).not.toContain('En vivo')
    expect(wrapper.find('[data-testid="live-notice"]').exists()).toBe(false)
  })

  it('without the Reverb variables the agenda works without «En vivo»', async () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', '')

    const { wrapper } = await open()

    expect(echo.subscriptions).toEqual([])
    expect(wrapper.findAll('ul[aria-label="Reservas del día"] a')).toHaveLength(5)
    expect(wrapper.text()).not.toContain('En vivo')
  })
})

describe('live agenda: the events', () => {
  it('reads the day again when an event touches the visible day, once for events that arrive together', async () => {
    const { calls } = await open()
    await goLive()
    const before = dayReads(calls.requests)

    echo.emit(liveEvent({ reserva: 'r9' }))
    echo.emit(liveEvent({ reserva: 'r10', ocurrio_en: `${DAY}T11:21:01-06:00` }))
    await settle()

    expect(dayReads(calls.requests)).toBe(before + 1)
    expect(calls.requests.at(-1)).toContain(`dia=${DAY}`)
  })

  it('does not read the day for an event of another day', async () => {
    const { calls } = await open()
    await goLive()
    const before = dayReads(calls.requests)

    echo.emit(liveEvent({ inicio: '2026-11-06T09:00:00-06:00', fin: '2026-11-06T09:30:00-06:00' }))
    await settle()

    expect(dayReads(calls.requests)).toBe(before)
  })

  it('reads the day when a reservation left it: the previous start was on the visible day', async () => {
    const { calls } = await open()
    await goLive()
    const before = dayReads(calls.requests)

    echo.emit(
      liveEvent({
        cambio: 'reprogramada',
        inicio: '2026-11-06T09:00:00-06:00',
        fin: '2026-11-06T09:30:00-06:00',
        inicio_anterior: `${DAY}T09:00:00-06:00`,
      }),
    )
    await settle()

    expect(dayReads(calls.requests)).toBe(before + 1)
  })

  it('drops an event older than what a read already covered, and reads for a newer one', async () => {
    const { calls } = await open()
    await goLive()

    echo.emit(liveEvent({ ocurrio_en: `${DAY}T11:30:00-06:00` }))
    await settle()
    const covered = dayReads(calls.requests)

    echo.emit(liveEvent({ reserva: 'r11', ocurrio_en: `${DAY}T11:25:00-06:00` }))
    await settle()
    expect(dayReads(calls.requests)).toBe(covered)

    echo.emit(liveEvent({ reserva: 'r12', ocurrio_en: `${DAY}T11:31:00-06:00` }))
    await settle()
    expect(dayReads(calls.requests)).toBe(covered + 1)
  })

  it('ignores what is not an event of the API', async () => {
    const { calls } = await open()
    await goLive()
    const before = dayReads(calls.requests)

    echo.emit(null)
    echo.emit({ reserva: 'r1' })
    echo.emit('hola')
    await settle()

    expect(dayReads(calls.requests)).toBe(before)
  })

  it('never paints what the event carries: the rows come from the API', async () => {
    const { wrapper } = await open()
    await goLive()

    echo.emit(liveEvent({ cliente_nombre: 'Intruso Falso', precio: 99999 }))
    await settle()

    expect(wrapper.text()).not.toContain('Intruso Falso')
    expect(wrapper.text()).not.toContain('99999')
  })

  it('reads the open reservation again when the event is about it, and only then', async () => {
    const { calls } = await open({}, '/r4')
    await goLive()
    const before = detailReads(calls.requests, 'r4')

    echo.emit(liveEvent({ reserva: 'r1' }))
    await settle()
    expect(detailReads(calls.requests, 'r4')).toBe(before)

    echo.emit(
      liveEvent({ reserva: 'r4', cambio: 'cancelada', ocurrio_en: `${DAY}T11:40:00-06:00` }),
    )
    await settle()
    expect(detailReads(calls.requests, 'r4')).toBe(before + 1)
  })
})

describe('live agenda: the connection', () => {
  it('shows «En vivo» once subscribed, and nothing before', async () => {
    const { wrapper } = await open()
    expect(wrapper.text()).not.toContain('En vivo')

    await goLive()

    expect(wrapper.get('[role="status"]').text()).toContain('En vivo')
  })

  it('shows «Reconectando…» with the warning when the link drops, then «Sincronizando…» while it reads the day again', async () => {
    const { wrapper, calls } = await open()
    await goLive()

    echo.connection('connecting')
    await nextTick()
    expect(wrapper.text()).toContain('Reconectando…')
    expect(wrapper.get('[data-testid="live-notice"]').text()).toContain(
      'La agenda puede estar desactualizada',
    )
    expect(wrapper.text()).not.toContain('En vivo')

    const before = dayReads(calls.requests)
    echo.connection('connected')
    echo.subscribe()
    await nextTick()
    expect(wrapper.text()).toContain('Sincronizando…')
    expect(wrapper.find('[data-testid="live-notice"]').exists()).toBe(false)

    await flushPromises()
    expect(dayReads(calls.requests)).toBe(before + 1)
    expect(wrapper.text()).toContain('En vivo')
    expect(wrapper.text()).not.toContain('Sincronizando…')
  })

  it('while it waits for the first connection it shows no indicator', async () => {
    const { wrapper } = await open()

    echo.connection('connecting')
    await nextTick()

    expect(wrapper.text()).not.toContain('Reconectando…')
    expect(wrapper.text()).not.toContain('En vivo')
  })

  it('reads the day again when the tab becomes visible, since Reverb does not repeat what was missed', async () => {
    const { calls } = await open()
    await goLive()
    const before = dayReads(calls.requests)

    const visibility = vi.spyOn(document, 'visibilityState', 'get')
    visibility.mockReturnValue('hidden')
    document.dispatchEvent(new Event('visibilitychange'))
    await flushPromises()
    expect(dayReads(calls.requests)).toBe(before)

    visibility.mockReturnValue('visible')
    document.dispatchEvent(new Event('visibilitychange'))
    await flushPromises()
    expect(dayReads(calls.requests)).toBe(before + 1)
  })
})

describe('live agenda: a refused subscription', () => {
  it.each([403, 404])(
    'shows the agenda without «En vivo» and with the notice after a %i',
    async (status) => {
      const { wrapper } = await open()

      echo.reject(status)
      await nextTick()

      expect(wrapper.findAll('ul[aria-label="Reservas del día"] a')).toHaveLength(5)
      expect(wrapper.text()).not.toContain('En vivo')
      expect(wrapper.get('[data-testid="live-notice"]').text()).toContain('No se pudo activar')
    },
  )

  it('does not come back to «Reconectando…» after a rejection', async () => {
    const { wrapper } = await open()
    echo.reject(403)

    echo.connection('failed')
    await nextTick()

    expect(wrapper.text()).not.toContain('Reconectando…')
    expect(wrapper.get('[data-testid="live-notice"]').text()).toContain('No se pudo activar')
  })
})

describe('live agenda: the cleanup', () => {
  it('leaves the channel and closes the connection when the agenda goes away', async () => {
    const { wrapper, calls, shopId } = await open()
    await goLive()

    wrapper.unmount()

    expect(echo.left).toEqual([`barberia.${shopId}.agenda`])
    expect(echo.disconnected).toBe(true)
    expect(echo.listening).toBe(0)
    const before = dayReads(calls.requests)
    document.dispatchEvent(new Event('visibilitychange'))
    await flushPromises()
    expect(dayReads(calls.requests)).toBe(before)
  })

  it('closes the connection when the session ends', async () => {
    await open()
    await goLive()

    // Only the token goes away (the screen is not redrawn): the connection must not outlive it.
    useSessionStore().token = null
    await flushPromises()

    expect(echo.disconnected).toBe(true)
  })

  it('logging out from the agenda leaves it first: no render error, the connection is closed and nothing stays subscribed', async () => {
    const { wrapper, router } = await open({}, '/r4')
    await goLive()

    await wrapper.get('button[aria-expanded]').trigger('click')
    const logout = wrapper.findAll('button').find((button) => button.text() === 'Cerrar sesión')
    await logout!.trigger('click')
    await flushPromises()

    // An error while redrawing with the shop gone would surface as an unhandled one and fail the run.
    expect(router.currentRoute.value.path).toBe('/')
    expect(useSessionStore().isAuthenticated).toBe(false)
    expect(echo.disconnected).toBe(true)
    expect(echo.left).toHaveLength(1)
    expect(echo.listening).toBe(0)
  })

  it('the links of the agenda keep the shop of the address when the store is cleared under it', async () => {
    const { wrapper, shopId } = await open()

    useActiveShopStore().clear()
    await flushPromises()

    const hrefs = wrapper
      .findAll('ul[aria-label="Reservas del día"] a')
      .map((a) => a.attributes('href'))
    expect(hrefs.length).toBeGreaterThan(0)
    expect(hrefs.every((href) => href?.startsWith(`/barberias/${shopId}/agenda/`))).toBe(true)
  })
})
