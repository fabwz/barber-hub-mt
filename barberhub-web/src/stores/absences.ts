import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api, type components } from '@/api'

export type Absence = components['schemas']['Ausencia']
export type AbsenceInput = components['schemas']['RegistrarAusencia']

export type AbsencesStatus = 'idle' | 'loading' | 'ready' | 'error'

const ABSENCES = '/barberias/{barberia}/administracion/ausencias'
const ONE = '/barberias/{barberia}/administracion/ausencias/{ausencia}'

function byStart(a: Absence, b: Absence): number {
  return a.inicio.localeCompare(b.inicio)
}

/**
 * The blocks of agenda of the active shop that have not ended (RF-07). The shop travels in the route of every call,
 * never in the body (RD-18). Saving over reservations is a 409 `ausencia-con-reservas` with the list; every action
 * throws the ApiError of the client and the view decides what to show.
 */
export const useAbsencesStore = defineStore('absences', () => {
  const items = ref<Absence[]>([])
  const status = ref<AbsencesStatus>('idle')
  /** The shop `items` belong to, so a list of one shop is never shown for another. */
  const shopId = ref<string | null>(null)

  // A newer load or a reset drops the answer of an older one.
  let generation = 0

  async function load(barberia: string): Promise<void> {
    const current = ++generation
    if (shopId.value !== barberia) items.value = []
    shopId.value = barberia
    status.value = 'loading'
    try {
      const { data } = await api.GET(ABSENCES, { params: { path: { barberia } } })
      if (current !== generation) return
      items.value = [...data!.data].sort(byStart)
      status.value = 'ready'
    } catch {
      if (current !== generation) return
      status.value = 'error'
    }
  }

  async function add(barberia: string, input: AbsenceInput): Promise<Absence> {
    const { data } = await api.POST(ABSENCES, { params: { path: { barberia } }, body: input })
    const created = data!.data
    items.value = [...items.value, created].sort(byStart)
    return created
  }

  async function remove(barberia: string, ausencia: string): Promise<void> {
    await api.DELETE(ONE, { params: { path: { barberia, ausencia } } })
    items.value = items.value.filter((item) => item.id !== ausencia)
  }

  function clear(): void {
    generation++
    items.value = []
    shopId.value = null
    status.value = 'idle'
  }

  return { items, status, shopId, load, add, remove, clear }
})
