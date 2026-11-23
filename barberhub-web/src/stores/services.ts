import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api, type components } from '@/api'

export type Service = components['schemas']['Servicio']
export type ServiceInput = components['schemas']['CrearServicio']
export type ServicePatch = components['schemas']['ActualizarServicio']

export type ServicesStatus = 'idle' | 'loading' | 'ready' | 'error'

/**
 * The services of the active shop as the owner sees them (RF-03): active and inactive ones. The shop travels
 * in the route of every call, never in the body (RD-18). Every action throws the ApiError of the client.
 */
export const useServiceAdminStore = defineStore('serviceAdmin', () => {
  const items = ref<Service[]>([])
  const status = ref<ServicesStatus>('idle')
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
      const { data } = await api.GET('/barberias/{barberia}/administracion/servicios', {
        params: { path: { barberia } },
      })
      if (current !== generation) return
      items.value = data!.data
      status.value = 'ready'
    } catch {
      if (current !== generation) return
      status.value = 'error'
    }
  }

  function replace(service: Service): void {
    const index = items.value.findIndex((item) => item.id === service.id)
    if (index === -1) items.value = [...items.value, service]
    else items.value = items.value.map((item) => (item.id === service.id ? service : item))
    items.value = [...items.value].sort((a, b) => a.nombre.localeCompare(b.nombre, 'es'))
  }

  async function create(barberia: string, input: ServiceInput): Promise<Service> {
    const { data } = await api.POST('/barberias/{barberia}/administracion/servicios', {
      params: { path: { barberia } },
      body: input,
    })
    const service = data!.data
    replace(service)
    return service
  }

  async function update(barberia: string, servicio: string, patch: ServicePatch): Promise<Service> {
    const { data } = await api.PATCH('/barberias/{barberia}/administracion/servicios/{servicio}', {
      params: { path: { barberia, servicio } },
      body: patch,
    })
    const service = data!.data
    replace(service)
    return service
  }

  /** Uploads or replaces the image (SV-06, RF-46) as `multipart/form-data`; the API reads the real type. */
  async function uploadImage(barberia: string, servicio: string, file: File): Promise<Service> {
    const { data } = await api.POST(
      '/barberias/{barberia}/administracion/servicios/{servicio}/imagen',
      {
        params: { path: { barberia, servicio } },
        // The contract types the file as a string; the serializer builds the real form.
        body: { imagen: '' },
        bodySerializer: () => {
          const form = new FormData()
          form.append('imagen', file)
          return form
        },
      },
    )
    const service = data!.data
    replace(service)
    return service
  }

  function clear(): void {
    generation++
    items.value = []
    shopId.value = null
    status.value = 'idle'
  }

  return { items, status, shopId, load, create, update, uploadImage, clear }
})
