import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import ShopHeader from '@/components/ShopHeader.vue'

afterEach(() => {
  document.body.innerHTML = ''
})

// Test data includes what the API does not deliver yet (address, networks, logo, banner).
const FULL = {
  name: 'Pura Navaja',
  city: 'San José',
  phone: '+506 2222 3333',
  address: 'Avenida Central, 200 m norte del Parque',
  logoUrl: 'https://cdn.example.test/logo.png',
  bannerUrl: 'https://cdn.example.test/banner.jpg',
  whatsapp: 'https://wa.me/50622223333',
  instagram: 'https://instagram.com/puranavaja',
}

function make(props: Partial<typeof FULL>) {
  return mount(ShopHeader, { props: props as typeof FULL, attachTo: document.body })
}

function parentClass(element: Element): string {
  return element.parentElement!.className
}

describe('ShopHeader', () => {
  it('with logo and banner: decorative banner, logo over its edge, name, address, phone, networks', () => {
    const wrapper = make(FULL)

    const banner = wrapper.get('img[loading="lazy"]')
    expect(banner.attributes('alt')).toBe('')
    expect(banner.attributes('width')).toBe('1200')
    expect(banner.attributes('height')).toBe('600')
    expect(banner.element.parentElement!.getAttribute('aria-hidden')).toBe('true')
    expect(parentClass(banner.element)).toContain('aspect-2/1')
    expect(parentClass(banner.element)).toContain('md:aspect-3/1')

    const logo = wrapper.get('img[alt="Logo de Pura Navaja"]')
    expect(logo.attributes('width')).toBe('72')
    expect(logo.attributes('height')).toBe('72')
    expect(parentClass(logo.element)).toContain('size-18')
    expect(parentClass(logo.element)).toContain('md:size-22')
    expect(parentClass(logo.element)).toContain('-mt-9')

    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('Pura Navaja')
    expect(wrapper.get('h1').classes()).toContain('text-4xl')
    expect(wrapper.get('button').text()).toContain(
      'Avenida Central, 200 m norte del Parque, San José',
    )
    const tel = wrapper.get('a[href^="tel:"]')
    expect(tel.attributes('href')).toBe('tel:+50622223333')
    expect(tel.classes()).toContain('tabular-nums')
    expect(wrapper.get('a[aria-label="WhatsApp de Pura Navaja"]').exists()).toBe(true)
    expect(wrapper.get('a[aria-label="Instagram de Pura Navaja"]').exists()).toBe(true)
    expect(wrapper.find('a[aria-label^="Facebook"]').exists()).toBe(false)
    expect(wrapper.find('[role="img"]').exists()).toBe(false)
  })

  it('with logo only: compact header, logo to the left of the name, no banner', () => {
    const wrapper = make({ ...FULL, bannerUrl: undefined })

    expect(wrapper.find('img[loading="lazy"]').exists()).toBe(false)
    expect(wrapper.findAll('img')).toHaveLength(1)
    const logo = wrapper.get('img[alt="Logo de Pura Navaja"]')
    expect(parentClass(logo.element)).toContain('size-14')
    expect(parentClass(logo.element)).not.toContain('-mt-')
    expect(logo.element.parentElement!.parentElement!.className).toContain('items-start')
  })

  it('with neither: monogram on elevated and compact header', () => {
    const wrapper = make({ ...FULL, logoUrl: undefined, bannerUrl: undefined })

    expect(wrapper.find('img').exists()).toBe(false)
    const monogram = wrapper.get('[role="img"]')
    expect(monogram.attributes('aria-label')).toBe('Monograma de Pura Navaja')
    expect(monogram.text()).toBe('PN')
    expect(parentClass(monogram.element)).toContain('bg-elevated')
    expect(parentClass(monogram.element)).toContain('size-14')
  })

  it('falls back to the monogram when the logo fails to load', async () => {
    const wrapper = make({ ...FULL, bannerUrl: undefined })

    await wrapper.get('img').trigger('error')

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.get('[role="img"]').text()).toBe('PN')
    expect(wrapper.get('h1').text()).toBe('Pura Navaja')
  })

  it('falls back to the compact header when the banner fails to load', async () => {
    const wrapper = make(FULL)

    await wrapper.get('img[loading="lazy"]').trigger('error')

    expect(wrapper.find('img[loading="lazy"]').exists()).toBe(false)
    expect(parentClass(wrapper.get('img[alt="Logo de Pura Navaja"]').element)).toContain('size-14')
  })

  it('works with what the API delivers today: name, city and phone', () => {
    const wrapper = make({ name: 'Pura Navaja', city: 'San José', phone: '+506 2222 3333' })

    expect(wrapper.get('h1').text()).toBe('Pura Navaja')
    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.text()).toContain('San José')
    expect(wrapper.get('a[href^="tel:"]').text()).toBe('+506 2222 3333')
    expect(wrapper.find('a[target="_blank"]').exists()).toBe(false)
    expect(wrapper.get('[role="img"]').text()).toBe('PN')
  })

  it('puts city and phone in one row when there is no address', () => {
    const wrapper = make({ name: 'Pura Navaja', city: 'San José', phone: '+506 2222 3333' })

    const tel = wrapper.get('a[href^="tel:"]')
    const city = wrapper.findAll('span').find((span) => span.text() === 'San José')!
    expect(city.element.parentElement).toBe(tel.element.parentElement)
  })

  it('leaves no empty wrapper when address, networks, banner and phone are absent', () => {
    const wrapper = make({ name: 'Pura Navaja', city: '', phone: '' })

    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.find('ul').exists()).toBe(false)
    expect(wrapper.find('img').exists()).toBe(false)
    const text = wrapper.get('h1').element.parentElement!
    // Only the title remains in the text column: no empty rows below it.
    expect(text.children).toHaveLength(1)
  })

  it('does not render the networks row for networks that are not valid', () => {
    const wrapper = make({
      name: 'Pura Navaja',
      city: 'San José',
      phone: '',
      whatsapp: 'http://wa.me/1',
    })

    expect(wrapper.find('ul').exists()).toBe(false)
    expect(wrapper.get('h1').element.parentElement!.children).toHaveLength(2)
  })

  it('takes its horizontal margins from the page container, not its own', () => {
    expect(make(FULL).html()).not.toMatch(/\bpx-5\b|\bmd:px-8\b/)
  })

  it.each([
    ['Pura Navaja', 'PN'],
    ['Barbería de Don Chepe', 'BD'],
    ['La Fade Lab', 'FL'],
    ['Ab', 'A'],
    ['don chepe', 'DC'],
  ])('initials of "%s" are %s', (name, expected) => {
    const wrapper = make({ name, city: 'San José', phone: '' })
    expect(wrapper.get('[role="img"]').text()).toBe(expected)
  })
})
