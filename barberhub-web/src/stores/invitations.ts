import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api, type components } from '@/api'

/**
 * The contract leaves `servicios` of `Invitacion` as `unknown[]`, although the API always sends service ids. This is the
 * shape it really has; when the contract types it, `components['schemas']['Invitacion']` replaces it.
 */
export type Invitation = Omit<components['schemas']['Invitacion'], 'servicios'> & {
  servicios: string[]
}
export type InvitationInput = components['schemas']['InvitarColaborador']

export type InvitationsStatus = 'idle' | 'loading' | 'ready' | 'error'

const INVITATIONS = '/barberias/{barberia}/administracion/invitaciones'
const ONE = '/barberias/{barberia}/administracion/invitaciones/{invitacion}'

/**
 * The pending invitations of the active shop as the owner sees them (RF-06). The shop travels in the route of every
 * call, never in the body (RD-18). The token of a link never reaches this store: it only travels in the email. Every
 * action throws the ApiError of the client.
 */
export const useInvitationStore = defineStore('invitations', () => {
  const items = ref<Invitation[]>([])
  const status = ref<InvitationsStatus>('idle')
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
      const { data } = await api.GET(INVITATIONS, { params: { path: { barberia } } })
      if (current !== generation) return
      items.value = data!.data as Invitation[]
      status.value = 'ready'
    } catch {
      if (current !== generation) return
      status.value = 'error'
    }
  }

  /** Invites an email; inviting it again replaces its pending invitation, so the old one leaves the list. */
  async function invite(barberia: string, input: InvitationInput): Promise<Invitation> {
    const { data } = await api.POST(INVITATIONS, { params: { path: { barberia } }, body: input })
    const invitation = data!.data as Invitation
    items.value = [invitation, ...items.value.filter((item) => item.correo !== invitation.correo)]
    return invitation
  }

  /** Cancels a pending invitation: its link stops working. A 404 means it is gone already, and the list agrees. */
  async function cancel(barberia: string, invitacion: string): Promise<void> {
    await api.DELETE(ONE, { params: { path: { barberia, invitacion } } })
    items.value = items.value.filter((item) => item.id !== invitacion)
  }

  function clear(): void {
    generation++
    items.value = []
    shopId.value = null
    status.value = 'idle'
  }

  return { items, status, shopId, load, invite, cancel, clear }
})
