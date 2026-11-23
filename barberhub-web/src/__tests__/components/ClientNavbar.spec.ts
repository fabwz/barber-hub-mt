import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, type VueWrapper } from '@vue/test-utils'

import ClientNavbar from '@/components/ClientNavbar.vue'
import { accountInitials, shopInitials } from '@/composables/initials'
import { SESSION_STORAGE_KEY } from '@/stores/session'
import { mockFetch } from '../helpers/http'
import { mountView } from '../helpers/mountView'

const EMAIL = 'laura.brenes@correo.cr'

beforeEach(() => {
  sessionStorage.setItem(
    SESSION_STORAGE_KEY,
    JSON.stringify({ token: 'tok', person: { email: EMAIL } }),
  )
})
afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

// The first trigger is the desktop pill; the second, the mobile «Menú» button.
function triggers(wrapper: VueWrapper) {
  const [desktop, mobile] = wrapper.findAll('button[aria-expanded]')
  return { desktop: desktop!, mobile: mobile! }
}

function panel(wrapper: VueWrapper) {
  return wrapper.find('[data-testid="account-identity"]').element.parentElement!
}

async function mountNavbar(extraRoutes: Parameters<typeof mountView>[2] = []) {
  mockFetch(() => new Response(null, { status: 204 }))
  const mounted = await mountView(ClientNavbar, '/barberias', extraRoutes)
  return { ...mounted, ...triggers(mounted.wrapper) }
}

describe('ClientNavbar — bar', () => {
  it('shows the brand, «Barberías» as the current page and the account menu closed', async () => {
    const { wrapper, desktop } = await mountNavbar()

    const nav = wrapper.get('nav[aria-label="Principal"]')
    const link = nav.get('a')
    expect(link.text()).toBe('Barberías')
    expect(link.attributes('href')).toBe('/barberias')
    expect(link.attributes('aria-current')).toBe('page')
    expect(wrapper.get('a[aria-label="BarberHub, ir a Barberías"]').attributes('href')).toBe(
      '/barberias',
    )
    expect(desktop.attributes('aria-expanded')).toBe('false')
    expect(wrapper.text()).not.toContain('Sesión iniciada')
  })

  it('the pill shows the monogram and who is signed in', async () => {
    const { desktop } = await mountNavbar()

    expect(desktop.text()).toContain('LB')
    expect(desktop.text()).toContain(EMAIL)
  })

  it('shows «Mi perfil» and «Preferencias» in the bar and in the mobile panel, pointing to «Mi cuenta»', async () => {
    const { wrapper, desktop } = await mountNavbar()

    const bar = wrapper.get('nav[aria-label="Principal"]')
    expect(bar.findAll('a').map((a) => a.text())).toEqual([
      'Barberías',
      'Mi perfil',
      'Preferencias',
    ])
    expect(bar.findAll('a').map((a) => a.attributes('href'))).toEqual([
      '/barberias',
      '/cuenta/datos',
      '/cuenta/apariencia',
    ])

    await desktop.trigger('click')
    const inPanel = panel(wrapper).querySelectorAll('a')
    expect([...inPanel].map((a) => a.textContent?.trim())).toEqual([
      'Barberías',
      'Mi perfil',
      'Preferencias',
    ])
  })

  it('only links to routes that exist', async () => {
    const { wrapper, router } = await mountNavbar()

    for (const anchor of wrapper.findAll('a')) {
      expect(router.resolve(anchor.attributes('href')!).matched.length).toBeGreaterThan(0)
    }
  })

  it('marks another current page when the route changes', async () => {
    const { wrapper, router } = await mountNavbar()
    await router.push('/cuenta/apariencia')
    await flushPromises()

    const bar = wrapper.get('nav[aria-label="Principal"]')
    expect(bar.get('a[href="/cuenta/apariencia"]').attributes('aria-current')).toBe('page')
    expect(bar.get('a[href="/barberias"]').attributes('aria-current')).toBeUndefined()
  })
})

describe('ClientNavbar — account menu', () => {
  it('opens with «Sesión iniciada», the name and «Cerrar sesión»', async () => {
    const { wrapper, desktop } = await mountNavbar()

    await desktop.trigger('click')

    expect(desktop.attributes('aria-expanded')).toBe('true')
    expect(desktop.attributes('aria-controls')).toBe(panel(wrapper).id)
    expect(panel(wrapper).textContent).toContain('Sesión iniciada')
    expect(panel(wrapper).textContent).toContain(EMAIL)
    expect(wrapper.findAll('button').some((b) => b.text() === 'Cerrar sesión')).toBe(true)
  })

  it('closes with the trigger again', async () => {
    const { wrapper, desktop } = await mountNavbar()

    await desktop.trigger('click')
    await desktop.trigger('click')

    expect(desktop.attributes('aria-expanded')).toBe('false')
    expect(wrapper.text()).not.toContain('Sesión iniciada')
  })

  it('closes with Escape and gives the focus back to the trigger', async () => {
    const { wrapper, desktop } = await mountNavbar()
    await desktop.trigger('click')
    const logout = wrapper.findAll('button').find((b) => b.text() === 'Cerrar sesión')!
    ;(logout.element as HTMLButtonElement).focus()

    await logout.trigger('keydown', { key: 'Escape' })

    expect(wrapper.text()).not.toContain('Sesión iniciada')
    expect(desktop.attributes('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(desktop.element)
  })

  it('closes when a click lands outside', async () => {
    const { wrapper, desktop } = await mountNavbar()
    await desktop.trigger('click')

    document.body.click()
    await flushPromises()

    expect(desktop.attributes('aria-expanded')).toBe('false')
    expect(wrapper.text()).not.toContain('Sesión iniciada')
  })

  it('«Cerrar sesión» revokes the token, clears the session and returns to the start', async () => {
    const fetchMock = mockFetch(() => new Response(null, { status: 204 }))
    const { wrapper, router } = await mountView(ClientNavbar, '/barberias')
    await triggers(wrapper).desktop.trigger('click')

    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Cerrar sesión')!
      .trigger('click')
    await flushPromises()

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.method).toBe('DELETE')
    expect(new URL(request.url).pathname).toBe('/api/v1/tokens/actual')
    expect(request.headers.get('Authorization')).toBe('Bearer tok')
    expect(sessionStorage.getItem(SESSION_STORAGE_KEY)).toBeNull()
    expect(router.currentRoute.value.path).toBe('/')
  })

  it('leaves the session even when the API does not answer', async () => {
    mockFetch(() => Promise.reject(new TypeError('Failed to fetch')))
    const { wrapper, router } = await mountView(ClientNavbar, '/barberias')
    await triggers(wrapper).desktop.trigger('click')

    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Cerrar sesión')!
      .trigger('click')
    await flushPromises()

    expect(sessionStorage.getItem(SESSION_STORAGE_KEY)).toBeNull()
    expect(router.currentRoute.value.path).toBe('/')
  })
})

describe('ClientNavbar — mobile', () => {
  it('has a «Menú» button of 44 px and hides the desktop bar and pill', async () => {
    const { wrapper, desktop, mobile } = await mountNavbar()

    expect(mobile.attributes('aria-label')).toBe('Menú')
    expect(mobile.classes()).toEqual(expect.arrayContaining(['size-11', 'md:hidden']))
    expect(desktop.classes()).toEqual(expect.arrayContaining(['hidden', 'md:flex', 'min-h-11']))
    expect(wrapper.get('nav[aria-label="Principal"]').classes()).toEqual(
      expect.arrayContaining(['hidden', 'md:flex']),
    )
  })

  it('opens a panel with the links, the name and «Cerrar sesión»', async () => {
    const { wrapper, mobile } = await mountNavbar()

    await mobile.trigger('click')

    expect(mobile.attributes('aria-expanded')).toBe('true')
    const text = panel(wrapper).textContent
    expect(text).toContain('Barberías')
    expect(text).toContain('Sesión iniciada')
    expect(text).toContain(EMAIL)
    expect(text).toContain('Cerrar sesión')
    // The link list is only for mobile; on desktop the links live in the bar.
    expect(panel(wrapper).querySelector('ul')!.className).toContain('md:hidden')
    const current = panel(wrapper).querySelector('a[aria-current="page"]')
    expect(current?.textContent?.trim()).toBe('Barberías')
  })

  it('Escape closes the panel and the focus goes back to the mobile button', async () => {
    const { wrapper, mobile } = await mountNavbar()
    await mobile.trigger('click')

    await mobile.trigger('keydown', { key: 'Escape' })

    expect(mobile.attributes('aria-expanded')).toBe('false')
    expect(wrapper.text()).not.toContain('Sesión iniciada')
    expect(document.activeElement).toBe(mobile.element)
  })

  it('following a link of the panel closes it', async () => {
    const { wrapper, mobile } = await mountNavbar()
    await mobile.trigger('click')

    const link = panel(wrapper).querySelector('a')!
    link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }))
    await flushPromises()

    expect(mobile.attributes('aria-expanded')).toBe('false')
  })

  it('the rows of the panel are at least 48 px tall', async () => {
    const { wrapper, mobile } = await mountNavbar()
    await mobile.trigger('click')

    expect(panel(wrapper).querySelector('a')!.className).toContain('min-h-12')
    const logout = [...panel(wrapper).querySelectorAll('button')].find(
      (b) => b.textContent?.trim() === 'Cerrar sesión',
    )!
    expect(logout.className).toContain('min-h-12')
  })
})

describe('initials', () => {
  it.each([
    ['laura.brenes@correo.cr', 'LB'],
    ['ana@example.test', 'A'],
    ['juan_perez-mora@x.cr', 'JP'],
    ['', ''],
  ])('account «%s» gives «%s»', (label, expected) => {
    expect(accountInitials(label)).toBe(expected)
  })

  it.each([
    ['Pura Navaja', 'PN'],
    ['Fade Lab', 'FL'],
    ['Barbería Don Chepe', 'BD'],
    ['Ox', 'O'],
  ])('shop «%s» gives «%s»', (name, expected) => {
    expect(shopInitials(name)).toBe(expected)
  })
})
