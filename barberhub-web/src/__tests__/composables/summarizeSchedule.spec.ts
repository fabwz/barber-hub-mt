import { describe, expect, it } from 'vitest'

import { summarizeSchedule, type DayDraft } from '@/composables/useCollaboratorForm'
import { WEEKDAYS, hoursLabel, weekdayLabel } from '@/composables/weekdays'

function week(hours: Record<number, Array<[string, string]>>): DayDraft[] {
  return WEEKDAYS.map((item) => ({
    day: item.day,
    label: item.label,
    working: item.day in hours,
    shifts: (hours[item.day] ?? []).map(([start, end], index) => ({
      key: `k-${item.day}-${index}`,
      start,
      end,
    })),
    breaks: [],
  }))
}

describe('summarizeSchedule', () => {
  it('groups the consecutive days that share their shifts', () => {
    const days = week({
      1: [['09:00', '17:00']],
      2: [['09:00', '17:00']],
      3: [['09:00', '17:00']],
      4: [['09:00', '17:00']],
      5: [['09:00', '17:00']],
    })
    expect(summarizeSchedule(days)).toBe('Lunes a viernes, 09:00 a 17:00')
  })

  it('names one day alone and keeps the groups apart', () => {
    const days = week({ 1: [['09:00', '17:00']], 2: [['09:00', '17:00']], 6: [['08:00', '12:00']] })
    expect(summarizeSchedule(days)).toBe('Lunes a martes, 09:00 a 17:00; Sábado, 08:00 a 12:00')
  })

  it('lists days that are not consecutive', () => {
    const days = week({ 1: [['09:00', '17:00']], 3: [['09:00', '17:00']] })
    expect(summarizeSchedule(days)).toBe('Lunes, miércoles, 09:00 a 17:00')
  })

  it('joins the shifts of a split day', () => {
    expect(
      summarizeSchedule(
        week({
          5: [
            ['09:00', '12:30'],
            ['14:00', '19:00'],
          ],
        }),
      ),
    ).toBe('Viernes, 09:00 a 12:30 y 14:00 a 19:00')
  })

  it('says there is no schedule when no day is worked', () => {
    expect(summarizeSchedule(week({}))).toBe('Sin horario')
  })
})

describe('weekdays', () => {
  it('numbers the week from Monday (1) to Sunday (7)', () => {
    expect(weekdayLabel(1)).toBe('Lunes')
    expect(weekdayLabel(7)).toBe('Domingo')
    expect(weekdayLabel(9)).toBe('')
  })

  it('writes the opening hours of a day, or «Cerrada»', () => {
    expect(hoursLabel([])).toBe('Cerrada')
    expect(
      hoursLabel([
        { abre: '14:00', cierra: '19:00' },
        { abre: '09:00', cierra: '12:30' },
      ]),
    ).toBe('09:00–12:30 y 14:00–19:00')
  })
})
