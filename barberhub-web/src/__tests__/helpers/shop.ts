import { jsonResponse, pathOf, problem } from './http'

export const SHOP = {
  id: '0190b1f0-0000-7000-8000-000000000001',
  nombre: 'Pura Navaja',
  ciudad: 'San José',
  telefono: '+506 2222 3333',
}

export const SERVICES = [
  { id: 's1', nombre: 'Corte clásico', duracion_minutos: 30, precio: 7000, base: 6195, iva: 805 },
  { id: 's2', nombre: 'Corte + barba', duracion_minutos: 90, precio: 10000, base: 8850, iva: 1150 },
]

/** Responde como la API: la barbería, su catálogo y 404 para cualquier otro enlace. */
export function shopHandler(request: Request): Response {
  const route = pathOf(request)
  if (route === 'GET /api/v1/enlaces/pura-navaja') return jsonResponse(200, { data: SHOP })
  if (route === `GET /api/v1/barberias/${SHOP.id}/servicios`) {
    return jsonResponse(200, { data: SERVICES })
  }
  return problem(404, { type: 'no-encontrado', title: 'Esta barbería no existe' })
}
