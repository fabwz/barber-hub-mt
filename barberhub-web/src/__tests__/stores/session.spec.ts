import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { defineComponent, h } from 'vue'

import { api, ApiError, setTokenProvider, setUnauthorizedHandler } from '@/api'
import { installGuards } from '@/router/guard'
import { connectSession, SESSION_STORAGE_KEY, useSessionStore } from '@/stores/session'
import { jsonResponse, mockFetch, pathOf, problem } from '../helpers/http'

const page = defineComponent({ render: () => h('p') })
const tokenResponse = () => jsonResponse(201, { data: { token: 'tok-1', tipo: 'Bearer' } })

beforeEach(() => {
  sessionStorage.clear()
  setActivePinia(createPinia())
})

afterEach(() => {
  vi.unstubAllGlobals()
  setTokenProvider(() => null)
  setUnauthorizedHandler(() => {})
})

describe('session store', () => {
  it('register posts the contract body with both acceptances and starts no session', async () => {
    const fetchMock = mockFetch(() => jsonResponse(201, { data: { id: '1', nombre: 'Ana' } }))
    const session = useSessionStore()

    await session.register({
      name: 'Ana Mora',
      email: 'ana@example.test',
      phone: '88888888',
      password: 'Abcdef1!',
    })

    const request = fetchMock.mock.calls[0]![0]
    expect(pathOf(request)).toBe('POST /api/v1/cuentas')
    expect(await request.clone().json()).toEqual({
      nombre: 'Ana Mora',
      correo: 'ana@example.test',
      telefono: '88888888',
      contrasena: 'Abcdef1!',
      acepta_terminos: true,
      acepta_privacidad: true,
    })
    expect(session.isAuthenticated).toBe(false)
  })

  it('login keeps the token in memory and in sessionStorage, never in localStorage', async () => {
    const fetchMock = mockFetch(tokenResponse)
    const session = useSessionStore()

    await session.login('ana@example.test', 'Abcdef1!')

    const body = await fetchMock.mock.calls[0]![0].clone().json()
    expect(body).toEqual({
      correo: 'ana@example.test',
      contrasena: 'Abcdef1!',
      dispositivo: 'web',
    })
    expect(session.isAuthenticated).toBe(true)
    expect(session.token).toBe('tok-1')
    expect(JSON.parse(sessionStorage.getItem(SESSION_STORAGE_KEY)!).token).toBe('tok-1')
    expect(localStorage.length).toBe(0)
  })

  it('a failed login leaves no session', async () => {
    mockFetch(() => problem(401, { type: 'credenciales-invalidas' }))
    const session = useSessionStore()

    await expect(session.login('ana@example.test', 'mal')).rejects.toBeInstanceOf(ApiError)
    expect(session.isAuthenticated).toBe(false)
    expect(sessionStorage.getItem(SESSION_STORAGE_KEY)).toBeNull()
  })

  it('logout revokes the token and clears the local session', async () => {
    const fetchMock = mockFetch((request) =>
      pathOf(request) === 'POST /api/v1/tokens'
        ? tokenResponse()
        : new Response(null, { status: 204 }),
    )
    const session = useSessionStore()
    connectSession(createRouter({ history: createMemoryHistory(), routes: [] }))
    await session.login('ana@example.test', 'Abcdef1!')

    await session.logout()

    const last = fetchMock.mock.calls.at(-1)![0]
    expect(pathOf(last)).toBe('DELETE /api/v1/tokens/actual')
    expect(last.headers.get('Authorization')).toBe('Bearer tok-1')
    expect(session.isAuthenticated).toBe(false)
    expect(sessionStorage.getItem(SESSION_STORAGE_KEY)).toBeNull()
  })

  it('logout clears the local session even if the API call fails', async () => {
    mockFetch((request) => {
      if (pathOf(request) === 'POST /api/v1/tokens') return tokenResponse()
      throw new TypeError('offline')
    })
    const session = useSessionStore()
    await session.login('ana@example.test', 'Abcdef1!')

    await expect(session.logout()).resolves.toBeUndefined()
    expect(session.isAuthenticated).toBe(false)
  })

  it('restores the session from sessionStorage when the store starts', () => {
    sessionStorage.setItem(
      SESSION_STORAGE_KEY,
      JSON.stringify({ token: 'tok-9', person: { email: 'ana@example.test' } }),
    )
    setActivePinia(createPinia())

    const session = useSessionStore()

    expect(session.isAuthenticated).toBe(true)
    expect(session.token).toBe('tok-9')
    expect(session.person).toEqual({ email: 'ana@example.test' })
  })

  it('ignores a corrupt stored value', () => {
    sessionStorage.setItem(SESSION_STORAGE_KEY, '{no es json')
    setActivePinia(createPinia())

    expect(useSessionStore().isAuthenticated).toBe(false)
  })

  it('works in memory when sessionStorage is not available', async () => {
    const blocked = () => {
      throw new Error('blocked')
    }
    vi.stubGlobal('sessionStorage', { getItem: blocked, setItem: blocked, removeItem: blocked })
    mockFetch(tokenResponse)
    setActivePinia(createPinia())
    const session = useSessionStore()

    await session.login('ana@example.test', 'Abcdef1!')
    expect(session.isAuthenticated).toBe(true)

    session.clear()
    expect(session.isAuthenticated).toBe(false)
  })
})

describe('401 handler (connectSession)', () => {
  function setup() {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: page },
        { path: '/privado', component: page, meta: { requiresAuth: true } },
        { path: '/ingresar', name: 'login', component: page, meta: { public: true } },
      ],
    })
    installGuards(router)
    connectSession(router)
    const session = useSessionStore()
    session.token = 'tok-1'
    return { router, session }
  }

  it('sends the token of the store as bearer', async () => {
    const fetchMock = mockFetch(() => jsonResponse(200, { data: [] }))
    setup()

    await api.GET('/barberias')

    expect(fetchMock.mock.calls[0]![0].headers.get('Authorization')).toBe('Bearer tok-1')
  })

  it('on a 401 clears the session and goes to /ingresar with the current route in redirect', async () => {
    mockFetch(() => problem(401, { type: 'no-autenticado' }))
    const { router, session } = setup()
    await router.push('/privado')

    await expect(api.GET('/barberias')).rejects.toBeInstanceOf(ApiError)
    await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/ingresar'))

    expect(router.currentRoute.value.query.redirect).toBe('/privado')
    expect(session.isAuthenticated).toBe(false)
  })

  it('on a 401 in a public page clears the session without redirecting', async () => {
    mockFetch(() => problem(401, { type: 'credenciales-invalidas' }))
    const { router, session } = setup()
    await router.push('/ingresar')

    await expect(api.GET('/barberias')).rejects.toBeInstanceOf(ApiError)

    expect(router.currentRoute.value.fullPath).toBe('/ingresar')
    expect(session.isAuthenticated).toBe(false)
  })
})
