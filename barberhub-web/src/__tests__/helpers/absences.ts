import { addDays } from '@/composables/format'
import { jsonResponse, pathOf, problem } from './http'
import { collaborator, teamApi, type CollaboratorItem } from './team'

export interface AbsenceItem {
  id: string
  colaborador: string
  inicio: string
  fin: string
  motivo: string | null
}

export function absence(over: Partial<AbsenceItem> = {}): AbsenceItem {
  return {
    id: '0190b1f0-0000-7000-8000-0000000000a1',
    colaborador: 'kevin-id',
    inicio: '2030-03-05T14:00:00-06:00',
    fin: '2030-03-05T16:00:00-06:00',
    motivo: null,
    ...over,
  }
}

export interface ConflictItem {
  id: string
  inicio: string
  fin: string
  servicio: string
  cliente: string
  estado: 'confirmada' | 'temporal'
}

export function conflict(over: Partial<ConflictItem> = {}): ConflictItem {
  return {
    id: 'res-ana',
    inicio: '2030-03-05T14:00:00-06:00',
    fin: '2030-03-05T14:30:00-06:00',
    servicio: 'Corte clásico',
    cliente: 'Ana Quirós',
    estado: 'confirmada',
    ...over,
  }
}

export const KEVIN: CollaboratorItem = collaborator({ id: 'kevin-id', nombre: 'Kevin Araya' })
export const ANDRES: CollaboratorItem = collaborator({
  id: 'andres-id',
  nombre: 'Andrés Mora',
  correo: 'andres@correo.cr',
})
export const INACTIVE: CollaboratorItem = collaborator({
  id: 'inactivo-id',
  nombre: 'Daniela Mora',
  activo: false,
})

/**
 * The blocks of agenda of the owner, in memory, on top of the team API. Saving a block while `conflicts` lists a
 * reservation answers the 409 `ausencia-con-reservas`; cancelling that reservation removes it from the list, as the
 * API would. `list` is the answer of the listing (`'error'` fails it) and `calls` records «METHOD path» of the writes.
 */
export function absencesApi({
  collaborators = [KEVIN, ANDRES, INACTIVE],
  absences = [],
  conflicts = [],
  total,
  listFails = false,
  saveStatus,
  reservationDetail,
}: {
  collaborators?: CollaboratorItem[]
  absences?: AbsenceItem[]
  conflicts?: ConflictItem[]
  total?: number
  listFails?: boolean
  saveStatus?: { status: number; body: Record<string, unknown> }
  /** What `GET /reservas/{reserva}` answers, to move a reservation. */
  reservationDetail?: Record<string, unknown>
} = {}) {
  let list = [...absences]
  let open = [...conflicts]
  let next = 900
  const saved: unknown[] = []
  const cancelled: string[] = []

  const api = teamApi({
    collaborators,
    override: (request) => {
      const route = pathOf(request)
      const path = new URL(request.url).pathname
      if (route.endsWith('/administracion/ausencias') && request.method === 'GET') {
        return listFails ? problem(500) : jsonResponse(200, { data: list })
      }
      if (route.endsWith('/administracion/ausencias') && request.method === 'POST') {
        return request.json().then((body: Record<string, unknown>) => {
          saved.push(body)
          if (saveStatus !== undefined) return problem(saveStatus.status, saveStatus.body)
          if (open.length > 0) {
            return problem(409, {
              type: 'ausencia-con-reservas',
              detail: 'El bloqueo cae sobre reservas del colaborador.',
              reservas: open,
              total_reservas: total ?? open.length,
            })
          }
          const created = absence({
            id: `0190b1f0-0000-7000-8000-${String(next++).padStart(12, '0')}`,
            colaborador: String(body.colaborador),
            inicio: `${String(body.fecha_desde)}T${String(body.hora_desde ?? '00:00')}:00-06:00`,
            fin:
              body.hora_hasta === undefined
                ? `${addDays(String(body.fecha_hasta), 1)}T00:00:00-06:00`
                : `${String(body.fecha_hasta)}T${String(body.hora_hasta)}:00-06:00`,
          })
          list = [...list, created]
          return jsonResponse(201, { data: created })
        }) as unknown as Response
      }
      const removed = path.match(/\/administracion\/ausencias\/([^/]+)$/)
      if (request.method === 'DELETE' && removed !== null) {
        list = list.filter((item) => item.id !== removed[1])
        return new Response(null, { status: 204 })
      }
      if (
        request.method === 'GET' &&
        reservationDetail !== undefined &&
        /\/reservas\/[^/]+$/.test(path)
      ) {
        return jsonResponse(200, { data: reservationDetail })
      }
      const cancel = path.match(/\/reservas\/([^/]+)\/cancelacion$/)
      if (request.method === 'POST' && cancel !== null) {
        cancelled.push(cancel[1]!)
        open = open.filter((item) => item.id !== cancel[1])
        return jsonResponse(200, { data: { id: cancel[1] } })
      }
      return undefined
    },
  })

  return { ...api, saved, cancelled, blocks: () => list }
}
