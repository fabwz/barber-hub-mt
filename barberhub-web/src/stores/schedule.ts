import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api, type components } from '@/api'

export type TimeSlot = components['schemas']['Franja']
export type Closure = components['schemas']['Cierre']
export type SlotInput = components['schemas']['CrearFranja']
export type ClosureInput = components['schemas']['RegistrarCierre']

export type ScheduleStatus = 'idle' | 'loading' | 'ready' | 'error'

/** `{barberia}` of the routes of the schedule; it always travels in the route, never in the body (RD-18). */
const SLOTS = '/barberias/{barberia}/administracion/horario'
const CLOSURES = '/barberias/{barberia}/administracion/cierres'

function byStart(a: TimeSlot, b: TimeSlot): number {
  return a.dia - b.dia || a.abre.localeCompare(b.abre)
}

/**
 * The weekly schedule and the closures of the active shop (RF-04, HO-01, HO-03). Every change is its own call and
 * applies at once: there is no «save the week». Every action throws the ApiError of the client.
 */
export const useScheduleStore = defineStore('schedule', () => {
  const slots = ref<TimeSlot[]>([])
  const closures = ref<Closure[]>([])
  const status = ref<ScheduleStatus>('idle')
  const shopId = ref<string | null>(null)

  // A newer load or a reset drops the answer of an older one.
  let generation = 0

  async function load(barberia: string): Promise<void> {
    const current = ++generation
    if (shopId.value !== barberia) {
      slots.value = []
      closures.value = []
    }
    shopId.value = barberia
    status.value = 'loading'
    try {
      const params = { params: { path: { barberia } } }
      const [slotsAnswer, closuresAnswer] = await Promise.all([
        api.GET(SLOTS, params),
        api.GET(CLOSURES, params),
      ])
      if (current !== generation) return
      slots.value = [...slotsAnswer.data!.data].sort(byStart)
      closures.value = closuresAnswer.data!.data
      status.value = 'ready'
    } catch {
      if (current !== generation) return
      status.value = 'error'
    }
  }

  async function addSlot(barberia: string, input: SlotInput): Promise<TimeSlot> {
    const { data } = await api.POST(SLOTS, { params: { path: { barberia } }, body: input })
    const created = data!.data
    slots.value = [...slots.value, created].sort(byStart)
    return created
  }

  async function removeSlot(barberia: string, franja: string): Promise<void> {
    await api.DELETE('/barberias/{barberia}/administracion/horario/{franja}', {
      params: { path: { barberia, franja } },
    })
    slots.value = slots.value.filter((slot) => slot.id !== franja)
  }

  async function addClosure(barberia: string, input: ClosureInput): Promise<Closure> {
    const { data } = await api.POST(CLOSURES, { params: { path: { barberia } }, body: input })
    const created = data!.data
    closures.value = [...closures.value, created].sort((a, b) => a.fecha.localeCompare(b.fecha))
    return created
  }

  async function removeClosure(barberia: string, cierre: string): Promise<void> {
    await api.DELETE('/barberias/{barberia}/administracion/cierres/{cierre}', {
      params: { path: { barberia, cierre } },
    })
    closures.value = closures.value.filter((closure) => closure.id !== cierre)
  }

  function clear(): void {
    generation++
    slots.value = []
    closures.value = []
    shopId.value = null
    status.value = 'idle'
  }

  return {
    slots,
    closures,
    status,
    shopId,
    load,
    addSlot,
    removeSlot,
    addClosure,
    removeClosure,
    clear,
  }
})
