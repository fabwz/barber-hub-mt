import { vi } from 'vitest'

import { jsonResponse, pathOf, problem } from './http'
import { MAURICIO } from './booking'
import { SHOP, shopHandler } from './shop'

export const RESERVATION_ID = '0190b1f0-0000-7000-8000-0000000000b1'
export const PAYMENT_ID = '0190b1f0-0000-7000-8000-0000000000c1'
export const ONVO_KEY = 'onvo_test_publishable_key_abc'
export const ONVO_URL = 'https://api.onvopay.com/v1/payment-methods'
export const PAYMENT_PAGE = `/b/pura-navaja/pago/${RESERVATION_ID}`

const BASE = `/api/v1/barberias/${SHOP.id}`

/** Reservation of the customer as `GET /reservas/{reserva}` returns it (a deposit with ONVO). */
export function detail(over: Record<string, unknown> = {}) {
  return {
    id: RESERVATION_ID,
    servicio: 's2',
    servicio_nombre: 'Corte + barba',
    barbero: MAURICIO,
    barbero_nombre: 'Mauricio',
    estado: 'temporal',
    inicio: '2030-03-05T10:30:00-06:00',
    fin: '2030-03-05T11:15:00-06:00',
    precio: 10000,
    duracion_minutos: 45,
    expira_en: '2030-03-05T10:10:00-06:00',
    monto_a_pagar: 3333,
    saldo_en_local: 6667,
    vence_en: '2030-03-05T10:10:00-06:00',
    pasarela: 'onvo',
    metodos_de_pago: ['tarjeta', 'sinpe_movil'],
    plazo_cancelacion_horas: 24,
    ...over,
  }
}

export function payment(over: Record<string, unknown> = {}) {
  return {
    id: PAYMENT_ID,
    reserva: RESERVATION_ID,
    estado: 'pendiente',
    metodo: 'tarjeta',
    monto: 3333,
    motivo: null,
    devolucion_pendiente: false,
    accion_siguiente: null,
    ...over,
  }
}

type Handler = (request: Request) => Response | Promise<Response>

export interface PaymentHandlers {
  reservation?: Handler
  create?: Handler
  status?: Handler
  cancel?: Handler
  /** The call to ONVO from the browser: `fetch(url, init)`. */
  onvo?: (url: string, init: RequestInit) => Response | Promise<Response>
}

export interface Recorded {
  method: string
  path: string
  body: string
}

/** Simulates fetch: the shop, the reservation, the payment and ONVO. Nothing reaches a real server. */
export function mockPaymentFetch(handlers: PaymentHandlers = {}) {
  const onvo: Array<{ url: string; init: RequestInit }> = []
  const api: Recorded[] = []
  const fetchMock = vi.fn(async (input: Request | string, init?: RequestInit) => {
    if (typeof input === 'string') {
      onvo.push({ url: input, init: init ?? {} })
      return handlers.onvo
        ? handlers.onvo(input, init ?? {})
        : jsonResponse(201, { id: 'pm_test_1' })
    }
    const body = input.method === 'POST' ? await input.clone().text() : ''
    api.push({ method: input.method, path: new URL(input.url).pathname, body })
    const route = pathOf(input)
    if (route === `GET ${BASE}/reservas/${RESERVATION_ID}`) {
      return handlers.reservation
        ? handlers.reservation(input)
        : jsonResponse(200, { data: detail() })
    }
    if (route === `POST ${BASE}/reservas/${RESERVATION_ID}/pagos`) {
      return handlers.create ? handlers.create(input) : jsonResponse(201, { data: payment() })
    }
    if (route === `POST ${BASE}/reservas/${RESERVATION_ID}/cancelacion`) {
      return handlers.cancel
        ? handlers.cancel(input)
        : jsonResponse(200, { data: { id: RESERVATION_ID, estado: 'cancelada_por_cliente' } })
    }
    if (route === `GET ${BASE}/pagos/${PAYMENT_ID}`) {
      return handlers.status ? handlers.status(input) : jsonResponse(200, { data: payment() })
    }
    return shopHandler(input) ?? problem(404)
  })
  vi.stubGlobal('fetch', fetchMock)
  return {
    fetchMock,
    onvo,
    api,
    posts: () => api.filter((call) => call.method === 'POST'),
    cancelCalls: () => api.filter((call) => call.path.endsWith('/cancelacion')),
    statusCalls: () => api.filter((call) => call.path === `${BASE}/pagos/${PAYMENT_ID}`),
  }
}
