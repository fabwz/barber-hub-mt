import { jsonResponse, mockFetch, pathOf } from './http'
import { OWNER_SHOP, SHOP_A, SHOP_B, STAFF_SHOP, staffHandler } from './staff'

export function service(over: Record<string, unknown> = {}) {
  return {
    id: '0190b1f0-0000-7000-8000-0000000000a1',
    nombre: 'Corte clásico',
    duracion_minutos: 30,
    precio: 7000,
    base: 6195,
    iva: 805,
    activo: true,
    imagen: null as string | null,
    ...over,
  }
}

export const RAPADO = service({
  id: '0190b1f0-0000-7000-8000-0000000000a2',
  nombre: 'Rapado',
  duracion_minutos: 20,
  precio: 5500,
})

type Override = (request: Request) => Response | undefined

/**
 * The services API of the owner, in memory. `override` gets each request first: a Response answers it, undefined
 * lets the normal answer through. `calls` lists «METHOD path» of every administration request.
 */
export function servicesApi({
  role = 'dueno',
  services = [service(), RAPADO],
  override = () => undefined,
}: { role?: 'dueno' | 'colaborador'; services?: unknown[]; override?: Override } = {}) {
  const shopId = role === 'dueno' ? SHOP_A : SHOP_B
  const base = `/api/v1/barberias/${shopId}/administracion/servicios`
  const calls: string[] = []
  /** The JSON body of every POST and PATCH, in order. */
  const bodies: unknown[] = []
  let list = [...services] as Array<ReturnType<typeof service>>
  let nextId = 100

  const fetchMock = mockFetch(async (request) => {
    const route = pathOf(request)
    if (route.includes('/administracion/')) calls.push(route)
    const forced = override(request)
    if (forced !== undefined) return forced
    const fallback = staffHandler([OWNER_SHOP, STAFF_SHOP])(request)
    if (fallback.status !== 404) return fallback

    if (route === `GET ${base}`) return jsonResponse(200, { data: list })
    if (route === `POST ${base}`) {
      const body = (await request.json()) as Record<string, unknown>
      bodies.push(body)
      const id = `0190b1f0-0000-7000-8000-${String(nextId++).padStart(12, '0')}`
      const created = service({ ...body, id })
      list = [...list, created]
      return jsonResponse(201, { data: created })
    }
    const match = new URL(request.url).pathname.match(new RegExp(`^${base}/([^/]+)(/imagen)?$`))
    const current = list.find((item) => item.id === match?.[1])
    if (match !== null && current !== undefined) {
      if (match[2] === '/imagen' && request.method === 'POST') {
        const updated = { ...current, imagen: '/api/v1/archivos/nueva.webp' }
        list = list.map((item) => (item.id === updated.id ? updated : item))
        return jsonResponse(200, { data: updated })
      }
      if (request.method === 'PATCH') {
        const body = (await request.json()) as Record<string, unknown>
        bodies.push(body)
        const updated = { ...current, ...body }
        list = list.map((item) => (item.id === updated.id ? updated : item))
        return jsonResponse(200, { data: updated })
      }
    }
    return jsonResponse(404, { status: 404, type: 'no-encontrado' })
  })

  return { shopId, calls, bodies, fetchMock, base, current: () => list }
}
