import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import HomeView from '@/views/HomeView.vue'
import { mockFetch } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { ACTIVE_SHOP_STORAGE_KEY } from '@/stores/activeShop'
import { SESSION_STORAGE_KEY } from '@/stores/session'
import { CLIENT_SHOP, OWNER_SHOP, SHOP_B, STAFF_SHOP, staffHandler } from '../helpers/staff'

afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

describe('HomeView (temporary)', () => {
  it('without a session offers Ingresar and Crear cuenta', async () => {
    sessionStorage.clear()
    const { wrapper } = await mountView(HomeView, '/')

    expect(wrapper.get('a[href="/ingresar"]').text()).toBe('Ingresar')
    expect(wrapper.get('a[href="/registro"]').text()).toBe('Crear cuenta')
    expect(wrapper.text()).not.toContain('Cerrar sesión')
  })

  it('with a session shows the person and closes the session', async () => {
    sessionStorage.setItem(
      SESSION_STORAGE_KEY,
      JSON.stringify({ token: 'tok', person: { email: 'ana@example.test' } }),
    )
    mockFetch((request) =>
      request.method === 'DELETE' ? new Response(null, { status: 204 }) : staffHandler([])(request),
    )
    const { wrapper } = await mountView(HomeView, '/')
    expect(wrapper.text()).toContain('ana@example.test')

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.get('a[href="/ingresar"]').exists()).toBe(true)
    expect(sessionStorage.getItem(SESSION_STORAGE_KEY)).toBeNull()
  })

  function startSession() {
    sessionStorage.setItem(
      SESSION_STORAGE_KEY,
      JSON.stringify({ token: 'tok', person: { email: 'ana@example.test' } }),
    )
  }

  it('with an active shop shows its name and role, and offers to change it when there are several', async () => {
    startSession()
    sessionStorage.setItem(ACTIVE_SHOP_STORAGE_KEY, JSON.stringify({ id: SHOP_B }))
    mockFetch(staffHandler([OWNER_SHOP, STAFF_SHOP]))
    const { wrapper } = await mountView(HomeView, '/')
    await flushPromises()

    expect(wrapper.text()).toContain('Barbería: Fade Lab (Colaborador)')
    expect(wrapper.get('a[href="/negocios"]').text()).toBe('Cambiar de barbería')
  })

  it('with a single shop shows it but offers no change', async () => {
    startSession()
    sessionStorage.setItem(ACTIVE_SHOP_STORAGE_KEY, JSON.stringify({ id: OWNER_SHOP.id }))
    mockFetch(staffHandler([OWNER_SHOP, CLIENT_SHOP]))
    const { wrapper } = await mountView(HomeView, '/')
    await flushPromises()

    expect(wrapper.text()).toContain('Barbería: Pura Navaja (Dueño)')
    expect(wrapper.find('a[href="/negocios"]').exists()).toBe(false)
  })

  it('with a session but no active shop shows neither shop nor change link', async () => {
    startSession()
    mockFetch(staffHandler([CLIENT_SHOP]))
    const { wrapper } = await mountView(HomeView, '/')
    await flushPromises()

    expect(wrapper.text()).not.toContain('Barbería:')
    expect(wrapper.find('a[href="/negocios"]').exists()).toBe(false)
  })
})
