import type { InjectionKey } from 'vue'

/** What the agenda gives the detail panel that lives inside it (a child route). */
export interface AgendaContext {
  /** Reads the day again without clearing the list, after an action changed a reservation. */
  reload: () => Promise<void>
}

export const agendaContextKey: InjectionKey<AgendaContext> = Symbol('agenda-context')
