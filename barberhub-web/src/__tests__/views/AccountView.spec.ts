import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import App from '@/App.vue'
import { usePreferencesStore } from '@/stores/preferences'
import { accountHandler, signIn } from '../helpers/account'
import { fakeDevice } from '../helpers/device'
import { mountView } from '../helpers/mountView'
import { ACTIVE_SHOP_STORAGE_KEY } from '@/stores/activeShop'
import { OWNER_SHOP, SHOP_A, STAFF_SHOP, staffHandler } from '../helpers/staff'

beforeEach(() => {
  sessionStorage.clear()
  localStorage.clear()
  fakeDevice({ dark: true })
  signIn()
})
afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

async function open(path: string) {
  const handler = accountHandler()
  const mounted = await mountView(App, path)
  await flushPromises()
  return { ...mounted, ...handler }
}

describe('«Mi cuenta»: the sections', () => {
  it('has one h1 and three links to the sections that exist today, the current one marked', async () => {
    const { wrapper } = await open('/cuenta/seguridad')

    expect(wrapper.findAll('h1').map((heading) => heading.text())).toEqual(['Mi cuenta'])
    const links = wrapper.get('nav[aria-label="Mi cuenta"]').findAll('a')
    expect(links.map((link) => link.text())).toEqual(['Datos', 'Seguridad', 'Apariencia'])
    expect(links.map((link) => link.attributes('href'))).toEqual([
      '/cuenta/datos',
      '/cuenta/seguridad',
      '/cuenta/apariencia',
    ])
    expect(links.map((link) => link.attributes('aria-current'))).toEqual([
      undefined,
      'page',
      undefined,
    ])
  })

  it('does not show «Avisos» (#106) nor «Idioma» (#108): their screens do not exist yet', async () => {
    const { wrapper, router } = await open('/cuenta/datos')

    const section = wrapper.get('nav[aria-label="Mi cuenta"]').text()
    expect(section).not.toContain('Avisos')
    expect(section).not.toContain('Idioma')
    expect(router.getRoutes().some((route) => /avisos|idioma/.test(route.path))).toBe(false)
  })

  it('uses links and no tab roles', async () => {
    const { wrapper } = await open('/cuenta/datos')

    expect(wrapper.find('[role="tab"]').exists()).toBe(false)
    expect(wrapper.find('[role="tablist"]').exists()).toBe(false)
    expect(wrapper.find('[role="tabpanel"]').exists()).toBe(false)
  })

  it('every link is a 44 px target: pills that wrap on a phone, a vertical list on a desktop', async () => {
    const { wrapper } = await open('/cuenta/datos')

    const nav = wrapper.get('nav[aria-label="Mi cuenta"]')
    expect(nav.get('ul').classes()).toEqual(expect.arrayContaining(['flex-wrap', 'md:flex-col']))
    for (const link of nav.findAll('a')) {
      expect(link.classes()).toEqual(expect.arrayContaining(['min-h-11', 'rounded-pill']))
    }
  })

  it('«/cuenta» opens «Datos», and the client navbar frames the screen', async () => {
    const { wrapper, router } = await open('/cuenta')

    expect(router.currentRoute.value.path).toBe('/cuenta/datos')
    expect(wrapper.find('nav[aria-label="Principal"]').exists()).toBe(true)
    const current = wrapper.get('nav[aria-label="Principal"] a[aria-current="page"]')
    expect(current.text()).toBe('Mi perfil')
  })

  it('«Preferencias» of the navbar is the Apariencia section, and the old address leads there', async () => {
    const { wrapper, router } = await open('/cuenta/apariencia')

    const current = wrapper.get('nav[aria-label="Principal"] a[aria-current="page"]')
    expect(current.text()).toBe('Preferencias')
    expect(current.attributes('href')).toBe('/cuenta/apariencia')

    await router.push('/preferencias')
    await flushPromises()
    expect(router.currentRoute.value.path).toBe('/cuenta/apariencia')
  })

  it('needs a session', async () => {
    sessionStorage.clear()
    const { router } = await open('/cuenta/datos')

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/cuenta/datos')
  })
})

describe('«Mi cuenta · Apariencia»', () => {
  it('hosts the block of «Preferencias» and shares its store, with no request to the API', async () => {
    const { wrapper, fetchMock } = await open('/cuenta/apariencia')
    const preferences = usePreferencesStore()

    expect(wrapper.get('h2').text()).toBe('Apariencia')
    expect(wrapper.find('[role="radiogroup"][aria-label="Tema"]').exists()).toBe(true)
    expect(wrapper.find('[role="radiogroup"][aria-label="Tamaño del texto"]').exists()).toBe(true)
    expect(wrapper.find('[role="switch"]').exists()).toBe(true)

    const dark = wrapper.findAll('[role="radio"]').find((radio) => radio.text().includes('Oscuro'))
    await dark!.trigger('click')

    expect(preferences.theme).toBe('dark')
    expect(JSON.parse(localStorage.getItem('barberhub.preferences') ?? '{}').theme).toBe('dark')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('«Preferencias» without a session keeps its public screen', async () => {
    sessionStorage.clear()
    const { router, wrapper } = await open('/preferencias')

    expect(router.currentRoute.value.name).toBe('preferences')
    expect(wrapper.get('h1').text()).toBe('Preferencias')
    expect(wrapper.find('[role="radiogroup"][aria-label="Tema"]').exists()).toBe(true)
  })
})

describe('«Mi cuenta»: which bar frames it', () => {
  it('with an active shop (confirmed by the list) it wears the staff bar, with «Mi cuenta» in its menu', async () => {
    sessionStorage.setItem(ACTIVE_SHOP_STORAGE_KEY, JSON.stringify({ id: SHOP_A }))
    const { calls } = accountHandler({ other: staffHandler([OWNER_SHOP, STAFF_SHOP]) })
    const { wrapper } = await mountView(App, '/cuenta/datos')
    await flushPromises()

    expect(calls.some((call) => call.route === 'GET /api/v1/mis-barberias')).toBe(true)
    expect(wrapper.get('[data-testid="active-shop"]').text()).toBe('Pura Navaja')
    expect(wrapper.text()).not.toContain('Barberías')
  })

  it('without a stored shop it keeps the customer bar and asks no shops', async () => {
    const { calls } = accountHandler({ other: staffHandler([OWNER_SHOP]) })
    const { wrapper } = await mountView(App, '/cuenta/datos')
    await flushPromises()

    expect(calls.some((call) => call.route === 'GET /api/v1/mis-barberias')).toBe(false)
    expect(wrapper.find('[data-testid="active-shop"]').exists()).toBe(false)
    expect(wrapper.get('nav[aria-label="Principal"]').text()).toContain('Barberías')
  })

  it('a stored shop that is no longer in the list falls back to the customer bar', async () => {
    sessionStorage.setItem(ACTIVE_SHOP_STORAGE_KEY, JSON.stringify({ id: SHOP_A }))
    accountHandler({ other: staffHandler([STAFF_SHOP]) })
    const { wrapper } = await mountView(App, '/cuenta/datos')
    await flushPromises()

    expect(wrapper.find('[data-testid="active-shop"]').exists()).toBe(false)
    expect(wrapper.get('nav[aria-label="Principal"]').text()).toContain('Barberías')
  })
})
