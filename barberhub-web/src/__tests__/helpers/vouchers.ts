import { vi } from 'vitest'

import { SESSION_STORAGE_KEY } from '@/stores/session'
import { jsonResponse, pathOf, problem } from './http'
import { SHOP, shopHandler } from './shop'

export const BASE = `/api/v1/barberias/${SHOP.id}`

export const VOUCHERS_PAGE = '/b/pura-navaja/bonos'
export const VOUCHER_ID = '0190b1f0-0000-7000-8000-0000000000d1'
export const BOUGHT_ID = '0190b1f0-0000-7000-8000-0000000000d2'
export const VOUCHER_PURCHASE_PAGE = `/b/pura-navaja/bonos/${VOUCHER_ID}/compra`
export const VOUCHER_PAYMENT_ID = '0190b1f0-0000-7000-8000-0000000000d3'

export const CUT = { id: 's1', nombre: 'Corte clásico' }
export const CUT_BEARD = { id: 's2', nombre: 'Corte + barba' }
export const DYE = { id: 's9', nombre: 'Tinte' }

export function signIn(): void {
  sessionStorage.setItem(
    SESSION_STORAGE_KEY,
    JSON.stringify({ token: 'token', person: { email: 'ana@correo.cr' } }),
  )
}

/** A voucher on sale (`BonoALaVenta`). */
export function onSale(over: Record<string, unknown> = {}) {
  return {
    id: VOUCHER_ID,
    nombre: 'Bono 5 cortes',
    sesiones: 5,
    vigencia_dias: 60,
    precio: 45000,
    servicios: [CUT, CUT_BEARD],
    ...over,
  }
}

/** A voucher the person bought (`BonoComprado`); by default an active one with 3 of 5 sessions. */
export function bought(over: Record<string, unknown> = {}) {
  return {
    id: BOUGHT_ID,
    nombre: 'Bono 5 cortes',
    sesiones_totales: 5,
    saldo: 3,
    estado: 'activo',
    activado_en: '2030-01-04T08:00:00-06:00',
    vence_en: '2030-03-05T08:00:00-06:00',
    movimientos: [
      { tipo: 'compra', sesiones: 5, reserva: null, fecha: '2030-01-04T08:00:00-06:00' },
      { tipo: 'apartado', sesiones: -1, reserva: 'r1', fecha: '2030-01-12T09:00:00-06:00' },
      { tipo: 'consumo', sesiones: 0, reserva: 'r1', fecha: '2030-01-14T10:00:00-06:00' },
      { tipo: 'apartado', sesiones: -1, reserva: 'r2', fecha: '2030-01-26T09:00:00-06:00' },
      { tipo: 'reintegro', sesiones: 1, reserva: 'r2', fecha: '2030-01-27T09:00:00-06:00' },
      { tipo: 'apartado', sesiones: -1, reserva: 'r3', fecha: '2030-01-28T09:00:00-06:00' },
    ],
    servicios: [CUT, CUT_BEARD],
    ...over,
  }
}

/** An expired voucher: balance 0 and its expiry date. */
export function expired(over: Record<string, unknown> = {}) {
  return bought({
    id: '0190b1f0-0000-7000-8000-0000000000d4',
    nombre: 'Bono 4 barbas',
    sesiones_totales: 4,
    saldo: 0,
    estado: 'vencido',
    vence_en: '2029-11-03T08:00:00-06:00',
    servicios: [{ id: 's3', nombre: 'Barba' }],
    ...over,
  })
}

export function list(items: unknown[], meta: Record<string, unknown> = {}) {
  return {
    data: items,
    meta: { pagina: 1, por_pagina: 50, total: items.length, ultima_pagina: 1, ...meta },
  }
}

export function payment(over: Record<string, unknown> = {}) {
  return {
    id: VOUCHER_PAYMENT_ID,
    reserva: null,
    bono_comprado: BOUGHT_ID,
    estado: 'pendiente',
    metodo: 'tarjeta',
    monto: 45000,
    motivo: null,
    devolucion_pendiente: false,
    accion_siguiente: null,
    ...over,
  }
}

type Handler = (request: Request) => Response | Promise<Response>

export interface VoucherHandlers {
  onSale?: Handler
  mine?: Handler
  purchase?: Handler
  status?: Handler
  /** The call to ONVO from the browser: `fetch(url, init)`. */
  onvo?: (url: string, init: RequestInit) => Response | Promise<Response>
  /** Anything else (reservations, availability...). */
  other?: Handler
}

export interface Recorded {
  method: string
  path: string
  search: string
  body: string
}

/** Simulates fetch for the voucher screens. Nothing reaches a real server. */
export function mockVoucherFetch(handlers: VoucherHandlers = {}) {
  const api: Recorded[] = []
  const onvo: Array<{ url: string; init: RequestInit }> = []
  const fetchMock = vi.fn(async (input: Request | string, init?: RequestInit) => {
    if (typeof input === 'string') {
      onvo.push({ url: input, init: init ?? {} })
      return handlers.onvo
        ? handlers.onvo(input, init ?? {})
        : jsonResponse(201, { id: 'pm_test_1' })
    }
    const url = new URL(input.url)
    const body = input.method === 'GET' ? '' : await input.clone().text()
    api.push({ method: input.method, path: url.pathname, search: url.search, body })
    const route = pathOf(input)
    if (route === `GET ${BASE}/bonos`) {
      return handlers.onSale
        ? handlers.onSale(input)
        : jsonResponse(200, list([onSale()], { bonos_activos: true }))
    }
    if (route === `GET ${BASE}/mis-bonos`) {
      return handlers.mine ? handlers.mine(input) : jsonResponse(200, list([bought()]))
    }
    if (route === `POST ${BASE}/bonos/${VOUCHER_ID}/compras`) {
      return handlers.purchase ? handlers.purchase(input) : jsonResponse(201, { data: payment() })
    }
    if (route === `GET ${BASE}/pagos/${VOUCHER_PAYMENT_ID}`) {
      return handlers.status ? handlers.status(input) : jsonResponse(200, { data: payment() })
    }
    if (handlers.other) {
      const answer = await handlers.other(input)
      if (answer.status !== 404) return answer
    }
    return shopHandler(input) ?? problem(404)
  })
  vi.stubGlobal('fetch', fetchMock)
  return {
    fetchMock,
    api,
    onvo,
    count: (method: string, suffix: string) =>
      api.filter((call) => call.method === method && call.path.endsWith(suffix)).length,
    calls: (method: string, suffix: string) =>
      api.filter((call) => call.method === method && call.path.endsWith(suffix)),
  }
}
