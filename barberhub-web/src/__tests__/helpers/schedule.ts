import { jsonResponse, mockFetch, pathOf, problem } from './http'
import { OWNER_SHOP, SHOP_A, SHOP_B, STAFF_SHOP, staffHandler } from './staff'

export interface SlotItem {
  id: string
  dia: number
  abre: string
  cierra: string
}
export interface ClosureItem {
  id: string
  fecha: string
  motivo: string | null
}

export function slot(id: string, dia: number, abre: string, cierra: string): SlotItem {
  return { id, dia, abre, cierra }
}

type Override = (request: Request) => Response | undefined

/**
 * The schedule and closures API of the owner, in memory, with the rules the API has: a slot that overlaps another
 * of the same day is a 409, a closure on a date that already has one is a 409. `override` gets each request first.
 * `calls` lists «METHOD path» of every administration request and `bodies` the JSON of every POST.
 */
export function scheduleApi({
  role = 'dueno',
  slots = [],
  closures = [],
  override = () => undefined,
}: {
  role?: 'dueno' | 'colaborador'
  slots?: SlotItem[]
  closures?: ClosureItem[]
  override?: Override
} = {}) {
  const shopId = role === 'dueno' ? SHOP_A : SHOP_B
  const base = `/api/v1/barberias/${shopId}/administracion`
  const calls: string[] = []
  const bodies: unknown[] = []
  let slotList = [...slots]
  let closureList = [...closures]
  let next = 500

  const fetchMock = mockFetch(async (request) => {
    const route = pathOf(request)
    if (route.includes('/administracion/')) calls.push(route)
    const forced = override(request)
    if (forced !== undefined) return forced
    const fallback = staffHandler([OWNER_SHOP, STAFF_SHOP])(request)
    if (fallback.status !== 404) return fallback

    const path = new URL(request.url).pathname
    if (route === `GET ${base}/horario`) return jsonResponse(200, { data: slotList })
    if (route === `GET ${base}/cierres`) return jsonResponse(200, { data: closureList })

    if (route === `POST ${base}/horario`) {
      const body = (await request.json()) as { dia: number; abre: string; cierra: string }
      bodies.push(body)
      const overlaps = slotList.some(
        (s) => s.dia === body.dia && body.abre < s.cierra && s.abre < body.cierra,
      )
      if (overlaps) return problem(409, { type: 'conflicto', detail: 'La franja se traslapa.' })
      const created = slot(`slot-${next++}`, body.dia, body.abre, body.cierra)
      slotList = [...slotList, created]
      return jsonResponse(201, { data: created })
    }
    if (route === `POST ${base}/cierres`) {
      const body = (await request.json()) as { fecha: string; motivo?: string | null }
      bodies.push(body)
      if (closureList.some((c) => c.fecha === body.fecha)) {
        return problem(409, { type: 'conflicto', detail: 'Ya hay un cierre.' })
      }
      const created = { id: `closure-${next++}`, fecha: body.fecha, motivo: body.motivo ?? null }
      closureList = [...closureList, created]
      return jsonResponse(201, { data: created })
    }
    if (request.method === 'DELETE') {
      const slotId = path.match(/\/horario\/([^/]+)$/)?.[1]
      if (slotId !== undefined && slotList.some((s) => s.id === slotId)) {
        slotList = slotList.filter((s) => s.id !== slotId)
        return new Response(null, { status: 204 })
      }
      const closureId = path.match(/\/cierres\/([^/]+)$/)?.[1]
      if (closureId !== undefined && closureList.some((c) => c.id === closureId)) {
        closureList = closureList.filter((c) => c.id !== closureId)
        return new Response(null, { status: 204 })
      }
    }
    return problem(404, { type: 'no-encontrado' })
  })

  return {
    shopId,
    base,
    calls,
    bodies,
    fetchMock,
    slots: () => slotList,
    closures: () => closureList,
  }
}
