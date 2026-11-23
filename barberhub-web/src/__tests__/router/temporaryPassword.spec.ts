import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { defineComponent, h } from 'vue'

import { api, setTemporaryPasswordHandler, setTokenProvider, setUnauthorizedHandler } from '@/api'
import { installGuards } from '@/router/guard'
import { useAccountStore } from '@/stores/account'
import { connectSession, useSessionStore } from '@/stores/session'
import { ACCOUNT, accountHandler, signIn, temporaryPasswordProblem } from '../helpers/account'
import { jsonResponse, mockFetch, pathOf, problem } from '../helpers/http'

const page = defineComponent({ render: () => h('p') })

function makeRouter() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: page },
      { path: '/terminos', component: page, meta: { public: true } },
      { path: '/privado', component: page, meta: { requiresAuth: true } },
      { path: '/otro', component: page, meta: { requiresAuth: true } },
      {
        path: '/ingresar',
        name: 'login',
        component: page,
        meta: { public: true, guestOnly: true },
      },
      {
        path: '/cambiar-contrasena',
        name: 'password-change',
        component: page,
        meta: { requiresAuth: true },
      },
    ],
  })
  installGuards(router)
  return router
}

const TEMPORARY = { ...ACCOUNT, contrasena_temporal: true }

beforeEach(() => {
  sessionStorage.clear()
  setActivePinia(createPinia())
})
afterEach(() => {
  vi.unstubAllGlobals()
  setTokenProvider(() => null)
  setUnauthorizedHandler(() => {})
  setTemporaryPasswordHandler(() => {})
})

describe('router guard: temporary password (UP-04)', () => {
  it('a temporary password sends every protected route to the mandatory change, keeping the destination', async () => {
    signIn()
    const { calls } = accountHandler({ account: TEMPORARY })
    const router = makeRouter()

    await router.push('/privado?pestana=1')

    expect(router.currentRoute.value.name).toBe('password-change')
    expect(router.currentRoute.value.query.redirect).toBe('/privado?pestana=1')
    expect(calls.map((call) => call.route)).toEqual(['GET /api/v1/cuenta'])
  })

  it('while it is temporary no other route can be reached, with no further reads', async () => {
    signIn()
    const { calls } = accountHandler({ account: TEMPORARY })
    const router = makeRouter()
    await router.push('/privado')

    await router.push('/otro')
    expect(router.currentRoute.value.name).toBe('password-change')
    await router.push('/')
    expect(router.currentRoute.value.name).toBe('password-change')
    await router.push('/terminos')
    expect(router.currentRoute.value.name).toBe('password-change')

    expect(calls).toHaveLength(1)
  })

  it('the mandatory change itself can be opened', async () => {
    signIn()
    accountHandler({ account: TEMPORARY })
    const router = makeRouter()

    await router.push('/cambiar-contrasena')

    expect(router.currentRoute.value.name).toBe('password-change')
  })

  it('without a temporary password the routes open, and the mandatory change goes home', async () => {
    signIn()
    accountHandler()
    const router = makeRouter()

    await router.push('/privado')
    expect(router.currentRoute.value.path).toBe('/privado')

    await router.push('/cambiar-contrasena')
    expect(router.currentRoute.value.path).toBe('/')
  })

  it('reads the account once per session, not on every route', async () => {
    signIn()
    const { calls } = accountHandler()
    const router = makeRouter()

    await router.push('/privado')
    await router.push('/otro')
    await router.push('/privado')

    expect(calls).toHaveLength(1)
  })

  it('a public route reads the account too, so the app blocks a temporary password from the start', async () => {
    signIn()
    const { calls } = accountHandler({ account: TEMPORARY })
    const router = makeRouter()

    await router.push('/terminos')

    expect(router.currentRoute.value.name).toBe('password-change')
    expect(calls).toHaveLength(1)
  })

  it('a 401 on a public route closes the session without sending the person to sign in', async () => {
    signIn()
    mockFetch(() => problem(401, { type: 'http://localhost/problemas/no-autenticado' }))
    const router = makeRouter()
    connectSession(router)

    await router.push('/terminos')
    await flushPromises()

    expect(useSessionStore().isAuthenticated).toBe(false)
    expect(router.currentRoute.value.path).toBe('/terminos')
  })

  it('without a session it asks nothing and goes to the sign-in page', async () => {
    const fetchMock = mockFetch(() => jsonResponse(404, {}))
    const router = makeRouter()

    await router.push('/privado')

    expect(router.currentRoute.value.name).toBe('login')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('a failed read does not lock the person out: the 403 of the API still catches it later', async () => {
    signIn()
    mockFetch(() => problem(500, {}))
    const router = makeRouter()

    await router.push('/privado')

    expect(router.currentRoute.value.path).toBe('/privado')
    expect(useAccountStore().temporaryPassword).toBeNull()
  })

  it('a 401 while the guard reads the account ends at the sign-in page with the destination', async () => {
    signIn()
    mockFetch(() => problem(401, { type: 'http://localhost/problemas/no-autenticado' }))
    const router = makeRouter()
    connectSession(router)

    await router.push('/privado?x=1')
    await flushPromises()

    expect(useSessionStore().isAuthenticated).toBe(false)
    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/privado?x=1')
  })
})

describe('403 «contrasena-temporal» on any request', () => {
  it('marks the password as temporary and goes to the mandatory change', async () => {
    signIn()
    const router = makeRouter()
    useAccountStore().$patch({ status: 'ready', temporaryPassword: false })
    await router.push('/privado')
    connectSession(router)
    mockFetch((request) =>
      pathOf(request) === 'GET /api/v1/mis-barberias'
        ? temporaryPasswordProblem()
        : jsonResponse(404, {}),
    )

    await api.GET('/mis-barberias').catch(() => undefined)
    await flushPromises()

    expect(useAccountStore().temporaryPassword).toBe(true)
    expect(router.currentRoute.value.name).toBe('password-change')
  })

  it('another 403 changes nothing', async () => {
    signIn()
    const router = makeRouter()
    useAccountStore().$patch({ status: 'ready', temporaryPassword: false })
    await router.push('/privado')
    connectSession(router)
    mockFetch(() =>
      problem(403, { type: 'http://localhost/problemas/prohibido', title: 'Prohibido' }),
    )

    await api.GET('/mis-barberias').catch(() => undefined)
    await flushPromises()

    expect(useAccountStore().temporaryPassword).toBe(false)
    expect(router.currentRoute.value.path).toBe('/privado')
  })
})
