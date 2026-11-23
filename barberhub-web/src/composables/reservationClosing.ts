/**
 * Minutes after the start from which a reservation may be marked as absent (CO-05, RE-04).
 * The contract does not carry this number: it only says it in the 409 `conflicto` of the closing
 * ("`ausente`, 10 minutos después de ese inicio (tolerancia)"). It lives here and nowhere else.
 */
export const NO_SHOW_TOLERANCE_MINUTES = 10

export interface ClosingAvailability {
  /** «Marcar atendida» is allowed: the start has passed. */
  canAttend: boolean
  /** «Marcar ausente» is allowed: the start plus the tolerance has passed. */
  canMarkAbsent: boolean
}

/** What the person may close at `now`. The API decides in the end; this only avoids a refused click. */
export function closingAvailability(start: string, now: number = Date.now()): ClosingAvailability {
  const startsAt = Date.parse(start)
  if (Number.isNaN(startsAt)) return { canAttend: false, canMarkAbsent: false }
  return {
    canAttend: now >= startsAt,
    canMarkAbsent: now >= startsAt + NO_SHOW_TOLERANCE_MINUTES * 60_000,
  }
}

/** «11:40»: the start plus the tolerance, as wall time from the ISO text (never converted). */
export function absentFrom(start: string): string {
  const total =
    Number(start.slice(11, 13)) * 60 + Number(start.slice(14, 16)) + NO_SHOW_TOLERANCE_MINUTES
  const pad = (value: number): string => String(value).padStart(2, '0')
  return `${pad(Math.floor(total / 60) % 24)}:${pad(total % 60)}`
}
