import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Component } from 'vue'

import HomeView from '@/views/HomeView.vue'
import LoginView from '@/views/LoginView.vue'
import PrivacyView from '@/views/PrivacyView.vue'
import RegisterView from '@/views/RegisterView.vue'
import TermsView from '@/views/TermsView.vue'
import { fakeDevice } from '../helpers/device'
import { mountView } from '../helpers/mountView'

beforeEach(() => {
  sessionStorage.clear()
  localStorage.clear()
  fakeDevice({ dark: true })
})
afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

const SCREENS: Array<[string, Component, string]> = [
  ['the home page', HomeView, '/'],
  ['Ingresar', LoginView, '/ingresar'],
  ['Crear cuenta', RegisterView, '/registro'],
  ['Términos y condiciones', TermsView, '/terminos'],
  ['Política de privacidad', PrivacyView, '/privacidad'],
]

describe('«Preferencias» without a session', () => {
  it.each(SCREENS)(
    '%s has the link in its header, next to the logotype',
    async (_name, view, path) => {
      const { wrapper } = await mountView(view, path)

      const header = wrapper.get('header')
      const link = header.get('a[href="/preferencias"]')
      expect(link.text()).toBe('Preferencias')
      expect(header.get('img').attributes('alt')).toBe('BarberHub')
    },
  )

  it.each(SCREENS)(
    '%s uses the same link component, with one link only',
    async (_name, view, path) => {
      const { wrapper } = await mountView(view, path)

      expect(wrapper.findAll('a[href="/preferencias"]')).toHaveLength(1)
    },
  )

  it.each(SCREENS)(
    '%s keeps the 44 px circle of a phone and the pill of a desktop',
    async (_name, view, path) => {
      const { wrapper } = await mountView(view, path)
      const classes = wrapper.get('a[href="/preferencias"]').classes()

      expect(classes).toEqual(expect.arrayContaining(['size-11', 'md:rounded-pill']))
    },
  )

  it('on the legal pages «Volver» stays at the left and the link and logotype at the right', async () => {
    for (const [, view, path] of SCREENS.filter(([name]) => /Términos|privacidad/.test(name))) {
      const { wrapper } = await mountView(view, path)
      const header = wrapper.get('header')
      const [back, group] = header.element.children

      expect(back?.textContent).toContain('Volver')
      expect(group?.querySelector('a[href="/preferencias"]')).not.toBeNull()
      expect(group?.querySelector('img')).not.toBeNull()
      // The group does not shrink away from «Volver».
      expect(header.classes()).toEqual(expect.arrayContaining(['justify-between', 'gap-2']))
    }
  })

  it('on the sign-in screens the logotype stays at the left and the link at the right', async () => {
    for (const [, view, path] of SCREENS.filter(([name]) => /Ingresar|Crear/.test(name))) {
      const { wrapper } = await mountView(view, path)
      const [first, last] = wrapper.get('header').element.children

      expect(first?.tagName).toBe('SPAN')
      expect(first?.querySelector('img')).not.toBeNull()
      expect(last?.getAttribute('href')).toBe('/preferencias')
    }
  })

  it('the link leads to the screen, which needs no session', async () => {
    const { wrapper, router } = await mountView(LoginView, '/ingresar')

    await wrapper.get('a[href="/preferencias"]').trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('preferences'))
  })
})
