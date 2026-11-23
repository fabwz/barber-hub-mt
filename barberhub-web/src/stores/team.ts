import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api, type components } from '@/api'

/**
 * The contract leaves `servicios`, `turnos` and `pausas` of `Colaborador` as `unknown[]`, although the API always sends
 * service ids and entries of the schedule. This is the shape they really have; when the contract types them,
 * `components['schemas']['Colaborador']` replaces it.
 */
export type Collaborator = Omit<
  components['schemas']['Colaborador'],
  'servicios' | 'turnos' | 'pausas'
> & {
  servicios: string[]
  turnos: ScheduleEntry[]
  pausas: ScheduleEntry[]
}
export type CollaboratorInput = components['schemas']['CrearColaborador']
export type ScheduleInput = components['schemas']['CambiarHorarioDeColaborador']
export type ScheduleEntry = ScheduleInput['turnos'][number]

export type TeamStatus = 'idle' | 'loading' | 'ready' | 'error'

const COLLABORATORS = '/barberias/{barberia}/administracion/colaboradores'
const ONE = '/barberias/{barberia}/administracion/colaboradores/{colaborador}'

/**
 * The collaborators of the active shop as the owner sees them (RF-05). The shop travels in the route of every call,
 * never in the body (RD-18). Creating a collaborator is three calls (the account, its services, its schedule): the
 * view runs them one by one and, if one fails, repeats only that one, so none of this store creates twice.
 * Every action throws the ApiError of the client.
 */
export const useTeamStore = defineStore('team', () => {
  const items = ref<Collaborator[]>([])
  const status = ref<TeamStatus>('idle')
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
      const { data } = await api.GET(COLLABORATORS, { params: { path: { barberia } } })
      if (current !== generation) return
      items.value = data!.data as Collaborator[]
      status.value = 'ready'
    } catch {
      if (current !== generation) return
      status.value = 'error'
    }
  }

  function replace(collaborator: Collaborator): void {
    const exists = items.value.some((item) => item.id === collaborator.id)
    items.value = exists
      ? items.value.map((item) => (item.id === collaborator.id ? collaborator : item))
      : [...items.value, collaborator]
  }

  /** The account, the collaborator role and the profile, active and without services or schedule (UP-04). */
  async function createAccount(barberia: string, input: CollaboratorInput): Promise<Collaborator> {
    const { data } = await api.POST(COLLABORATORS, {
      params: { path: { barberia } },
      body: input,
    })
    const collaborator = data!.data as Collaborator
    replace(collaborator)
    return collaborator
  }

  /** The profile of the owner (CO-02); a 409 says it already has one. */
  async function createOwnProfile(barberia: string): Promise<Collaborator> {
    const { data } = await api.POST(
      '/barberias/{barberia}/administracion/colaboradores/mi-perfil',
      {
        params: { path: { barberia } },
      },
    )
    const collaborator = data!.data as Collaborator
    replace(collaborator)
    return collaborator
  }

  /** Replaces the whole list of services the collaborator offers (CO-01). */
  async function replaceServices(
    barberia: string,
    colaborador: string,
    servicios: string[],
  ): Promise<Collaborator> {
    const { data } = await api.PUT(`${ONE}/servicios`, {
      params: { path: { barberia, colaborador } },
      body: { servicios },
    })
    const collaborator = data!.data as Collaborator
    replace(collaborator)
    return collaborator
  }

  /** Replaces all the shifts and breaks of the week (HO-02). Errors name `turnos.N` or `pausas.N`. */
  async function replaceSchedule(
    barberia: string,
    colaborador: string,
    schedule: ScheduleInput,
  ): Promise<Collaborator> {
    const { data } = await api.PUT(`${ONE}/horario`, {
      params: { path: { barberia, colaborador } },
      body: schedule,
    })
    const collaborator = data!.data as Collaborator
    replace(collaborator)
    return collaborator
  }

  /** Activates or deactivates (CO-03). Deactivating with confirmed reservations ahead is a 409 with the list. */
  async function setActive(
    barberia: string,
    colaborador: string,
    activo: boolean,
  ): Promise<Collaborator> {
    const { data } = await api.PATCH(ONE, {
      params: { path: { barberia, colaborador } },
      body: { activo },
    })
    const collaborator = data!.data as Collaborator
    replace(collaborator)
    return collaborator
  }

  function clear(): void {
    generation++
    items.value = []
    shopId.value = null
    status.value = 'idle'
  }

  return {
    items,
    status,
    shopId,
    load,
    createAccount,
    createOwnProfile,
    replaceServices,
    replaceSchedule,
    setActive,
    clear,
  }
})
