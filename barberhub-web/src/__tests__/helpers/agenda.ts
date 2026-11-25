import { vi } from 'vitest'

import { jsonResponse, mockFetch, pathOf, problem } from './http'
import { OWNER_SHOP, SHOP_A, STAFF_SHOP, SHOP_B, staffHandler } from './staff'

export const DAY = '2026-11-05'
/** 11:20 in Costa Rica on `DAY`. */
export const NOW_ISO = '2026-11-05T17:20:00Z'

export function listItem(id: string, over: Record<string, unknown> = {}) {
  return {
    id,
    servicio: 'sv1',
    servicio_nombre: 'Corte clásico',
    barbero: 'b1',
    barbero_nombre: 'Mauricio',
    estado: 'confirmada',
    inicio: `${DAY}T09:00:00-06:00`,
    fin: `${DAY}T09:30:00-06:00`,
    precio: 7000,
    duracion_minutos: 30,
    expira_en: null,
    cliente_nombre: 'Luis Mora',
    ...over,
  }
}

/** The day of the design: attended, absent, cancelled, one in progress and two to come. */
export const DAY_ITEMS = [
  listItem('r1', { estado: 'completada', cliente_nombre: 'Luis Mora' }),
  listItem('r2', {
    estado: 'ausente',
    barbero: 'b2',
    barbero_nombre: 'Kevin',
    inicio: `${DAY}T10:00:00-06:00`,
    fin: `${DAY}T10:40:00-06:00`,
    cliente_nombre: 'Diego Vargas',
    servicio_nombre: 'Fade',
  }),
  listItem('r3', {
    estado: 'cancelada_por_cliente',
    inicio: `${DAY}T10:45:00-06:00`,
    fin: `${DAY}T11:15:00-06:00`,
    cliente_nombre: 'Carlos Jiménez',
  }),
  listItem('r4', {
    estado: 'confirmada',
    barbero: 'b2',
    barbero_nombre: 'Kevin',
    inicio: `${DAY}T11:30:00-06:00`,
    fin: `${DAY}T12:15:00-06:00`,
    cliente_nombre: 'Javier Araya',
    servicio_nombre: 'Corte + barba',
    duracion_minutos: 45,
    precio: 10000,
  }),
  listItem('r5', {
    estado: 'temporal',
    barbero: 'b3',
    barbero_nombre: 'Andrés',
    inicio: `${DAY}T13:00:00-06:00`,
    fin: `${DAY}T13:20:00-06:00`,
    cliente_nombre: 'Pablo Quesada',
    servicio_nombre: 'Barba',
  }),
  listItem('r6', {
    estado: 'expirada',
    barbero: 'b3',
    barbero_nombre: 'Andrés',
    inicio: `${DAY}T14:00:00-06:00`,
    fin: `${DAY}T14:30:00-06:00`,
    cliente_nombre: 'Ana Quirós',
  }),
]

export function detailOf(item: Record<string, unknown>, owner: boolean, history = true) {
  return {
    ...item,
    plazo_cancelacion_horas: 24,
    saldo_pendiente: 7000,
    ...(owner && history
      ? {
          historial: [
            { de: null, a: 'temporal', autor: 'Javier Araya', fecha: `${DAY}T08:02:00-06:00` },
            { de: 'temporal', a: 'confirmada', autor: 'Sistema', fecha: `${DAY}T08:12:00-06:00` },
          ],
        }
      : {}),
  }
}

export interface AgendaCalls {
  requests: string[]
  closing: Array<{ id: string; body: unknown }>
  cancellations: string[]
}

export interface AgendaOptions {
  /** Role of the person in the shop. */
  role?: 'dueno' | 'colaborador'
  items?: Array<Record<string, unknown>>
  /** Makes the list answer this status. */
  listStatus?: number
  /** Overrides the answer of a close or a cancel. */
  actionResponse?: () => Response
  detailStatus?: number
  /** Id of the collaborator profile of the person (`colaborador` of `/mis-barberias`); none by default. */
  collaborator?: string | null
}

/** Fake API of the agenda: the shops, the list of the day, the detail and the actions. */
export function agendaApi(options: AgendaOptions = {}) {
  const { role = 'dueno', items = DAY_ITEMS } = options
  const calls: AgendaCalls = { requests: [], closing: [], cancellations: [] }
  const shop =
    role === 'dueno' ? OWNER_SHOP : { ...STAFF_SHOP, colaborador: options.collaborator ?? null }
  const fetchMock = mockFetch(async (request) => {
    const route = pathOf(request)
    const url = new URL(request.url)
    calls.requests.push(`${request.method} ${url.pathname}${url.search}`)
    if (route === 'GET /api/v1/mis-barberias') return staffHandler([shop])(request)
    if (route.endsWith('/reservas') && request.method === 'GET') {
      if (options.listStatus && options.listStatus >= 400) return problem(options.listStatus)
      const day = url.searchParams.get('dia')
      const list = day === DAY ? items : []
      return jsonResponse(200, {
        data: list,
        meta: { pagina: 1, por_pagina: 50, total: list.length, ultima_pagina: 1 },
      })
    }
    const detail = /\/reservas\/([^/]+)$/.exec(url.pathname)
    if (detail && request.method === 'GET') {
      if (options.detailStatus && options.detailStatus >= 400) return problem(options.detailStatus)
      const found = items.find((item) => item.id === detail[1])
      return found ? jsonResponse(200, { data: detailOf(found, role === 'dueno') }) : problem(404)
    }
    if (url.pathname.endsWith('/cierre')) {
      calls.closing.push({
        id: url.pathname.split('/').at(-2)!,
        body: JSON.parse(await request.clone().text()),
      })
      return options.actionResponse?.() ?? jsonResponse(200, { data: {} })
    }
    if (url.pathname.endsWith('/cancelacion')) {
      calls.cancellations.push(url.pathname.split('/').at(-2)!)
      return options.actionResponse?.() ?? jsonResponse(200, { data: {} })
    }
    if (url.pathname.endsWith('/disponibilidad')) {
      return jsonResponse(200, {
        data: {
          barberos: [
            {
              id: 'b2',
              nombre: 'Kevin',
              espacios: [
                {
                  barbero: 'b2',
                  inicio: `${DAY}T15:00:00-06:00`,
                  fin: `${DAY}T15:45:00-06:00`,
                },
              ],
            },
          ],
          dias_siguientes: [],
        },
      })
    }
    return problem(404)
  })
  return { calls, fetchMock, shopId: role === 'dueno' ? SHOP_A : SHOP_B }
}

/** Fixes the clock at 11:20 in Costa Rica; only `Date` is faked, so promises and timers still run. */
export function freezeClock(at: string = NOW_ISO): void {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date(at))
}
