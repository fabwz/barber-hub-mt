import { jsonResponse, pathOf, problem } from './http'
import { SERVICES, SHOP, shopHandler } from './shop'

export const MAURICIO = '0190b1f0-0000-7000-8000-0000000000a1'
export const DANIELA = '0190b1f0-0000-7000-8000-0000000000a2'

export function slot(barber: string, start: string, end: string) {
  return { inicio: start, fin: end, barbero: barber }
}

export function group(id: string, nombre: string, ...espacios: ReturnType<typeof slot>[]) {
  return { id, nombre, espacios }
}

/** Body of `GET /disponibilidad` for a date. */
export function availabilityBody(
  fecha: string,
  barberos: ReturnType<typeof group>[],
  dias_siguientes: Array<{ fecha: string; barberos: ReturnType<typeof group>[] }> = [],
) {
  return {
    data: {
      servicio: { ...SERVICES[0], duracion_minutos: 30 },
      fecha,
      barberos,
      dias_siguientes,
    },
  }
}

export const RESERVATION = {
  id: 'r1',
  servicio: SERVICES[0]!.id,
  barbero: MAURICIO,
  estado: 'temporal',
  inicio: '2030-03-05T10:00:00-06:00',
  fin: '2030-03-05T10:30:00-06:00',
  precio: 7000,
  duracion_minutos: 30,
  plazo_cancelacion_horas: 24,
  expira_en: null,
}

export interface BookingHandlers {
  availability?: (request: Request) => Response | Promise<Response>
  reserve?: (request: Request) => Response | Promise<Response>
}

/** The shop plus availability and reservation answers; each test overrides what it needs. */
export function bookingHandler(handlers: BookingHandlers = {}) {
  return (request: Request): Response | Promise<Response> => {
    const route = pathOf(request)
    if (route === `GET /api/v1/barberias/${SHOP.id}/disponibilidad`) {
      return handlers.availability
        ? handlers.availability(request)
        : jsonResponse(200, availabilityBody('x', []))
    }
    if (route === `POST /api/v1/barberias/${SHOP.id}/reservas`) {
      return handlers.reserve ? handlers.reserve(request) : jsonResponse(201, { data: RESERVATION })
    }
    return shopHandler(request)
  }
}

export function conflict(alternativas: unknown[]) {
  return problem(409, {
    type: 'espacio-no-disponible',
    title: 'Espacio no disponible',
    detail: 'Ese espacio ya no está disponible.',
    alternativas,
  })
}
