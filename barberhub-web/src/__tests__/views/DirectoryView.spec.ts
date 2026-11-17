import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import DirectoryView from '@/views/DirectoryView.vue'
import { routes } from '@/router'
import { SEARCH_DELAY_MS } from '@/composables/useDirectory'
import { SESSION_STORAGE_KEY } from '@/stores/session'
import {
  CORTE_FINO,
  FADE_LAB,
  PURA_NAVAJA,
  directoryHandler,
  directoryPage,
  searchParamsOf,
} from '../helpers/directory'
import { mockFetch, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'

const ALL = [PURA_NAVAJA, FADE_LAB, CORTE_FINO]

beforeEach(() => {
  sessionStorage.setItem(
    SESSION_STORAGE_KEY,
    JSON.stringify({ token: 'tok', person: { email: 'laura.brenes@correo.cr' } }),
  )
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

/** Only the timers: promises and `flushPromises` keep working. */
function fakeTimers(): void {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
}

async function typeAndWait(
  wrapper: Awaited<ReturnType<typeof mountView>>['wrapper'],
  text: string,
) {
  await wrapper.get('input[role="searchbox"]').setValue(text)
  await vi.advanceTimersByTimeAsync(SEARCH_DELAY_MS)
  await flushPromises()
}

describe('route /barberias', () => {
  it('needs a session and loads its view lazily', () => {
    const route = routes.find((item) => item.name === 'directory')

    expect(route?.path).toBe('/barberias')
    expect(route?.meta?.requiresAuth).toBe(true)
    expect(typeof route?.component).toBe('function')
  })

  it('without a session goes to the login with the way back', async () => {
    sessionStorage.clear()
    const { router } = await mountView(DirectoryView, '/barberias')

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/barberias')
  })
})

describe('DirectoryView — states', () => {
  it('loading: skeleton, aria-busy and the field disabled', async () => {
    mockFetch(() => new Promise<Response>(() => {}))
    const { wrapper } = await mountView(DirectoryView, '/barberias')

    expect(wrapper.get('h1').text()).toBe('Barberías')
    expect(wrapper.get('main').attributes('aria-busy')).toBe('true')
    expect(wrapper.get('[data-testid="directory-skeleton"]').attributes('aria-hidden')).toBe('true')
    expect(wrapper.get('input[role="searchbox"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('a[href^="/b/"]').exists()).toBe(false)
  })

  it('list: one card per shop with name and city, no address, no count without a search', async () => {
    const fetchMock = mockFetch(directoryHandler(ALL))
    const { wrapper } = await mountView(DirectoryView, '/barberias')
    await flushPromises()

    const cards = wrapper.findAll('main a[href^="/b/"]')
    expect(cards.map((card) => card.attributes('href'))).toEqual([
      '/b/pura-navaja',
      '/b/fade-lab',
      '/b/corte-fino',
    ])
    expect(cards[0]!.text()).toContain('Pura Navaja')
    expect(cards[0]!.text()).toContain('San José')
    // No logo yet (#46): the monogram takes its place.
    expect(cards[0]!.text()).toContain('PN')
    expect(wrapper.get('main').attributes('aria-busy')).toBeUndefined()
    expect(wrapper.get('[role="status"]').text()).toBe('')
    // The biggest page the API accepts.
    const first = searchParamsOf(fetchMock)[0]!
    expect(first.get('por_pagina')).toBe('50')
    expect(first.has('buscar')).toBe(false)
  })

  it('general empty: message, no field and no cards', async () => {
    mockFetch(directoryHandler([]))
    const { wrapper } = await mountView(DirectoryView, '/barberias')
    await flushPromises()

    expect(wrapper.text()).toContain('Aún no hay barberías disponibles.')
    expect(wrapper.text()).toContain('Cuando haya una disponible, aparece aquí.')
    expect(wrapper.find('input[role="searchbox"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Borrar búsqueda')
  })

  it('error: alert with «Reintentar» that loads the list again', async () => {
    let online = false
    mockFetch((request) => (online ? directoryHandler(ALL)(request) : problem(500)))
    const { wrapper } = await mountView(DirectoryView, '/barberias')
    await flushPromises()

    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toContain('No pudimos cargar las barberías.')
    expect(alert.classes()).toContain('bg-danger-surface')
    expect(wrapper.find('a[href^="/b/"]').exists()).toBe(false)

    online = true
    await alert.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.findAll('main a[href^="/b/"]')).toHaveLength(3)
  })

  it('network failure is also the error state', async () => {
    mockFetch(() => Promise.reject(new TypeError('Failed to fetch')))
    const { wrapper } = await mountView(DirectoryView, '/barberias')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar las barberías.')
  })
})

describe('DirectoryView — search', () => {
  it('waits for the delay, asks the API with `buscar` and takes the count from `meta`', async () => {
    fakeTimers()
    const fetchMock = mockFetch((request) => {
      const term = new URL(request.url).searchParams.get('buscar')
      return term === 'ca' ? directoryPage([CORTE_FINO], { total: 1 }) : directoryPage(ALL)
    })
    const { wrapper } = await mountView(DirectoryView, '/barberias')
    await flushPromises()
    expect(fetchMock).toHaveBeenCalledTimes(1)

    await wrapper.get('input[role="searchbox"]').setValue('ca')
    await vi.advanceTimersByTimeAsync(SEARCH_DELAY_MS - 1)
    // Still inside the delay: nothing asked yet.
    expect(fetchMock).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(1)
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(searchParamsOf(fetchMock)[1]!.get('buscar')).toBe('ca')
    expect(wrapper.findAll('main a[href^="/b/"]')).toHaveLength(1)
    expect(wrapper.get('[role="status"]').text()).toBe('1 barbería')
  })

  it('typing fast makes a single request with the final text', async () => {
    fakeTimers()
    const fetchMock = mockFetch(directoryHandler(ALL))
    const { wrapper } = await mountView(DirectoryView, '/barberias')
    await flushPromises()

    const input = wrapper.get('input[role="searchbox"]')
    await input.setValue('c')
    await vi.advanceTimersByTimeAsync(100)
    await input.setValue('co')
    await vi.advanceTimersByTimeAsync(100)
    await input.setValue('cor')
    await vi.advanceTimersByTimeAsync(SEARCH_DELAY_MS)
    await flushPromises()

    const asked = searchParamsOf(fetchMock)
    expect(asked).toHaveLength(2)
    expect(asked[1]!.get('buscar')).toBe('cor')
  })

  it('counts several matches with the total from `meta`', async () => {
    fakeTimers()
    mockFetch((request) =>
      new URL(request.url).searchParams.has('buscar')
        ? directoryPage([PURA_NAVAJA, FADE_LAB], { total: 2 })
        : directoryPage(ALL),
    )
    const { wrapper } = await mountView(DirectoryView, '/barberias')
    await flushPromises()
    await typeAndWait(wrapper, 'a')

    expect(wrapper.get('[role="status"]').text()).toBe('2 barberías')
  })

  it('keeps the field enabled while a search loads, so the focus is not lost', async () => {
    fakeTimers()
    let release: (value: Response) => void = () => {}
    mockFetch((request) =>
      new URL(request.url).searchParams.has('buscar')
        ? new Promise<Response>((resolve) => (release = resolve))
        : directoryPage(ALL),
    )
    const { wrapper } = await mountView(DirectoryView, '/barberias')
    await flushPromises()
    await typeAndWait(wrapper, 'ca')

    expect(wrapper.find('[data-testid="directory-skeleton"]').exists()).toBe(true)
    expect(wrapper.get('input[role="searchbox"]').attributes('disabled')).toBeUndefined()

    release(directoryPage([CORTE_FINO]))
    await flushPromises()
    expect(wrapper.find('[data-testid="directory-skeleton"]').exists()).toBe(false)
  })

  it('drops an old answer that arrives after a newer one', async () => {
    fakeTimers()
    const pending = new Map<string, (value: Response) => void>()
    mockFetch((request) => {
      const term = new URL(request.url).searchParams.get('buscar')
      if (term === null) return directoryPage(ALL)
      return new Promise<Response>((resolve) => pending.set(term, resolve))
    })
    const { wrapper } = await mountView(DirectoryView, '/barberias')
    await flushPromises()

    await typeAndWait(wrapper, 'fa')
    await typeAndWait(wrapper, 'co')
    // The newest answers first, then the old one arrives late.
    pending.get('co')!(directoryPage([CORTE_FINO]))
    await flushPromises()
    pending.get('fa')!(directoryPage([FADE_LAB]))
    await flushPromises()

    const cards = wrapper.findAll('main a[href^="/b/"]')
    expect(cards.map((card) => card.attributes('href'))).toEqual(['/b/corte-fino'])
  })

  it('search empty: names the text, offers «Borrar búsqueda» and it shows every shop again', async () => {
    fakeTimers()
    const fetchMock = mockFetch(directoryHandler(ALL))
    const { wrapper } = await mountView(DirectoryView, '/barberias')
    await flushPromises()
    await typeAndWait(wrapper, 'Limón')

    expect(wrapper.text()).toContain('No encontramos barberías con “Limón”.')
    expect(wrapper.text()).toContain('Revise el nombre o la ciudad')
    expect(wrapper.find('a[href^="/b/"]').exists()).toBe(false)

    const clear = wrapper.findAll('button').find((button) => button.text() === 'Borrar búsqueda')!
    await clear.trigger('click')
    await flushPromises()

    expect((wrapper.get('input[role="searchbox"]').element as HTMLInputElement).value).toBe('')
    expect(wrapper.findAll('main a[href^="/b/"]')).toHaveLength(3)
    // Clearing does not wait for the delay and does not repeat the request.
    const asked = searchParamsOf(fetchMock)
    expect(asked[asked.length - 1]!.has('buscar')).toBe(false)
    expect(asked).toHaveLength(3)
  })

  it('the clear button in the field empties the text, shows every shop and keeps the focus', async () => {
    fakeTimers()
    mockFetch(directoryHandler(ALL))
    const { wrapper } = await mountView(DirectoryView, '/barberias')
    await flushPromises()
    await typeAndWait(wrapper, 'fade')
    expect(wrapper.findAll('main a[href^="/b/"]')).toHaveLength(1)

    await wrapper.get('button[aria-label="Borrar búsqueda"]').trigger('click')
    await flushPromises()

    expect(wrapper.findAll('main a[href^="/b/"]')).toHaveLength(3)
    expect(wrapper.find('button[aria-label="Borrar búsqueda"]').exists()).toBe(false)
    expect(document.activeElement).toBe(wrapper.get('input[role="searchbox"]').element)
  })

  it('error during a search keeps the field and retries the same text', async () => {
    fakeTimers()
    let failing = false
    const fetchMock = mockFetch((request) =>
      failing && new URL(request.url).searchParams.has('buscar')
        ? problem(500)
        : directoryHandler(ALL)(request),
    )
    const { wrapper } = await mountView(DirectoryView, '/barberias')
    await flushPromises()
    failing = true
    await typeAndWait(wrapper, 'fade')

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.find('input[role="searchbox"]').exists()).toBe(true)

    failing = false
    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()

    const asked = searchParamsOf(fetchMock)
    expect(asked[asked.length - 1]!.get('buscar')).toBe('fade')
    expect(wrapper.findAll('main a[href^="/b/"]')).toHaveLength(1)
  })
})

describe('DirectoryView — more pages', () => {
  it('has no «Ver más» when everything fits in one page', async () => {
    mockFetch(directoryHandler(ALL))
    const { wrapper } = await mountView(DirectoryView, '/barberias')
    await flushPromises()

    expect(wrapper.text()).not.toContain('Ver más')
  })

  it('«Ver más» asks the next page and appends it, then disappears on the last one', async () => {
    const fetchMock = mockFetch((request) => {
      const page = new URL(request.url).searchParams.get('pagina')
      return page === '2'
        ? directoryPage([CORTE_FINO], { pagina: 2, total: 3, ultima_pagina: 2 })
        : directoryPage([PURA_NAVAJA, FADE_LAB], { pagina: 1, total: 3, ultima_pagina: 2 })
    })
    const { wrapper } = await mountView(DirectoryView, '/barberias')
    await flushPromises()
    expect(wrapper.findAll('main a[href^="/b/"]')).toHaveLength(2)

    const more = wrapper.findAll('button').find((button) => button.text() === 'Ver más')!
    await more.trigger('click')
    await flushPromises()

    expect(searchParamsOf(fetchMock)[1]!.get('pagina')).toBe('2')
    expect(wrapper.findAll('main a[href^="/b/"]')).toHaveLength(3)
    expect(wrapper.text()).not.toContain('Ver más')
  })

  it('a failed «Ver más» keeps the list and says so', async () => {
    mockFetch((request) =>
      new URL(request.url).searchParams.get('pagina') === '2'
        ? problem(500)
        : directoryPage([PURA_NAVAJA], { pagina: 1, total: 2, ultima_pagina: 2 }),
    )
    const { wrapper } = await mountView(DirectoryView, '/barberias')
    await flushPromises()
    await wrapper
      .findAll('button')
      .find((button) => button.text() === 'Ver más')!
      .trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar más barberías.')
    expect(wrapper.findAll('main a[href^="/b/"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Ver más')
  })
})

describe('DirectoryView — keyboard and access', () => {
  it('the cards are real links, reachable in order with the keyboard', async () => {
    mockFetch(directoryHandler(ALL))
    const { wrapper } = await mountView(DirectoryView, '/barberias')
    await flushPromises()

    for (const card of wrapper.findAll('main a[href^="/b/"]')) {
      expect(card.element.tagName).toBe('A')
      expect(card.attributes('tabindex')).toBeUndefined()
    }
  })

  it('the card goes to the shop when followed', async () => {
    mockFetch(directoryHandler(ALL))
    const { wrapper, router } = await mountView(DirectoryView, '/barberias')
    await flushPromises()

    await wrapper.get('a[href="/b/fade-lab"]').trigger('click')

    // The shop view loads lazily, so the navigation ends a moment later.
    await vi.waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/b/fade-lab'))
  })

  it('the search field has a name and the screen has one h1', async () => {
    mockFetch(directoryHandler(ALL))
    const { wrapper } = await mountView(DirectoryView, '/barberias')
    await flushPromises()

    expect(wrapper.get('[role="search"]').exists()).toBe(true)
    expect(wrapper.get('input[role="searchbox"]').attributes('aria-label')).toBe(
      'Buscar por nombre o ciudad',
    )
    expect(wrapper.findAll('h1')).toHaveLength(1)
  })
})
