import { jsonResponse, mockFetch, pathOf, problem } from './http'
import { SHOP, shopHandler } from './shop'
import { MAURICIO, availabilityBody, group } from './booking'
import { SESSION_STORAGE_KEY } from '@/stores/session'

export const USER_ID = '0190b1f0-0000-7000-8000-0000000000f1'
export const OTHER_USER_ID = '0190b1f0-0000-7000-8000-0000000000f9'
export const OFFER_ID = '0190b1f0-0000-7000-8000-0000000000f2'
export const ROW_ID = '0190b1f0-0000-7000-8000-0000000000f3'
export const RESERVATION_ID = '0190b1f0-0000-7000-8000-0000000000f4'
export const OTHER_SHOP = '0190b1f0-0000-7000-8000-000000000002'

export const BASE = `/api/v1/barberias/${SHOP.id}`
export const WAITLIST_PAGE = '/b/pura-navaja/lista-de-espera'

/** An offer (`OfertaDeEspera`) that expires in `minutes` minutes. */
export function offer(over: Record<string, unknown> = {}, minutes = 15) {
  return {
    id: OFFER_ID,
    fila: ROW_ID,
    servicio: 's1',
    barbero: MAURICIO,
    barbero_nombre: 'Mauricio',
    inicio: '2030-03-05T10:00:00-06:00',
    fin: '2030-03-05T10:35:00-06:00',
    vence_en: new Date(Date.now() + minutes * 60_000).toISOString(),
    estado: 'pendiente',
    reserva: null,
    ...over,
  }
}

/** A row of the waitlist (`FilaDeEspera`). */
export function row(over: Record<string, unknown> = {}) {
  return {
    id: ROW_ID,
    servicio: 's1',
    barbero: null,
    barbero_nombre: null,
    fecha: '2030-03-05',
    estado: 'esperando',
    ingreso: '2030-03-01T08:00:00-06:00',
    ...over,
  }
}

/** A membership of `GET /mis-barberias`. */
export function membership(over: Record<string, unknown> = {}) {
  return {
    id: SHOP.id,
    nombre: 'Pura Navaja',
    enlace: 'pura-navaja',
    rol: 'cliente',
    colaborador: null,
    ...over,
  }
}

export function signInAs(token = 'tok'): void {
  sessionStorage.setItem(
    SESSION_STORAGE_KEY,
    JSON.stringify({ token, person: { email: 'ana@correo.cr' } }),
  )
}

export interface OffersApi {
  memberships?: unknown[]
  offers?: () => unknown[] | Response | Promise<unknown[] | Response>
  rows?: () => unknown[] | Response | Promise<unknown[] | Response>
  account?: () => Response
  accept?: (request: Request) => Response | Promise<Response>
  reject?: (request: Request) => Response | Promise<Response>
  leave?: (request: Request) => Response | Promise<Response>
  vouchers?: unknown[]
  /** The barbers that appear in `GET /disponibilidad`. */
  barbers?: ReturnType<typeof group>[]
}

/** The API of the waitlist: every route the offers use, and the shop of the other tests. */
export function mockOffersApi(api: OffersApi = {}) {
  const requests: string[] = []
  const fetchMock = mockFetch(async (request) => {
    const route = pathOf(request)
    requests.push(route)
    if (route === 'GET /api/v1/cuenta') {
      return (
        api.account?.() ??
        jsonResponse(200, {
          data: {
            id: USER_ID,
            nombre: 'Ana',
            telefono: null,
            correo: 'ana@correo.cr',
            contrasena_temporal: false,
          },
        })
      )
    }
    if (route === 'GET /api/v1/mis-barberias') {
      return jsonResponse(200, { data: api.memberships ?? [membership()] })
    }
    if (route === `GET ${BASE}/mis-ofertas`) {
      const result = (await api.offers?.()) ?? []
      return result instanceof Response ? result : jsonResponse(200, { data: result })
    }
    if (route === `GET ${BASE}/mis-filas`) {
      const result = (await api.rows?.()) ?? []
      return result instanceof Response ? result : jsonResponse(200, { data: result })
    }
    if (route === `DELETE ${BASE}/filas/${ROW_ID}`) {
      return api.leave ? api.leave(request) : new Response(null, { status: 204 })
    }
    if (route === `POST ${BASE}/ofertas/${OFFER_ID}/aceptacion`) {
      return api.accept
        ? api.accept(request)
        : jsonResponse(200, {
            data: offer({ estado: 'aceptada', reserva: RESERVATION_ID }),
          })
    }
    if (route === `POST ${BASE}/ofertas/${OFFER_ID}/rechazo`) {
      return api.reject
        ? api.reject(request)
        : jsonResponse(200, { data: offer({ estado: 'rechazada' }) })
    }
    if (route === `GET ${BASE}/mis-bonos`) {
      const items = api.vouchers ?? []
      return jsonResponse(200, {
        data: items,
        meta: { pagina: 1, por_pagina: 50, total: items.length, ultima_pagina: 1 },
      })
    }
    if (route === `GET ${BASE}/disponibilidad`) {
      return jsonResponse(
        200,
        availabilityBody('2030-03-05', api.barbers ?? [group(MAURICIO, 'Mauricio')]),
      )
    }
    return shopHandler(request)
  })
  return { fetchMock, requests }
}

/** The error of the API for an offer that was not answered in time. */
export function expiredOfferError(): Response {
  return problem(409, {
    type: 'http://localhost/problemas/oferta-vencida',
    title: 'Oferta vencida',
    detail: 'La oferta ya venció.',
  })
}

export function conflictError(detail = 'No se pudo.'): Response {
  return problem(409, { type: 'http://localhost/problemas/conflicto', title: 'Conflicto', detail })
}

/** An event of the channel, as the API sends it. */
export function created(over: Record<string, unknown> = {}) {
  return {
    oferta: OFFER_ID,
    barberia: SHOP.id,
    vence_en: new Date(Date.now() + 15 * 60_000).toISOString(),
    ...over,
  }
}

export function closed(motivo: string, id = OFFER_ID) {
  return { oferta: id, motivo }
}
