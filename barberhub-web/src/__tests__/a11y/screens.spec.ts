import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { RouteRecordRaw } from 'vue-router'

import App from '@/App.vue'
import { routes } from '@/router'
import { signIn } from '../helpers/account'
import { resetFakeEcho } from '../helpers/echo'
import { mountView } from '../helpers/mountView'
import { expectKeyboardReady, expectNoViolations } from './helpers'
import { EXEMPT, SCREENS } from './screens'

/** The names of every route of the router, children included. */
function routeNames(list: RouteRecordRaw[]): string[] {
  return list.flatMap((route) => [
    ...(typeof route.name === 'string' ? [route.name] : []),
    ...routeNames(route.children ?? []),
  ])
}

const NAMES = routeNames(routes)

describe('accessibility of the screens (RNF-27, AC-01)', () => {
  it('every route of the router is in the registry or exempt, and the registry has no ghosts', () => {
    const known = new Set([...Object.keys(SCREENS), ...Object.keys(EXEMPT)])

    const missing = NAMES.filter((name) => !known.has(name))
    const ghosts = [...known].filter((name) => !NAMES.includes(name))
    const both = Object.keys(SCREENS).filter((name) => name in EXEMPT)

    expect(
      missing,
      `New screen without an accessibility test: ${missing.join(', ')}. Add it to SCREENS in ` +
        'src/__tests__/a11y/screens.ts (who looks at it, its fake API and its address), or to EXEMPT ' +
        'with a written reason. See docs/accessibility-tests.md.',
    ).toEqual([])
    expect(ghosts, 'Registered screens whose route no longer exists: remove them.').toEqual([])
    expect(both, 'A screen cannot be registered and exempt at once.').toEqual([])
  })

  describe.each(Object.entries(SCREENS))('%s', (name, screen) => {
    let wrapper: VueWrapper | undefined

    beforeEach(() => {
      // What index.html gives the real page and jsdom does not have.
      document.documentElement.lang = 'es-CR'
      document.title = 'BarberHub'
      if (screen.session !== 'guest') signIn('marco@example.test')
    })
    afterEach(() => {
      wrapper?.unmount()
      wrapper = undefined
      resetFakeEcho()
      vi.useRealTimers()
      vi.unstubAllGlobals()
      sessionStorage.clear()
      document.body.innerHTML = ''
    })

    it('has no WCAG 2.2 AA violations that jsdom can see and works with the keyboard', async () => {
      const path = screen.prepare()
      const mounted = await mountView(App, path, [], { knownAccount: screen.knownAccount ?? true })
      wrapper = mounted.wrapper
      await flushPromises()
      // «Loaded» state: the screen is on display and nothing says it is still loading (aria-busy).
      await vi.waitFor(() => expect(document.querySelector('main')).not.toBeNull())
      await vi.waitFor(() => expect(document.querySelector('[aria-busy="true"]')).toBeNull())
      await flushPromises()

      // The screen that was asked for is the one on display (a guard did not send the person elsewhere).
      expect(mounted.router.currentRoute.value.name).toBe(name)

      // The whole screen is there, with its parent: one <main> and a <h1> (as the real page has).
      expect(document.querySelectorAll('main')).toHaveLength(1)
      expect(document.querySelector('h1')).not.toBeNull()

      await expectNoViolations(document)
      expectKeyboardReady(document)
    }, 30_000)
  })
})
