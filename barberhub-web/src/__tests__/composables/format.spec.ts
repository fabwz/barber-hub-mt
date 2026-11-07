import { describe, expect, it } from 'vitest'

import {
  addDays,
  colones,
  dateOf,
  formatMonthYear,
  formatShortDate,
  secondsUntil,
  timeOf,
  todayInCostaRica,
} from '@/composables/format'
import { validEmail } from '@/composables/socialUrl'

describe('format', () => {
  it('computes today in Costa Rica (UTC-6), not in UTC or the browser zone', () => {
    // 03:00 UTC on the 7th is still 21:00 of the 6th in Costa Rica.
    expect(todayInCostaRica(new Date('2026-11-07T03:00:00Z'))).toBe('2026-11-06')
    expect(todayInCostaRica(new Date('2026-11-07T06:00:00Z'))).toBe('2026-11-07')
    expect(todayInCostaRica(new Date('2026-12-31T05:59:59Z'))).toBe('2026-12-30')
  })

  it('formats dates and hours as the design asks', () => {
    expect(formatShortDate('2026-11-07')).toBe('sáb 7 nov')
    expect(formatShortDate('2026-11-05')).toBe('jue 5 nov')
    expect(formatMonthYear('2026-11-07')).toBe('noviembre 2026')
    expect(timeOf('2026-11-07T10:30:00-06:00')).toBe('10:30')
    expect(dateOf('2026-11-07T10:30:00-06:00')).toBe('2026-11-07')
    expect(colones(7000)).toBe('₡7.000')
  })

  it('adds days with calendar arithmetic', () => {
    expect(addDays('2026-11-30', 1)).toBe('2026-12-01')
    expect(addDays('2026-12-31', 1)).toBe('2027-01-01')
    expect(addDays('2028-02-28', 1)).toBe('2028-02-29')
    expect(addDays('2026-11-07', 0)).toBe('2026-11-07')
  })

  it('counts the seconds to a deadline, never below zero', () => {
    const now = Date.parse('2030-03-05T10:00:00-06:00')
    expect(secondsUntil('2030-03-05T10:10:00-06:00', now)).toBe(600)
    expect(secondsUntil('2030-03-05T09:00:00-06:00', now)).toBe(0)
    expect(secondsUntil('no es una fecha', now)).toBe(0)
  })

  it('validates a contact email', () => {
    expect(validEmail(' hola@puranavaja.cr ')).toBe('hola@puranavaja.cr')
    expect(validEmail('hola@puranavaja')).toBeNull()
    expect(validEmail(undefined)).toBeNull()
  })
})
