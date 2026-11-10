import type { StaffRole } from '@/stores/activeShop'

/** The role of the contract (`dueno`, `colaborador`) as the interface shows it, in text. */
export const ROLE_LABELS: Record<StaffRole, string> = {
  dueno: 'Dueño',
  colaborador: 'Colaborador',
}
