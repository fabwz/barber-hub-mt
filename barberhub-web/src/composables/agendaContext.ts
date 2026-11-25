import type { InjectionKey, Ref } from 'vue'

import type { AgendaChanges } from '@/composables/useLiveAgenda'

/** What the agenda gives the detail panel that lives inside it (a child route). */
export interface AgendaContext {
  /** Reads the day again without clearing the list, after an action changed a reservation. */
  reload: () => Promise<void>
  /** What the live agenda reported: the detail panel reads its reservation again when it is one of them. */
  changes: Ref<AgendaChanges | null>
}

export const agendaContextKey: InjectionKey<AgendaContext> = Symbol('agenda-context')
