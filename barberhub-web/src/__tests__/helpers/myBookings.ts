import { vi } from 'vitest'

import { SESSION_STORAGE_KEY } from '@/stores/session'
import { MAURICIO, availabilityBody, group, slot } from './booking'
import { jsonResponse, pathOf, problem } from './http'
import { SHOP, shopHandler } from './shop'

const BASE = `/api/v1/barberias/${SHOP.id}`

export const LIST_PAGE = '/b/pura-navaja/mis-reservas'
export const DETAIL_ID = '0190b1f0-0000-7000-8000-0000000000e1'
export const DETAIL_PAGE = `/b/pura-navaja/mis-reservas/${DETAIL_ID}`

/** A reservation of the list (`ReservaDeCliente`); by default a confirmed one in 2030. */
export function listed(over: Record<string, unknown> = {}) {
  return {
    id: DETAIL_ID,
    servicio: 's2',
    servicio_nombre: 'Corte + barba',
    barbero: MAURICIO,
    barbero_nombre: 'Mauricio',
    estado: 'confirmada',
    inicio: '2030-03-05T10:30:00-06:00',
    fin: '2030-03-05T11:15:00-06:00',
    precio: 10000,
    duracion_minutos: 45,
    expira_en: null,
    monto_a_pagar: 0,
    saldo_en_local: 10000,
    vence_en: null,
    pasarela: null,
    metodos_de_pago: [],
    ...over,
  }
}

/** The detail (`ReservaDetalleDeCliente`): the same plus the free window of the reservation. */
export function detailed(over: Record<string, unknown> = {}) {
  return { ...listed(), plazo_cancelacion_horas: 4, ...over }
}

export function page(items: unknown[], pagina = 1, ultima = 1) {
  return {
    data: items,
    meta: { pagina, por_pagina: 50, total: items.length, ultima_pagina: ultima },
  }
}

type Handler = (request: Request) => Response | Promise<Response>

export interface MyBookingsHandlers {
  list?: Handler
  detail?: Handler
  reschedule?: Handler
  cancel?: Handler
  availability?: Handler
}

/** Mauricio's free hours on 2030-03-05: 11:00 and 11:30 (and a late one). */
export function freeHours(date = '2030-03-05') {
  const at = (time: string) => `${date}T${time}:00-06:00`
  return availabilityBody(date, [
    group(
      MAURICIO,
      'Mauricio',
      slot(MAURICIO, at('11:00'), at('11:30')),
      slot(MAURICIO, at('11:30'), at('12:00')),
      slot(MAURICIO, at('15:00'), at('15:30')),
    ),
  ])
}

export function signIn(): void {
  sessionStorage.setItem(
    SESSION_STORAGE_KEY,
    JSON.stringify({ token: 'token', person: { email: 'ana@correo.cr' } }),
  )
}

/** Simulates fetch for the screens of «Mis reservas». Nothing reaches a real server. */
export function mockBookingsFetch(handlers: MyBookingsHandlers = {}) {
  const calls: Array<{ method: string; path: string; search: string; body: string }> = []
  const fetchMock = vi.fn(async (request: Request) => {
    const url = new URL(request.url)
    const body = request.method === 'GET' ? '' : await request.clone().text()
    calls.push({ method: request.method, path: url.pathname, search: url.search, body })
    const route = pathOf(request)
    if (route === `GET ${BASE}/mis-reservas`) {
      return handlers.list ? handlers.list(request) : jsonResponse(200, page([listed()]))
    }
    if (route === `GET ${BASE}/reservas/${DETAIL_ID}`) {
      return handlers.detail ? handlers.detail(request) : jsonResponse(200, { data: detailed() })
    }
    if (route === `PATCH ${BASE}/reservas/${DETAIL_ID}`) {
      return handlers.reschedule
        ? handlers.reschedule(request)
        : jsonResponse(200, {
            data: {
              id: DETAIL_ID,
              estado: 'confirmada',
              inicio: '2030-03-05T11:00:00-06:00',
              fin: '2030-03-05T11:45:00-06:00',
            },
          })
    }
    if (route === `POST ${BASE}/reservas/${DETAIL_ID}/cancelacion`) {
      return handlers.cancel
        ? handlers.cancel(request)
        : jsonResponse(200, {
            data: { id: DETAIL_ID, estado: 'cancelada_por_cliente', clasificacion: 'a_tiempo' },
          })
    }
    if (route === `GET ${BASE}/disponibilidad`) {
      return handlers.availability ? handlers.availability(request) : jsonResponse(200, freeHours())
    }
    return shopHandler(request) ?? problem(404)
  })
  vi.stubGlobal('fetch', fetchMock)
  return {
    fetchMock,
    calls,
    count: (method: string, suffix: string) =>
      calls.filter((call) => call.method === method && call.path.endsWith(suffix)).length,
  }
}
