import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import LocationMenu from '@/components/LocationMenu.vue'

afterEach(() => {
  document.body.innerHTML = ''
})

const ADDRESS = 'Avenida Central, 200 m norte del Parque'
const CITY = 'San José'
const QUERY = encodeURIComponent(`${ADDRESS}, ${CITY}`)

function make(props: { address?: string; city?: string } = { address: ADDRESS, city: CITY }) {
  return mount(LocationMenu, { props, attachTo: document.body })
}

describe('LocationMenu', () => {
  it('renders nothing without an address', () => {
    expect(make({ city: CITY }).html()).toBe('<!--v-if-->')
    expect(make({ address: '  ', city: CITY }).find('button').exists()).toBe(false)
  })

  it('shows the address with the city in a button, closed at first', () => {
    const wrapper = make()
    const button = wrapper.get('button')

    expect(button.text()).toBe(`${ADDRESS}, ${CITY}`)
    expect(button.attributes('aria-expanded')).toBe('false')
    expect(wrapper.get('ul').isVisible()).toBe(false)
    expect(wrapper.find('[role="menu"]').exists()).toBe(false)
    expect(button.attributes('aria-controls')).toBe(wrapper.get('ul').attributes('id'))
  })

  it('opens with three links built from the encoded address', async () => {
    const wrapper = make()
    await wrapper.get('button').trigger('click')

    expect(wrapper.get('button').attributes('aria-expanded')).toBe('true')
    expect(wrapper.get('ul').isVisible()).toBe(true)
    const links = wrapper.findAll('a')
    expect(links.map((link) => link.text())).toEqual([
      'Ver en Google Maps',
      'Cómo llegar',
      'Abrir en Waze',
    ])
    expect(links.map((link) => link.attributes('href'))).toEqual([
      `https://www.google.com/maps/search/?api=1&query=${QUERY}`,
      `https://www.google.com/maps/dir/?api=1&destination=${QUERY}`,
      `https://waze.com/ul?q=${QUERY}&navigate=yes`,
    ])
    for (const link of links) {
      expect(link.attributes('target')).toBe('_blank')
      expect(link.attributes('rel')).toBe('noopener noreferrer')
    }
  })

  it('closes with Escape and returns the focus to the button', async () => {
    const wrapper = make()
    const button = wrapper.get('button')
    await button.trigger('click')
    ;(wrapper.get('a').element as HTMLElement).focus()

    await wrapper.get('a').trigger('keydown', { key: 'Escape' })

    expect(button.attributes('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(button.element)
  })

  it('closes on a click outside, not on a click inside', async () => {
    const wrapper = make()
    await wrapper.get('button').trigger('click')

    wrapper.get('ul').element.click()
    await wrapper.vm.$nextTick()
    expect(wrapper.get('button').attributes('aria-expanded')).toBe('true')

    document.body.click()
    await wrapper.vm.$nextTick()
    expect(wrapper.get('button').attributes('aria-expanded')).toBe('false')
  })

  it('toggles with the button', async () => {
    const wrapper = make()
    await wrapper.get('button').trigger('click')
    await wrapper.get('button').trigger('click')
    expect(wrapper.get('button').attributes('aria-expanded')).toBe('false')
  })

  it('each option has its own icon (not an external-link arrow) and the lines stop short of the edge', () => {
    const wrapper = mount(LocationMenu, { props: { address: 'Avenida Central', city: 'San José' } })
    const items = wrapper.findAll('li')

    expect(items).toHaveLength(3)
    const icons = items.map((item) => item.get('a svg').html())
    expect(new Set(icons).size).toBe(3)
    for (const icon of icons) expect(icon).not.toContain('lucide-external-link')
    // Brand glyphs for Google Maps and Waze, filled; a navigation arrow for the route.
    expect(items[0]!.get('a svg').attributes('fill')).toBe('currentColor')
    expect(items[2]!.get('a svg').attributes('fill')).toBe('currentColor')
    // The line above each option but the first is inset 16 px on both sides.
    expect(items[0]!.classes().join(' ')).toContain('not-first:before:inset-x-4')
    expect(items[1]!.classes().join(' ')).toContain('not-first:before:h-px')
    for (const item of items) expect(item.classes()).not.toContain('not-first:border-t')
  })
})
