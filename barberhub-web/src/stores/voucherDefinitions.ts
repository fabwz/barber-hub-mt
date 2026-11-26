import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api, type components } from '@/api'

export type VoucherDefinition = components['schemas']['Bono']
export type VoucherDefinitionInput = components['schemas']['CrearBono']
export type VoucherDefinitionPatch = components['schemas']['ActualizarBono']
export type SoldVoucher = components['schemas']['BonoVendido']

export type VoucherDefinitionsStatus = 'idle' | 'loading' | 'ready' | 'error'

/** Vouchers sold per request; the API allows up to 50 and the screen asks for more by page. */
const SOLD_PAGE_SIZE = 15

/**
 * The vouchers of the active shop as the owner sees them (RF-26, RF-29): the ones it defines, on sale or withdrawn,
 * and the ones its customers bought. The shop travels in the route of every call, never in the body (RD-18).
 * Every action throws the ApiError of the client; the screens decide what to tell the person.
 */
export const useVoucherDefinitionsStore = defineStore('voucherDefinitions', () => {
  const items = ref<VoucherDefinition[]>([])
  const status = ref<VoucherDefinitionsStatus>('idle')
  /** The shop `items` belong to, so a list of one shop is never shown for another. */
  const shopId = ref<string | null>(null)
  /** Whether the shop has the vouchers switched on (RF-09); null until it is known. */
  const vouchersEnabled = ref<boolean | null>(null)

  const sold = ref<SoldVoucher[]>([])
  const soldStatus = ref<VoucherDefinitionsStatus>('idle')
  const soldShopId = ref<string | null>(null)
  const soldPage = ref(0)
  const soldLastPage = ref(0)
  /** «Mostrar más» is running. */
  const soldLoadingMore = ref(false)
  /** The next page failed: the ones already shown stay. */
  const soldMoreFailed = ref(false)

  // A newer load or a reset drops the answer of an older one.
  let generation = 0
  let soldGeneration = 0

  async function load(barberia: string): Promise<void> {
    const current = ++generation
    if (shopId.value !== barberia) {
      items.value = []
      vouchersEnabled.value = null
    }
    shopId.value = barberia
    status.value = 'loading'
    try {
      const path = { barberia }
      const [list, features] = await Promise.all([
        api.GET('/barberias/{barberia}/administracion/bonos', { params: { path } }),
        loadFeatures(barberia),
      ])
      if (current !== generation) return
      items.value = list.data!.data
      vouchersEnabled.value = features
      status.value = 'ready'
    } catch {
      if (current !== generation) return
      status.value = 'error'
    }
  }

  /** The switch of the vouchers (RF-09); a failed read leaves it unknown and the API still has the last word. */
  async function loadFeatures(barberia: string): Promise<boolean | null> {
    try {
      const { data } = await api.GET('/barberias/{barberia}/administracion/funciones', {
        params: { path: { barberia } },
      })
      return data!.data.bonos_activos
    } catch {
      return null
    }
  }

  function replace(voucher: VoucherDefinition): void {
    const exists = items.value.some((item) => item.id === voucher.id)
    const next = exists
      ? items.value.map((item) => (item.id === voucher.id ? voucher : item))
      : [...items.value, voucher]
    items.value = next.sort((a, b) => a.nombre.localeCompare(b.nombre, 'es'))
  }

  async function create(
    barberia: string,
    input: VoucherDefinitionInput,
  ): Promise<VoucherDefinition> {
    const { data } = await api.POST('/barberias/{barberia}/administracion/bonos', {
      params: { path: { barberia } },
      body: input,
    })
    const voucher = data!.data
    replace(voucher)
    return voucher
  }

  async function update(
    barberia: string,
    bono: string,
    patch: VoucherDefinitionPatch,
  ): Promise<VoucherDefinition> {
    const { data } = await api.PATCH('/barberias/{barberia}/administracion/bonos/{bono}', {
      params: { path: { barberia, bono } },
      body: patch,
    })
    const voucher = data!.data
    replace(voucher)
    return voucher
  }

  /** The first page of the vouchers the customers bought, newest first. */
  async function loadSold(barberia: string): Promise<void> {
    const current = ++soldGeneration
    if (soldShopId.value !== barberia) sold.value = []
    soldShopId.value = barberia
    soldStatus.value = 'loading'
    soldMoreFailed.value = false
    soldLoadingMore.value = false
    try {
      const { data } = await api.GET('/barberias/{barberia}/administracion/bonos-vendidos', {
        params: { path: { barberia }, query: { pagina: 1, por_pagina: SOLD_PAGE_SIZE } },
      })
      if (current !== soldGeneration) return
      sold.value = data!.data
      soldPage.value = data!.meta.pagina
      soldLastPage.value = data!.meta.ultima_pagina
      soldStatus.value = 'ready'
    } catch {
      if (current !== soldGeneration) return
      soldStatus.value = 'error'
    }
  }

  /** «Mostrar más»: the next page is added after the ones shown. */
  async function loadMoreSold(barberia: string): Promise<void> {
    if (soldLoadingMore.value || soldPage.value >= soldLastPage.value) return
    const current = soldGeneration
    soldLoadingMore.value = true
    soldMoreFailed.value = false
    try {
      const { data } = await api.GET('/barberias/{barberia}/administracion/bonos-vendidos', {
        params: {
          path: { barberia },
          query: { pagina: soldPage.value + 1, por_pagina: SOLD_PAGE_SIZE },
        },
      })
      if (current !== soldGeneration) return
      sold.value = [...sold.value, ...data!.data]
      soldPage.value = data!.meta.pagina
      soldLastPage.value = data!.meta.ultima_pagina
    } catch {
      if (current !== soldGeneration) return
      soldMoreFailed.value = true
    } finally {
      if (current === soldGeneration) soldLoadingMore.value = false
    }
  }

  function clear(): void {
    generation++
    soldGeneration++
    items.value = []
    shopId.value = null
    vouchersEnabled.value = null
    status.value = 'idle'
    sold.value = []
    soldShopId.value = null
    soldPage.value = 0
    soldLastPage.value = 0
    soldStatus.value = 'idle'
    soldLoadingMore.value = false
    soldMoreFailed.value = false
  }

  return {
    items,
    status,
    shopId,
    vouchersEnabled,
    sold,
    soldStatus,
    soldPage,
    soldLastPage,
    soldLoadingMore,
    soldMoreFailed,
    load,
    create,
    update,
    loadSold,
    loadMoreSold,
    clear,
  }
})
