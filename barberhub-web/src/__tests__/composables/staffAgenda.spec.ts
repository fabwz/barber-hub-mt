import { afterEach, describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'

import { agendaPermissions } from '@/composables/useAgendaPermissions'
import {
  NO_SHOW_TOLERANCE_MINUTES,
  absentFrom,
  closingAvailability,
} from '@/composables/reservationClosing'
import {
  collaboratorOptions,
  currentTimeIndex,
  filterAgenda,
  useStaffAgenda,
  type AgendaReservation,
} from '@/composables/useStaffAgenda'
import { jsonResponse, mockFetch } from '../helpers/http'

const SHOP = '0190b1f0-0000-7000-8000-0000000000d1'

function reservation(
  id: string,
  estado: string,
  barbero = 'b1',
  nombre = 'Mauricio',
): AgendaReservation {
  return {
    id,
    servicio: 's1',
    servicio_nombre: 'Corte clásico',
    barbero,
    barbero_nombre: nombre,
    estado,
    inicio: '2026-11-05T09:00:00-06:00',
    fin: '2026-11-05T09:30:00-06:00',
    precio: 7000,
    duracion_minutos: 30,
    expira_en: null,
    cliente_nombre: 'Luis Mora',
  }
}

afterEach(() => vi.unstubAllGlobals())

describe('closing availability (CO-05)', () => {
  const start = '2026-11-05T11:30:00-06:00'
  const startMs = Date.parse(start)

  it('keeps the tolerance in one constant of 10 minutes', () => {
    expect(NO_SHOW_TOLERANCE_MINUTES).toBe(10)
  })

  it('allows nothing before the start', () => {
    expect(closingAvailability(start, startMs - 1)).toEqual({
      canAttend: false,
      canMarkAbsent: false,
    })
  })

  it('allows «atendida» from the start and «ausente» only after the tolerance', () => {
    expect(closingAvailability(start, startMs)).toEqual({ canAttend: true, canMarkAbsent: false })
    expect(closingAvailability(start, startMs + 10 * 60_000 - 1).canMarkAbsent).toBe(false)
    expect(closingAvailability(start, startMs + 10 * 60_000)).toEqual({
      canAttend: true,
      canMarkAbsent: true,
    })
  })

  it('says from when an absence can be marked', () => {
    expect(absentFrom(start)).toBe('11:40')
    expect(absentFrom('2026-11-05T11:55:00-06:00')).toBe('12:05')
  })
})

describe('permissions by role', () => {
  it('gives the owner every action and the whole detail', () => {
    expect(agendaPermissions('dueno')).toEqual({
      canFilterByCollaborator: true,
      canReschedule: true,
      canCancel: true,
      canClose: true,
      canSeeTotal: true,
      canSeeHistory: true,
      canSeePayment: true,
      title: 'Agenda',
    })
  })

  it('gives the collaborator only closing and the pending balance', () => {
    expect(agendaPermissions('colaborador')).toEqual({
      canFilterByCollaborator: false,
      canReschedule: false,
      canCancel: false,
      canClose: true,
      canSeeTotal: false,
      canSeeHistory: false,
      canSeePayment: false,
      title: 'Mi agenda',
    })
  })
})

describe('agenda filters', () => {
  const day = [
    reservation('1', 'confirmada'),
    reservation('2', 'cancelada_por_cliente', 'b2', 'Kevin'),
    reservation('3', 'cancelada_por_barberia'),
    reservation('4', 'expirada', 'b3', 'Andrés'),
    reservation('5', 'temporal', 'b2', 'Kevin'),
  ]

  it('never shows the expired ones', () => {
    const shown = filterAgenda(day, { status: null, collaborator: null })
    expect(shown.map((item) => item.id)).toEqual(['1', '2', '3', '5'])
  })

  it('«Cancelada» joins both cancellations', () => {
    const shown = filterAgenda(day, { status: 'cancelada', collaborator: null })
    expect(shown.map((item) => item.id)).toEqual(['2', '3'])
  })

  it('filters by status and by collaborator together', () => {
    expect(filterAgenda(day, { status: 'temporal', collaborator: 'b2' }).map((i) => i.id)).toEqual([
      '5',
    ])
    expect(filterAgenda(day, { status: 'confirmada', collaborator: 'b2' })).toEqual([])
  })

  it('offers only the collaborators that have a reservation that day, by name', () => {
    expect(collaboratorOptions(day)).toEqual([
      { id: 'b2', name: 'Kevin' },
      { id: 'b1', name: 'Mauricio' },
    ])
  })
})

describe('useStaffAgenda', () => {
  function run<T>(factory: () => T): T {
    return effectScope().run(factory)!
  }

  it('asks every page of the day, always with `dia`', async () => {
    const urls: URL[] = []
    mockFetch((request) => {
      const url = new URL(request.url)
      urls.push(url)
      const page = Number(url.searchParams.get('pagina'))
      return jsonResponse(200, {
        data: [reservation(`r${page}`, 'confirmada')],
        meta: { pagina: page, por_pagina: 50, total: 2, ultima_pagina: 2 },
      })
    })
    const agenda = run(() => useStaffAgenda())

    await agenda.load(SHOP, '2026-11-05')

    expect(agenda.status.value).toBe('ready')
    expect(agenda.items.value.map((item) => item.id)).toEqual(['r1', 'r2'])
    expect(urls.map((url) => url.searchParams.get('dia'))).toEqual(['2026-11-05', '2026-11-05'])
    expect(urls[0]!.pathname).toBe(`/api/v1/barberias/${SHOP}/reservas`)
  })

  it('ends in error when the API fails, and keeps the list on a quiet refresh', async () => {
    let fail = false
    mockFetch(() =>
      fail
        ? jsonResponse(500, { status: 500 })
        : jsonResponse(200, {
            data: [reservation('r1', 'confirmada')],
            meta: { pagina: 1, por_pagina: 50, total: 1, ultima_pagina: 1 },
          }),
    )
    const agenda = run(() => useStaffAgenda())
    await agenda.load(SHOP, '2026-11-05')

    fail = true
    await agenda.load(SHOP, '2026-11-05', true)
    expect(agenda.status.value).toBe('ready')
    expect(agenda.items.value).toHaveLength(1)

    await agenda.load(SHOP, '2026-11-05')
    expect(agenda.status.value).toBe('error')
  })
})

describe('mark of the current time', () => {
  const day = [
    { ...reservation('1', 'completada'), inicio: '2026-11-05T09:00:00-06:00' },
    { ...reservation('2', 'confirmada'), inicio: '2026-11-05T11:30:00-06:00' },
  ]

  it('goes before the first reservation that has not started', () => {
    expect(currentTimeIndex(day, Date.parse('2026-11-05T11:20:00-06:00'))).toBe(1)
    expect(currentTimeIndex(day, Date.parse('2026-11-05T08:00:00-06:00'))).toBe(0)
  })

  it('goes at the end when every reservation already started', () => {
    expect(currentTimeIndex(day, Date.parse('2026-11-05T12:00:00-06:00'))).toBe(2)
  })
})
