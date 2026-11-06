import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useShopStore } from '@/stores/shop'
import { mockFetch } from '../helpers/http'
import { SERVICES, SHOP, shopHandler } from '../helpers/shop'

beforeEach(() => setActivePinia(createPinia()))
afterEach(() => vi.unstubAllGlobals())

describe('useShopStore', () => {
  it('loads the shop and its catalog', async () => {
    mockFetch(shopHandler)
    const store = useShopStore()
    expect(store.status).toBe('idle')

    await store.load('pura-navaja')

    expect(store.status).toBe('ready')
    expect(store.shop).toEqual({
      id: SHOP.id,
      link: 'pura-navaja',
      profile: SHOP,
      services: SERVICES,
    })
  })

  it('does not ask again for the same link', async () => {
    const fetchMock = mockFetch(shopHandler)
    const store = useShopStore()

    await store.load('pura-navaja')
    await store.load('pura-navaja')

    expect(fetchMock).toHaveBeenCalledTimes(2) // shop + catalog, once
  })

  it('shares one request between two simultaneous calls for the same link', async () => {
    const fetchMock = mockFetch(shopHandler)
    const store = useShopStore()

    await Promise.all([store.load('pura-navaja'), store.load('pura-navaja')])

    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('asks again when the link changes', async () => {
    const fetchMock = mockFetch(shopHandler)
    const store = useShopStore()
    await store.load('pura-navaja')
    await store.load('otra')

    expect(fetchMock).toHaveBeenCalledTimes(3)
    expect(store.status).toBe('not-found')
    expect(store.shop).toBeNull()
  })

  it('reports not-found on a 404', async () => {
    mockFetch(shopHandler)
    const store = useShopStore()

    await store.load('no-existe')

    expect(store.status).toBe('not-found')
    expect(store.shop).toBeNull()
  })

  it('reports error on a network failure and loads again after it', async () => {
    let fail = true
    mockFetch((request) => {
      if (fail) throw new TypeError('Failed to fetch')
      return shopHandler(request)
    })
    const store = useShopStore()

    await store.load('pura-navaja')
    expect(store.status).toBe('error')

    fail = false
    await store.load('pura-navaja')
    expect(store.status).toBe('ready')
  })

  it('ignores a stale response when the link changed meanwhile', async () => {
    let open: () => void = () => {}
    const gate = new Promise<void>((resolve) => (open = resolve))
    mockFetch(async (request) => {
      await gate
      return shopHandler(request)
    })
    const store = useShopStore()

    const first = store.load('pura-navaja')
    const second = store.load('no-existe')
    open()
    await Promise.all([first, second])

    expect(store.status).toBe('not-found')
    expect(store.shop).toBeNull()
  })
})
