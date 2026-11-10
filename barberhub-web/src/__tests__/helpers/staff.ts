import { jsonResponse, pathOf } from './http'

export const SHOP_A = '0190b1f0-0000-7000-8000-0000000000d1'
export const SHOP_B = '0190b1f0-0000-7000-8000-0000000000d2'
export const SHOP_C = '0190b1f0-0000-7000-8000-0000000000d3'

export function membership(id: string, nombre: string, rol: 'dueno' | 'colaborador' | 'cliente') {
  return { id, nombre, enlace: nombre.toLowerCase().replace(/\s+/g, '-'), rol }
}

export const OWNER_SHOP = membership(SHOP_A, 'Pura Navaja', 'dueno')
export const STAFF_SHOP = membership(SHOP_B, 'Fade Lab', 'colaborador')
export const CLIENT_SHOP = membership(SHOP_C, 'Don Chepe', 'cliente')

export const TOKEN_BODY = { data: { token: 'tok-1', tipo: 'Bearer' } }

/** Answers `GET /mis-barberias` with the given memberships and `POST /tokens` with a token. */
export function staffHandler(memberships: unknown[] = []) {
  return (request: Request): Response => {
    const route = pathOf(request)
    if (route === 'GET /api/v1/mis-barberias') return jsonResponse(200, { data: memberships })
    if (route === 'POST /api/v1/tokens') return jsonResponse(201, TOKEN_BODY)
    return jsonResponse(404, { status: 404 })
  }
}
