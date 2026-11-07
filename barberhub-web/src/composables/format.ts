/**
 * Formatos de fecha, hora y dinero de la interfaz. La API entrega las fechas como
 * «AAAA-MM-DD» y las horas como ISO 8601 con el desfase de Costa Rica (-06:00,
 * HO-03). Se tratan como cadenas: nunca se convierten a la zona del navegador.
 */

export const COSTA_RICA_ZONE = 'America/Costa_Rica'

const DAY_NAMES = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb']
const MONTH_NAMES = [
  'ene',
  'feb',
  'mar',
  'abr',
  'may',
  'jun',
  'jul',
  'ago',
  'sep',
  'oct',
  'nov',
  'dic',
]
const MONTH_LONG = [
  'enero',
  'febrero',
  'marzo',
  'abril',
  'mayo',
  'junio',
  'julio',
  'agosto',
  'septiembre',
  'octubre',
  'noviembre',
  'diciembre',
]

const todayFormatter = new Intl.DateTimeFormat('en-CA', {
  timeZone: COSTA_RICA_ZONE,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
})

/** El día de hoy en Costa Rica («AAAA-MM-DD»), sin importar la zona del navegador. */
export function todayInCostaRica(now: Date = new Date()): string {
  const parts = Object.fromEntries(todayFormatter.formatToParts(now).map((p) => [p.type, p.value]))
  return `${parts.year}-${parts.month}-${parts.day}`
}

function split(date: string): [number, number, number] {
  const [year = 0, month = 1, day = 1] = date.split('-').map(Number)
  return [year, month, day]
}

/** Suma días a una fecha «AAAA-MM-DD» (aritmética de calendario, sin zona horaria). */
export function addDays(date: string, days: number): string {
  const [year, month, day] = split(date)
  return new Date(Date.UTC(year, month - 1, day + days)).toISOString().slice(0, 10)
}

/** Abreviatura del día de la semana y número del día: «sáb» y «7». */
export function dayParts(date: string): { weekday: string; day: number } {
  const [year, month, day] = split(date)
  const weekday = DAY_NAMES[new Date(Date.UTC(year, month - 1, day)).getUTCDay()] ?? ''
  return { weekday, day }
}

/** «sáb 7 nov». */
export function formatShortDate(date: string): string {
  const { weekday, day } = dayParts(date)
  const [, month] = split(date)
  return `${weekday} ${day} ${MONTH_NAMES[month - 1] ?? ''}`
}

/** «noviembre 2026». */
export function formatMonthYear(date: string): string {
  const [year, month] = split(date)
  return `${MONTH_LONG[month - 1] ?? ''} ${year}`
}

/** «AAAA-MM-DD» de un instante ISO 8601 con desfase. */
export function dateOf(moment: string): string {
  return moment.slice(0, 10)
}

/** «10:30» de un instante ISO 8601 con desfase. */
export function timeOf(moment: string): string {
  return moment.slice(11, 16)
}

/** Segundos que faltan para un instante ISO 8601 (0 si ya pasó o no se entiende). */
export function secondsUntil(moment: string, now: number = Date.now()): number {
  const target = Date.parse(moment)
  return Number.isNaN(target) ? 0 : Math.max(0, Math.ceil((target - now) / 1000))
}

/** ₡10.000: punto como separador de miles. */
export function colones(amount: number): string {
  return `₡${String(Math.round(amount)).replace(/\B(?=(\d{3})+(?!\d))/g, '.')}`
}
