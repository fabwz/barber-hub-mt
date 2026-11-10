import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { api, type components } from '@/api'

/** Only the id of the chosen shop is kept here (sessionStorage, like the session). Never the token. */
export const ACTIVE_SHOP_STORAGE_KEY = 'barberhub.shop'

type Membership = components['schemas']['MiBarberia']

/** Roles of the contract that belong to the staff; `cliente` is left out (UP-01). */
export type StaffRole = Exclude<Membership['rol'], 'cliente'>

export interface StaffShop {
  /** UUID used as `{barberia}` in the routes. */
  id: string
  name: string
  link: string
  role: StaffRole
}

export type StaffShopsStatus = 'idle' | 'loading' | 'ready' | 'error'

function readStoredId(): string | null {
  try {
    const raw = globalThis.sessionStorage.getItem(ACTIVE_SHOP_STORAGE_KEY)
    if (raw === null) return null
    const data: unknown = JSON.parse(raw)
    const id = typeof data === 'object' && data !== null ? (data as { id?: unknown }).id : null
    return typeof id === 'string' && id !== '' ? id : null
  } catch {
    // No storage (blocked, private window): the choice lives only in memory.
    return null
  }
}

function writeStoredId(id: string | null): void {
  try {
    if (id === null) globalThis.sessionStorage.removeItem(ACTIVE_SHOP_STORAGE_KEY)
    else globalThis.sessionStorage.setItem(ACTIVE_SHOP_STORAGE_KEY, JSON.stringify({ id }))
  } catch {
    // Same as above: ignore.
  }
}

function isStaff(item: Membership): item is Membership & { rol: StaffRole } {
  return item.rol === 'dueno' || item.rol === 'colaborador'
}

export const useActiveShopStore = defineStore('activeShop', () => {
  const shops = ref<StaffShop[]>([])
  const status = ref<StaffShopsStatus>('idle')
  // Restored from sessionStorage, but only trusted once the current list confirms it.
  const activeId = ref<string | null>(readStoredId())

  const active = computed<StaffShop | null>(
    () => shops.value.find((shop) => shop.id === activeId.value) ?? null,
  )

  // `clear()` bumps the generation so an answer that arrives after it (a 401, a logout) is dropped.
  let generation = 0
  let pending: Promise<void> | null = null

  async function fetchStaffShops(current: number): Promise<void> {
    try {
      const { data } = await api.GET('/mis-barberias')
      if (current !== generation) return
      shops.value = data!.data
        .filter(isStaff)
        .map((item) => ({ id: item.id, name: item.nombre, link: item.enlace, role: item.rol }))
      // A stored choice that is no longer in the list is discarded.
      if (activeId.value !== null && active.value === null) {
        activeId.value = null
        writeStoredId(null)
      }
      status.value = 'ready'
    } catch {
      if (current !== generation) return
      status.value = 'error'
    }
  }

  /** Loads the shops where the person is owner or collaborator. Never throws: the outcome is `status`. */
  function loadStaffShops(): Promise<void> {
    if (status.value === 'loading' && pending) return pending
    status.value = 'loading'
    const request = fetchStaffShops(generation).finally(() => {
      if (pending === request) pending = null
    })
    pending = request
    return request
  }

  /** Makes a shop of the loaded list the active one. An id outside the list is ignored. */
  function select(id: string): void {
    if (!shops.value.some((shop) => shop.id === id)) return
    activeId.value = id
    writeStoredId(id)
  }

  /** Forgets everything; called when the session closes or the API answers 401. */
  function clear(): void {
    generation += 1
    pending = null
    shops.value = []
    activeId.value = null
    status.value = 'idle'
    writeStoredId(null)
  }

  return { shops, status, active, loadStaffShops, select, clear }
})
