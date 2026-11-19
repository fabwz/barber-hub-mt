import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'

import PreferencesLink from '@/components/PreferencesLink.vue'
import { mountView } from '../helpers/mountView'

afterEach(() => {
  document.body.innerHTML = ''
})

const host = defineComponent({ render: () => h(PreferencesLink) })

describe('PreferencesLink', () => {
  it('is a link to /preferencias named «Preferencias»', async () => {
    const { wrapper } = await mountView(host, '/ingresar')

    const link = wrapper.get('a')
    expect(link.attributes('href')).toBe('/preferencias')
    expect(link.text()).toBe('Preferencias')
  })

  it('on a phone shows only the icon, keeping the name for assistive technology', async () => {
    const { wrapper } = await mountView(host, '/ingresar')
    const link = wrapper.get('a')

    expect(link.get('svg').attributes('aria-hidden')).toBe('true')
    expect(link.get('svg').classes()).toContain('md:hidden')
    expect(link.get('span').classes()).toEqual(
      expect.arrayContaining(['sr-only', 'md:not-sr-only']),
    )
  })

  it('has a 44 px target: a circle on a phone, a pill from 768 px', async () => {
    const { wrapper } = await mountView(host, '/ingresar')
    const classes = wrapper.get('a').classes()

    expect(classes).toEqual(expect.arrayContaining(['size-11', 'rounded-full']))
    expect(classes).toEqual(expect.arrayContaining(['md:rounded-pill', 'md:w-auto']))
    expect(classes).toContain('no-underline')
  })

  it('does not shrink, so it never pushes the logotype out of place', async () => {
    const { wrapper } = await mountView(host, '/ingresar')

    expect(wrapper.get('a').classes()).toContain('flex-none')
  })

  it('is not the current page elsewhere, and is on its own screen', async () => {
    const elsewhere = await mountView(host, '/ingresar')
    expect(elsewhere.wrapper.get('a').attributes('aria-current')).toBeUndefined()

    const own = await mountView(host, '/preferencias')
    expect(own.wrapper.get('a').attributes('aria-current')).toBe('page')
    expect(own.wrapper.get('a').classes()).toEqual(
      expect.arrayContaining(['bg-elevated', 'font-medium']),
    )
  })
})
