/** A reservation that stops a block from being saved (409 `ausencia-con-reservas`, CO-06). */
export interface ConflictingReservation {
  id: string
  start: string
  end: string
  service: string
  customer: string
  /** Only a confirmed reservation can be moved; a temporary one waits for its payment and can only be cancelled. */
  temporary: boolean
}

/** The contract does not type the members of the 409, so each one is read defensively. */
export function readConflicts(list: unknown[]): ConflictingReservation[] {
  return list.flatMap((entry): ConflictingReservation[] => {
    if (typeof entry !== 'object' || entry === null) return []
    const { id, inicio, fin, servicio, cliente, estado } = entry as Record<string, unknown>
    if (typeof id !== 'string' || typeof inicio !== 'string') return []
    return [
      {
        id,
        start: inicio,
        end: typeof fin === 'string' ? fin : inicio,
        service: typeof servicio === 'string' ? servicio : '',
        customer: typeof cliente === 'string' ? cliente : '',
        temporary: estado === 'temporal',
      },
    ]
  })
}
