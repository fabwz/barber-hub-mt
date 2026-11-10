import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { defineComponent, h } from 'vue'

import { installGuards, safeRedirect } from '@/router/guard'
import { useActiveShopStore } from '@/stores/activeShop'
import { useSessionStore } from '@/stores/session'
import { mockFetch } from '../helpers/http'
import { OWNER_SHOP, SHOP_A, STAFF_SHOP, staffHandler } from '../helpers/staff'

const page = defineComponent({ render: () => h('p') })
const guestMeta = { public: true, guestOnly: true }

function makeRouter() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: page },
      { path: '/privado/:id', component: page, meta: { requiresAuth: true } },
      { path: '/ingresar', name: 'login', component: page, meta: guestMeta },
      { path: '/registro', component: page, meta: guestMeta },
      { path: '/terminos', component: page, meta: { public: true } },
    ],
  })
  installGuards(router)
  return router
}

beforeEach(() => {
  sessionStorage.clear()
  setActivePinia(createPinia())
})

describe('router guard', () => {
  it('sends a protected route without session to /ingresar with the destination', async () => {
    const router = makeRouter()

    await router.push('/privado/7?tab=a')

    expect(router.currentRoute.value.path).toBe('/ingresar')
    expect(router.currentRoute.value.query.redirect).toBe('/privado/7?tab=a')
  })

  it('lets a protected route through with a session', async () => {
    useSessionStore().token = 'tok'
    const router = makeRouter()

    await router.push('/privado/7')

    expect(router.currentRoute.value.path).toBe('/privado/7')
  })

  it.each(['/ingresar', '/registro'])('with a session %s redirects to «/»', async (path) => {
    useSessionStore().token = 'tok'
    const router = makeRouter()

    await router.push(path)

    expect(router.currentRoute.value.path).toBe('/')
  })

  it('public pages are reachable with and without a session', async () => {
    const router = makeRouter()
    await router.push('/terminos')
    expect(router.currentRoute.value.path).toBe('/terminos')

    useSessionStore().token = 'tok'
    await router.push('/')
    await router.push('/terminos')
    expect(router.currentRoute.value.path).toBe('/terminos')
  })
})

describe('router guard: requiresStaffShop', () => {
  afterEach(() => vi.unstubAllGlobals())

  // Stand-in for the business panel (#78), which will be the first route to use the meta.
  function makeStaffRouter() {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: page },
        { path: '/negocios', component: page, meta: { requiresAuth: true } },
        { path: '/ingresar', name: 'login', component: page, meta: guestMeta },
        {
          path: '/panel-de-prueba',
          component: page,
          meta: { requiresAuth: true, requiresStaffShop: true },
        },
      ],
    })
    installGuards(router)
    return router
  }

  it('without an active shop sends the route to /negocios', async () => {
    useSessionStore().token = 'tok'
    mockFetch(staffHandler([OWNER_SHOP, STAFF_SHOP]))
    const router = makeStaffRouter()

    await router.push('/panel-de-prueba')

    expect(router.currentRoute.value.path).toBe('/negocios')
  })

  it('with an active shop lets the route through', async () => {
    useSessionStore().token = 'tok'
    mockFetch(staffHandler([OWNER_SHOP, STAFF_SHOP]))
    const shops = useActiveShopStore()
    await shops.loadStaffShops()
    shops.select(SHOP_A)
    const router = makeStaffRouter()

    await router.push('/panel-de-prueba')

    expect(router.currentRoute.value.path).toBe('/panel-de-prueba')
  })

  it('after a reload confirms the stored shop against the list before deciding', async () => {
    sessionStorage.setItem('barberhub.shop', JSON.stringify({ id: SHOP_A }))
    setActivePinia(createPinia()) // a new store reads the stored id
    useSessionStore().token = 'tok'
    mockFetch(staffHandler([OWNER_SHOP]))
    const router = makeStaffRouter()

    await router.push('/panel-de-prueba')

    expect(router.currentRoute.value.path).toBe('/panel-de-prueba')
  })

  it('a stored shop that is gone from the list sends the route to /negocios', async () => {
    sessionStorage.setItem('barberhub.shop', JSON.stringify({ id: SHOP_A }))
    setActivePinia(createPinia())
    useSessionStore().token = 'tok'
    mockFetch(staffHandler([STAFF_SHOP]))
    const router = makeStaffRouter()

    await router.push('/panel-de-prueba')

    expect(router.currentRoute.value.path).toBe('/negocios')
  })

  it('without a session the login comes first', async () => {
    const router = makeStaffRouter()

    await router.push('/panel-de-prueba')

    expect(router.currentRoute.value.path).toBe('/ingresar')
  })
})

describe('safeRedirect', () => {
  it.each(['/agenda', '/privado/7?tab=a', '/'])('accepts the internal path %s', (value) => {
    expect(safeRedirect(value)).toBe(value)
  })

  it.each([
    '//evil.com',
    'https://evil.com',
    'http://evil.com/x',
    '/\\evil.com',
    'javascript:alert(1)',
    'evil.com',
    '',
    '/\nhttps://evil.com',
    null,
    undefined,
    42,
  ])('rejects %s and falls back to «/»', (value) => {
    expect(safeRedirect(value)).toBe('/')
  })

  it('reads the first value of a repeated query parameter', () => {
    expect(safeRedirect(['/agenda', '//evil.com'])).toBe('/agenda')
    expect(safeRedirect(['//evil.com', '/agenda'])).toBe('/')
  })
})
