import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import HomeView from '@/views/HomeView.vue'
import { mockFetch } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { SESSION_STORAGE_KEY } from '@/stores/session'

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
    mockFetch(() => new Response(null, { status: 204 }))
    const { wrapper } = await mountView(HomeView, '/')
    expect(wrapper.text()).toContain('ana@example.test')

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.get('a[href="/ingresar"]').exists()).toBe(true)
    expect(sessionStorage.getItem(SESSION_STORAGE_KEY)).toBeNull()
  })
})
