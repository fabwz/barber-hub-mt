import { addDays, dateOf, formatShortDate, timeOf } from '@/composables/format'

/** The two instants of a block as the API writes them: ISO 8601 with the offset of Costa Rica; `fin` is not included. */
export interface AbsenceRange {
  inicio: string
  fin: string
}

/** A block without hours takes whole days: it starts and ends at midnight. */
export function takesWholeDays({ inicio, fin }: AbsenceRange): boolean {
  return timeOf(inicio) === '00:00' && timeOf(fin) === '00:00'
}

/**
 * How the owner reads a block: «jue 5 nov, 14:00–16:00» for hours of one day, «lun 9 nov – vie 13 nov» for whole days
 * (the last day is the one before the midnight that ends the range) and «jue 5 nov, 22:00 – vie 6 nov, 06:00» when
 * the hours cross days.
 */
export function absencePeriodLabel(range: AbsenceRange): string {
  const first = dateOf(range.inicio)
  if (takesWholeDays(range)) {
    const last = addDays(dateOf(range.fin), -1)
    return first === last
      ? formatShortDate(first)
      : `${formatShortDate(first)} – ${formatShortDate(last)}`
  }
  const last = dateOf(range.fin)
  return first === last
    ? `${formatShortDate(first)}, ${timeOf(range.inicio)}–${timeOf(range.fin)}`
    : `${formatShortDate(first)}, ${timeOf(range.inicio)} – ${formatShortDate(last)}, ${timeOf(range.fin)}`
}
