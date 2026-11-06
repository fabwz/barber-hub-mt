import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api, isApiError, type components } from '@/api'

export type PublicShop = components['schemas']['BarberiaPublica']
export type PublicService = components['schemas']['ServicioPublico']

export type ShopStatus = 'idle' | 'loading' | 'ready' | 'not-found' | 'error'

/** The shop reached through its public link, with its catalog of active services. */
export interface LoadedShop {
  /** Identifier used in `/barberias/{barberia}/...` routes. */
  id: string
  /** Public link (the `:enlace` of the route). */
  link: string
  profile: PublicShop
  services: PublicService[]
}

export const useShopStore = defineStore('shop', () => {
  const shop = ref<LoadedShop | null>(null)
  const status = ref<ShopStatus>('idle')

  // The link being fetched and its promise, so two calls for the same link share one request.
  let pendingLink: string | null = null
  let pending: Promise<void> | null = null

  async function fetchShop(link: string): Promise<void> {
    try {
      const { data: found } = await api.GET('/enlaces/{enlace}', {
        params: { path: { enlace: link } },
      })
      const profile = found!.data
      const { data: catalog } = await api.GET('/barberias/{barberia}/servicios', {
        params: { path: { barberia: profile.id } },
      })
      // The view moved to another link while this one was loading: drop this result.
      if (pendingLink !== link) return
      shop.value = { id: profile.id, link, profile, services: catalog!.data }
      status.value = 'ready'
    } catch (error) {
      if (pendingLink !== link) return
      status.value = isApiError(error) && error.status === 404 ? 'not-found' : 'error'
    }
  }

  /** Loads the shop for a link. Does not ask again for what it already has for that link. */
  function load(link: string): Promise<void> {
    if (status.value === 'ready' && shop.value?.link === link) return Promise.resolve()
    if (status.value === 'loading' && pendingLink === link && pending) return pending
    shop.value = null
    status.value = 'loading'
    pendingLink = link
    const request = fetchShop(link).finally(() => {
      if (pending === request) {
        pending = null
        pendingLink = null
      }
    })
    pending = request
    return request
  }

  return { shop, status, load }
})
