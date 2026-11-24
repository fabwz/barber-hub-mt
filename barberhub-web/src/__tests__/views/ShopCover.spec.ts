import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import ShopView from '@/views/ShopView.vue'
import { jsonResponse, mockFetch, pathOf } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { SERVICES, SHOP } from '../helpers/shop'

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

function serve(cover: Record<string, unknown>, services: unknown[] = SERVICES) {
  mockFetch((request) => {
    const route = pathOf(request)
    if (route === 'GET /api/v1/enlaces/pura-navaja') {
      return jsonResponse(200, { data: { ...SHOP, lista_espera_activa: false, ...cover } })
    }
    if (route === `GET /api/v1/barberias/${SHOP.id}/servicios`) {
      return jsonResponse(200, { data: services })
    }
    return jsonResponse(404, { status: 404 })
  })
}

async function open() {
  const { wrapper } = await mountView(ShopView, '/b/pura-navaja')
  await flushPromises()
  return wrapper
}

describe('ShopView: the header with the real data of the shop', () => {
  it('shows the logo, the banner, the address and the networks the API brings', async () => {
    serve({
      logo: '/api/v1/archivos/barbershops/x/logo/l.png',
      banner: '/api/v1/archivos/barbershops/x/banner/b.webp',
      direccion: 'Avenida Central, 200 m norte del Parque',
      correo_contacto: 'hola@barberia.cr',
      redes: { instagram: 'https://instagram.com/barberia' },
    })
    const wrapper = await open()

    expect(wrapper.get('img[alt="Logo de Pura Navaja"]').attributes('src')).toBe(
      '/api/v1/archivos/barbershops/x/logo/l.png',
    )
    expect(wrapper.get('header img[loading="lazy"]').attributes('src')).toBe(
      '/api/v1/archivos/barbershops/x/banner/b.webp',
    )
    expect(wrapper.text()).toContain('Avenida Central, 200 m norte del Parque')
    expect(wrapper.get('a[aria-label="Instagram de Pura Navaja"]').attributes('href')).toBe(
      'https://instagram.com/barberia',
    )
    expect(wrapper.find('a[aria-label="Correo de Pura Navaja"]').exists()).toBe(true)
    expect(wrapper.find('a[aria-label="WhatsApp de Pura Navaja"]').exists()).toBe(false)
  })

  it('without logo shows the monogram, and without banner the compact header', async () => {
    serve({})
    const wrapper = await open()

    expect(wrapper.get('[aria-label="Monograma de Pura Navaja"]').text()).toBe('PN')
    expect(wrapper.find('header img').exists()).toBe(false)
    expect(wrapper.find('header > div[aria-hidden="true"]').exists()).toBe(false)
    // None of the example data of the design is left.
    expect(wrapper.text()).not.toContain('Avenida Central')
    expect(wrapper.find('a[href^="mailto:"]').exists()).toBe(false)
  })
})

describe('ShopView: the image of the service', () => {
  it('shows it in the card where the customer chooses the service, and draws nothing without one', async () => {
    serve({}, [
      { ...SERVICES[0], imagen: '/api/v1/archivos/barbershops/x/servicios/c.webp' },
      SERVICES[1],
    ])
    const wrapper = await open()

    const rows = wrapper.findAll('[role="radio"]')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.get('img').attributes('src')).toBe(
      '/api/v1/archivos/barbershops/x/servicios/c.webp',
    )
    // SV-07: a service with no image is shown with none, not even a frame.
    expect(rows[1]!.find('img').exists()).toBe(false)
    expect(rows[1]!.find('svg').exists()).toBe(false)
  })
})
