import { computed, type ComputedRef } from 'vue'

import type { StaffRole } from '@/stores/activeShop'

/** What each role may see and do in the agenda. The API still decides (403 and 404): this only hides. */
export interface AgendaPermissions {
  /** The collaborator filter and the collaborator on each row: only the owner sees several agendas. */
  canFilterByCollaborator: boolean
  canReschedule: boolean
  canCancel: boolean
  /** Register a reservation for a person without an account (RF-12): only the owner. */
  canRegisterAssisted: boolean
  canClose: boolean
  /** The total of the reservation (the collaborator sees only the pending balance, PA-10). */
  canSeeTotal: boolean
  /** The status history with author and date (RE-09): only the owner. */
  canSeeHistory: boolean
  /** The payment detail and the deposit: only the owner, and the API does not deliver it yet. */
  canSeePayment: boolean
  /** Title of the screen: the collaborator sees «Mi agenda». */
  title: string
}

export function agendaPermissions(role: StaffRole): AgendaPermissions {
  const owner = role === 'dueno'
  return {
    canFilterByCollaborator: owner,
    canReschedule: owner,
    canCancel: owner,
    canRegisterAssisted: owner,
    canClose: true,
    canSeeTotal: owner,
    canSeeHistory: owner,
    canSeePayment: owner,
    title: owner ? 'Agenda' : 'Mi agenda',
  }
}

export function useAgendaPermissions(role: () => StaffRole): ComputedRef<AgendaPermissions> {
  return computed(() => agendaPermissions(role()))
}
