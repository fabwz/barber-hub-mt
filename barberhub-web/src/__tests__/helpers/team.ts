import { jsonResponse, mockFetch, pathOf, problem } from './http'
import { service } from './services'
import { slot, type SlotItem } from './schedule'
import { OWNER_SHOP, SHOP_A, SHOP_B, STAFF_SHOP, staffHandler } from './staff'

export interface CollaboratorItem {
  id: string
  nombre: string
  correo: string
  es_dueno: boolean
  activo: boolean
  servicios: string[]
  turnos: Array<{ dia: number; inicia: string; termina: string }>
  pausas: Array<{ dia: number; inicia: string; termina: string }>
}

export function collaborator(over: Partial<CollaboratorItem> = {}): CollaboratorItem {
  return {
    id: '0190b1f0-0000-7000-8000-0000000000c1',
    nombre: 'Daniela Mora',
    correo: 'daniela@correo.cr',
    es_dueno: false,
    activo: true,
    servicios: [],
    turnos: [],
    pausas: [],
    ...over,
  }
}

export const CLASICO = service({ id: 'svc-clasico' })
export const RAPADO = service({
  id: 'svc-rapado',
  nombre: 'Rapado',
  duracion_minutos: 20,
  precio: 5500,
})
export const MONDAY_HOURS: SlotItem[] = [slot('h1', 1, '09:00', '19:00')]

export interface BlockingReservation {
  id: string
  inicio: string
  servicio: string
  cliente: string
}

type Override = (request: Request) => Response | undefined

/**
 * The team API of the owner, in memory: the collaborators, the services and the schedule of the shop. Deactivating a
 * collaborator named in `blocking` answers the 409 `colaborador-con-reservas` with its reservations (CO-03).
 * `override` gets each request first. `calls` lists «METHOD path» and `bodies` the JSON of every write, in order.
 */
export function teamApi({
  role = 'dueno',
  collaborators = [],
  services = [CLASICO, RAPADO],
  slots = MONDAY_HOURS,
  blocking = {},
  total = {},
  override = () => undefined,
}: {
  role?: 'dueno' | 'colaborador'
  collaborators?: CollaboratorItem[]
  services?: unknown[]
  slots?: SlotItem[]
  blocking?: Record<string, BlockingReservation[]>
  total?: Record<string, number>
  override?: Override
} = {}) {
  const shopId = role === 'dueno' ? SHOP_A : SHOP_B
  const base = `/api/v1/barberias/${shopId}/administracion`
  const calls: string[] = []
  const bodies: Array<{ route: string; body: unknown }> = []
  let list = [...collaborators]
  let next = 700

  const fetchMock = mockFetch(async (request) => {
    const route = pathOf(request)
    if (route.includes('/administracion/')) calls.push(route)
    const forced = override(request)
    if (forced !== undefined) return forced
    const fallback = staffHandler([OWNER_SHOP, STAFF_SHOP])(request)
    if (fallback.status !== 404) return fallback

    const path = new URL(request.url).pathname
    if (route === `GET ${base}/servicios`) return jsonResponse(200, { data: services })
    if (route === `GET ${base}/horario`) return jsonResponse(200, { data: slots })
    if (route === `GET ${base}/cierres`) return jsonResponse(200, { data: [] })
    if (route === `GET ${base}/colaboradores`) return jsonResponse(200, { data: list })

    if (route === `POST ${base}/colaboradores`) {
      const body = (await request.json()) as { nombre: string; correo: string }
      bodies.push({ route, body })
      if (list.some((item) => item.correo === body.correo)) {
        return problem(422, {
          type: 'datos-invalidos',
          errores: { correo: ['Este correo ya está registrado.'] },
        })
      }
      const created = collaborator({
        id: `0190b1f0-0000-7000-8000-${String(next++).padStart(12, '0')}`,
        nombre: body.nombre,
        correo: body.correo,
      })
      list = [...list, created]
      return jsonResponse(201, { data: created })
    }
    if (route === `POST ${base}/colaboradores/mi-perfil`) {
      if (list.some((item) => item.es_dueno)) return problem(409, { type: 'conflicto' })
      const created = collaborator({
        id: `0190b1f0-0000-7000-8000-${String(next++).padStart(12, '0')}`,
        nombre: 'Marco Solís',
        correo: 'marco@example.test',
        es_dueno: true,
      })
      list = [...list, created]
      return jsonResponse(201, { data: created })
    }

    const match = path.match(/\/colaboradores\/([^/]+)(\/servicios|\/horario)?$/)
    const current = list.find((item) => item.id === match?.[1])
    if (match !== null && current !== undefined) {
      const body = (await request.json()) as Record<string, unknown>
      bodies.push({ route, body })
      let updated = current
      if (request.method === 'PATCH') {
        if (body.activo === false && (blocking[current.id]?.length ?? 0) > 0) {
          return problem(409, {
            type: 'colaborador-con-reservas',
            detail: 'El colaborador tiene reservas.',
            reservas: blocking[current.id],
            total_reservas: total[current.id] ?? blocking[current.id]!.length,
          })
        }
        updated = { ...current, activo: Boolean(body.activo) }
      } else if (match[2] === '/servicios') {
        updated = { ...current, servicios: body.servicios as string[] }
      } else if (match[2] === '/horario') {
        updated = {
          ...current,
          turnos: body.turnos as CollaboratorItem['turnos'],
          pausas: body.pausas as CollaboratorItem['pausas'],
        }
      }
      list = list.map((item) => (item.id === updated.id ? updated : item))
      return jsonResponse(200, { data: updated })
    }
    return problem(404, { type: 'no-encontrado' })
  })

  return { shopId, base, calls, bodies, fetchMock, current: () => list }
}
