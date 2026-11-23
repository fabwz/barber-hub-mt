/** The days of the week as the API numbers them: 1 is Monday and 7 is Sunday (HO-01). */
export const WEEKDAYS = [
  { day: 1, label: 'Lunes' },
  { day: 2, label: 'Martes' },
  { day: 3, label: 'Miércoles' },
  { day: 4, label: 'Jueves' },
  { day: 5, label: 'Viernes' },
  { day: 6, label: 'Sábado' },
  { day: 7, label: 'Domingo' },
] as const

export function weekdayLabel(day: number): string {
  return WEEKDAYS.find((item) => item.day === day)?.label ?? ''
}

/** «09:00–12:30 y 14:00–19:00» for the opening hours of a day, or «Cerrada» when there are none. */
export function hoursLabel(ranges: Array<{ abre: string; cierra: string }>): string {
  if (ranges.length === 0) return 'Cerrada'
  return [...ranges]
    .sort((a, b) => a.abre.localeCompare(b.abre))
    .map((range) => `${range.abre}–${range.cierra}`)
    .join(' y ')
}
