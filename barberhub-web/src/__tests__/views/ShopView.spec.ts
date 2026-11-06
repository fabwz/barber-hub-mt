import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import ShopView from '@/views/ShopView.vue'
import { jsonResponse, mockFetch } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { SHOP, shopHandler } from '../helpers/shop'

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
  document.title = ''
})

describe('ShopView', () => {
  it('shows skeletons while loading, then the header and the catalog', async () => {
    let release: () => void = () => {}
    const gate = new Promise<void>((resolve) => (release = resolve))
    mockFetch(async (request) => {
      await gate
      return shopHandler(request)
    })
    const { wrapper } = await mountView(ShopView, '/b/pura-navaja')

    expect(wrapper.get('main').attributes('aria-busy')).toBe('true')
    expect(wrapper.find('.animate-pulse').exists()).toBe(true)
    expect(wrapper.find('h1').exists()).toBe(false)

    release()
    await flushPromises()

    expect(wrapper.get('main').attributes('aria-busy')).toBe('false')
    expect(wrapper.find('.animate-pulse').exists()).toBe(false)
    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe(SHOP.nombre)
    expect(wrapper.get('h2').text()).toBe('Servicios')
    const rows = wrapper.findAll('section ul > li')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('Corte clásico')
    expect(rows[0]!.text()).toContain('₡7.000')
    expect(rows[1]!.text()).toContain('1 h 30 min')
    expect(wrapper.find('a[href^="tel:"]').exists()).toBe(true)
  })

  it('centers the content in a 1280 px container and limits the services to 720 px', async () => {
    mockFetch(shopHandler)
    const { wrapper } = await mountView(ShopView, '/b/pura-navaja')
    await flushPromises()

    const main = wrapper.get('main')
    expect(main.classes()).toEqual(expect.arrayContaining(['mx-auto', 'max-w-7xl']))
    const content = main.get('div')
    expect(content.classes()).toEqual(expect.arrayContaining(['px-5', 'md:px-8']))
    expect(content.get('header').exists()).toBe(true)
    const services = content.get('section')
    expect(services.classes()).toContain('max-w-180')
    expect(content.get('header').classes()).not.toContain('max-w-180')
  })

  it('sets document.title and restores it on leaving', async () => {
    document.title = 'Antes'
    mockFetch(shopHandler)
    const { wrapper } = await mountView(ShopView, '/b/pura-navaja')
    await flushPromises()

    expect(document.title).toBe('Pura Navaja · BarberHub')

    wrapper.unmount()
    expect(document.title).toBe('Antes')
  })

  it('shows «Esta barbería no existe.» with a way to / on a 404', async () => {
    mockFetch(shopHandler)
    const { wrapper } = await mountView(ShopView, '/b/no-existe')
    await flushPromises()

    expect(wrapper.text()).toContain('Esta barbería no existe.')
    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('a[href="/"]').text()).toBe('Ir al inicio')
  })

  it('shows the error with «Reintentar», which asks again', async () => {
    let fail = true
    const fetchMock = mockFetch((request) => {
      if (fail) throw new TypeError('Failed to fetch')
      return shopHandler(request)
    })
    const { wrapper } = await mountView(ShopView, '/b/pura-navaja')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('no pudimos cargar la barbería')
    const calls = fetchMock.mock.calls.length

    fail = false
    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(fetchMock.mock.calls.length).toBeGreaterThan(calls)
    expect(wrapper.get('h1').text()).toBe(SHOP.nombre)
  })

  it('shows an empty state when the shop has no active services', async () => {
    mockFetch((request) =>
      new URL(request.url).pathname.endsWith('/servicios')
        ? jsonResponse(200, { data: [] })
        : shopHandler(request),
    )
    const { wrapper } = await mountView(ShopView, '/b/pura-navaja')
    await flushPromises()

    expect(wrapper.text()).toContain('Esta barbería aún no tiene servicios.')
    expect(wrapper.find('section ul').exists()).toBe(false)
  })

  it('reloads when the route parameter changes', async () => {
    mockFetch(shopHandler)
    const { wrapper, router } = await mountView(ShopView, '/b/pura-navaja')
    await flushPromises()
    expect(wrapper.get('h1').text()).toBe(SHOP.nombre)

    await router.push('/b/no-existe')
    await flushPromises()

    expect(wrapper.text()).toContain('Esta barbería no existe.')
  })
})
