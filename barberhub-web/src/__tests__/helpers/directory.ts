import { jsonResponse, pathOf } from './http'

export interface DirectoryEntry {
  nombre: string
  ciudad: string
  logo: string | null
  enlace: string
}

export const PURA_NAVAJA: DirectoryEntry = {
  nombre: 'Pura Navaja',
  ciudad: 'San José',
  logo: null,
  enlace: 'pura-navaja',
}
export const FADE_LAB: DirectoryEntry = {
  nombre: 'Fade Lab',
  ciudad: 'Escazú',
  logo: null,
  enlace: 'fade-lab',
}
export const CORTE_FINO: DirectoryEntry = {
  nombre: 'Corte Fino',
  ciudad: 'Cartago',
  logo: null,
  enlace: 'corte-fino',
}

/** `GET /directorio` answer: one page of `data` with its `meta`. */
export function directoryPage(
  data: DirectoryEntry[],
  meta: { pagina?: number; total?: number; ultima_pagina?: number } = {},
): Response {
  return jsonResponse(200, {
    data,
    meta: {
      pagina: meta.pagina ?? 1,
      por_pagina: 50,
      total: meta.total ?? data.length,
      ultima_pagina: meta.ultima_pagina ?? 1,
    },
  })
}

/** Answers the directory with a fixed list, filtering by `buscar` like the API would (by name or city). */
export function directoryHandler(all: DirectoryEntry[]) {
  return (request: Request): Response => {
    if (pathOf(request) !== 'GET /api/v1/directorio') return jsonResponse(404, { status: 404 })
    const term = new URL(request.url).searchParams.get('buscar')?.toLowerCase() ?? ''
    const found = all.filter((shop) => `${shop.nombre} ${shop.ciudad}`.toLowerCase().includes(term))
    return directoryPage(found)
  }
}

/** The query string of every call made to the mocked `fetch`. */
export function searchParamsOf(fetchMock: { mock: { calls: unknown[][] } }): URLSearchParams[] {
  return fetchMock.mock.calls.map((call) => new URL((call[0] as Request).url).searchParams)
}
