import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import PreferencesView from '@/views/PreferencesView.vue'
import { routes } from '@/router'
import { SESSION_STORAGE_KEY } from '@/stores/session'
import { fakeDevice } from '../helpers/device'
import { mockFetch } from '../helpers/http'
import { mountView } from '../helpers/mountView'

beforeEach(() => {
  localStorage.clear()
  sessionStorage.clear()
  fakeDevice({ dark: true })
})
afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

function signIn(): void {
  sessionStorage.setItem(
    SESSION_STORAGE_KEY,
    JSON.stringify({ token: 'tok', person: { email: 'laura.brenes@correo.cr' } }),
  )
}

describe('route /preferencias', () => {
  it('exists, is named «preferences», is public and does not need a session', () => {
    const route = routes.find((candidate) => candidate.path === '/preferencias')

    expect(route?.name).toBe('preferences')
    expect(route?.meta).toEqual({ public: true })
  })

  it('opens without a session: no redirect to the sign-in page', async () => {
    const { router } = await mountView(PreferencesView, '/preferencias')

    expect(router.currentRoute.value.name).toBe('preferences')
  })

  it('with a session the address goes to «Mi cuenta · Apariencia», which hosts the same block', async () => {
    signIn()
    mockFetch(() => new Response(null, { status: 204 }))

    const { router } = await mountView(PreferencesView, '/preferencias')

    expect(router.currentRoute.value.name).toBe('account-appearance')
    expect(router.currentRoute.value.path).toBe('/cuenta/apariencia')
  })
})

describe('PreferencesView', () => {
  it('has a single h1 «Preferencias» and the three groups, with no «Volver»', async () => {
    const { wrapper } = await mountView(PreferencesView, '/preferencias')

    expect(wrapper.findAll('h1').map((heading) => heading.text())).toEqual(['Preferencias'])
    expect(wrapper.find('[role="radiogroup"][aria-label="Tema"]').exists()).toBe(true)
    expect(wrapper.find('[role="radiogroup"][aria-label="Tamaño del texto"]').exists()).toBe(true)
    expect(wrapper.find('[role="switch"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Volver')
  })

  it('without a session shows the logotype and «Preferencias» as the current page', async () => {
    const { wrapper } = await mountView(PreferencesView, '/preferencias')

    expect(wrapper.find('nav[aria-label="Principal"]').exists()).toBe(false)
    expect(wrapper.get('header img').attributes('alt')).toBe('BarberHub')
    expect(wrapper.get('header a[aria-current="page"]').text()).toBe('Preferencias')
  })

  it('never calls the API, with or without a session', async () => {
    const fetchMock = mockFetch(() => new Response(null, { status: 204 }))

    await mountView(PreferencesView, '/preferencias')
    signIn()
    await mountView(PreferencesView, '/preferencias')

    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('on desktop is a card of 16 px with a 1 px line, like the other screens', async () => {
    const { wrapper } = await mountView(PreferencesView, '/preferencias')

    expect(wrapper.get('[data-testid="preferences-card"]').classes()).toEqual(
      expect.arrayContaining(['md:rounded-card', 'md:border', 'md:border-line']),
    )
  })
})
