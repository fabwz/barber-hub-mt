import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { defineComponent, h } from 'vue'

import { ACTIVE_SHOP_STORAGE_KEY, useActiveShopStore } from '@/stores/activeShop'
import { connectSession, SESSION_STORAGE_KEY, useSessionStore } from '@/stores/session'
import { jsonResponse, mockFetch, problem } from '../helpers/http'
import { CLIENT_SHOP, OWNER_SHOP, SHOP_A, SHOP_B, STAFF_SHOP, staffHandler } from '../helpers/staff'

beforeEach(() => {
  sessionStorage.clear()
  setActivePinia(createPinia())
})
afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('useActiveShopStore', () => {
  it('loads the shops and keeps only owner and collaborator roles', async () => {
    mockFetch(staffHandler([OWNER_SHOP, CLIENT_SHOP, STAFF_SHOP]))
    const store = useActiveShopStore()
    expect(store.status).toBe('idle')

    await store.loadStaffShops()

    expect(store.status).toBe('ready')
    expect(store.shops).toEqual([
      { id: SHOP_A, name: 'Pura Navaja', link: 'pura-navaja', role: 'dueno' },
      { id: SHOP_B, name: 'Fade Lab', link: 'fade-lab', role: 'colaborador' },
    ])
    expect(store.active).toBeNull()
  })

  it('a customer has no staff shops and that is not an error', async () => {
    mockFetch(staffHandler([CLIENT_SHOP]))
    const store = useActiveShopStore()

    await store.loadStaffShops()

    expect(store.status).toBe('ready')
    expect(store.shops).toEqual([])
  })

  it('ends in error when the request fails', async () => {
    mockFetch(() => problem(500))
    const store = useActiveShopStore()

    await store.loadStaffShops()

    expect(store.status).toBe('error')
  })

  it('shares one request between two simultaneous loads', async () => {
    const fetchMock = mockFetch(staffHandler([OWNER_SHOP]))
    const store = useActiveShopStore()

    await Promise.all([store.loadStaffShops(), store.loadStaffShops()])

    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('select makes a shop active and stores only its id', async () => {
    mockFetch(staffHandler([OWNER_SHOP, STAFF_SHOP]))
    const store = useActiveShopStore()
    await store.loadStaffShops()

    store.select(SHOP_B)

    expect(store.active?.name).toBe('Fade Lab')
    expect(sessionStorage.getItem(ACTIVE_SHOP_STORAGE_KEY)).toBe(JSON.stringify({ id: SHOP_B }))
  })

  it('select ignores an id that is not in the list', async () => {
    mockFetch(staffHandler([OWNER_SHOP]))
    const store = useActiveShopStore()
    await store.loadStaffShops()

    store.select('otra-barberia')

    expect(store.active).toBeNull()
    expect(sessionStorage.getItem(ACTIVE_SHOP_STORAGE_KEY)).toBeNull()
  })

  it('restores the stored shop once the current list confirms it', async () => {
    sessionStorage.setItem(ACTIVE_SHOP_STORAGE_KEY, JSON.stringify({ id: SHOP_B }))
    mockFetch(staffHandler([OWNER_SHOP, STAFF_SHOP]))
    const store = useActiveShopStore()
    expect(store.active).toBeNull() // not trusted before the list arrives

    await store.loadStaffShops()

    expect(store.active?.id).toBe(SHOP_B)
  })

  it('discards a stored shop that is no longer in the list', async () => {
    sessionStorage.setItem(ACTIVE_SHOP_STORAGE_KEY, JSON.stringify({ id: SHOP_B }))
    mockFetch(staffHandler([OWNER_SHOP]))
    const store = useActiveShopStore()

    await store.loadStaffShops()

    expect(store.active).toBeNull()
    expect(sessionStorage.getItem(ACTIVE_SHOP_STORAGE_KEY)).toBeNull()
  })

  it('ignores a damaged stored value', async () => {
    sessionStorage.setItem(ACTIVE_SHOP_STORAGE_KEY, '{no es json')
    mockFetch(staffHandler([OWNER_SHOP]))
    const store = useActiveShopStore()

    await store.loadStaffShops()

    expect(store.active).toBeNull()
  })

  it('works in memory when sessionStorage throws', async () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    mockFetch(staffHandler([OWNER_SHOP]))
    const store = useActiveShopStore()
    await store.loadStaffShops()

    store.select(SHOP_A)

    expect(store.active?.id).toBe(SHOP_A)
  })

  it('clear forgets the shops and the stored choice', async () => {
    mockFetch(staffHandler([OWNER_SHOP]))
    const store = useActiveShopStore()
    await store.loadStaffShops()
    store.select(SHOP_A)

    store.clear()

    expect(store.shops).toEqual([])
    expect(store.active).toBeNull()
    expect(store.status).toBe('idle')
    expect(sessionStorage.getItem(ACTIVE_SHOP_STORAGE_KEY)).toBeNull()
  })

  it('an answer that arrives after clear is dropped', async () => {
    let release!: () => void
    mockFetch(
      () =>
        new Promise<Response>((resolve) => {
          release = () => resolve(jsonResponse(200, { data: [OWNER_SHOP] }))
        }),
    )
    const store = useActiveShopStore()
    const loading = store.loadStaffShops()
    await vi.waitFor(() => expect(release).toBeTypeOf('function'))

    store.clear()
    release()
    await loading

    expect(store.shops).toEqual([])
    expect(store.status).toBe('idle')
  })

  it('closing the session clears the active shop', async () => {
    mockFetch((request) =>
      request.method === 'DELETE'
        ? new Response(null, { status: 204 })
        : staffHandler([OWNER_SHOP])(request),
    )
    const session = useSessionStore()
    await session.login('ana@example.test', 'Secreta1!')
    const store = useActiveShopStore()
    await store.loadStaffShops()
    store.select(SHOP_A)

    await session.logout()

    expect(store.active).toBeNull()
    expect(store.status).toBe('idle')
    expect(sessionStorage.getItem(ACTIVE_SHOP_STORAGE_KEY)).toBeNull()
  })

  it('a 401 clears the session and the active shop', async () => {
    const blank = defineComponent({ render: () => h('p') })
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: blank },
        { path: '/ingresar', name: 'login', component: blank },
      ],
    })
    connectSession(router)
    const session = useSessionStore()
    const store = useActiveShopStore()
    mockFetch(staffHandler([OWNER_SHOP]))
    await session.login('ana@example.test', 'Secreta1!')
    await store.loadStaffShops()
    store.select(SHOP_A)

    mockFetch(() => problem(401, { type: 'no-autenticado' }))
    await store.loadStaffShops()
    await flushPromises()

    expect(session.isAuthenticated).toBe(false)
    expect(sessionStorage.getItem(SESSION_STORAGE_KEY)).toBeNull()
    expect(store.active).toBeNull()
    expect(store.status).toBe('idle')
    expect(sessionStorage.getItem(ACTIVE_SHOP_STORAGE_KEY)).toBeNull()
  })
})
