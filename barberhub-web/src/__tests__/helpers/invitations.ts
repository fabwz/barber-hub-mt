import { jsonResponse, mockFetch, pathOf, problem } from './http'
import { service } from './services'
import { OWNER_SHOP, SHOP_A, STAFF_SHOP, staffHandler } from './staff'

export interface InvitationItem {
  id: string
  correo: string
  servicios: string[]
  enviada: string
  vence: string
}

export function invitation(over: Partial<InvitationItem> = {}): InvitationItem {
  return {
    id: '0190b1f0-0000-7000-8000-0000000000e1',
    correo: 'lucia@correo.cr',
    servicios: [],
    enviada: '2026-11-05T09:14:00-06:00',
    vence: '2026-11-12T09:14:00-06:00',
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

type Override = (request: Request) => Response | undefined

/**
 * The invitations API of the owner, in memory: the pending invitations and the services of the shop. Inviting an email that
 * has one pending replaces it, as the API does. `override` gets each request first. `calls` lists «METHOD path» and
 * `bodies` the JSON of every write, in order.
 */
export function invitationsApi({
  invitations = [],
  services = [CLASICO, RAPADO],
  override = () => undefined,
}: {
  invitations?: InvitationItem[]
  services?: unknown[]
  override?: Override
} = {}) {
  const base = `/api/v1/barberias/${SHOP_A}/administracion`
  const calls: string[] = []
  const bodies: Array<{ route: string; body: unknown }> = []
  let list = [...invitations]
  let next = 900

  const fetchMock = mockFetch(async (request) => {
    const route = pathOf(request)
    if (route.includes('/administracion/')) calls.push(route)
    const forced = override(request)
    if (forced !== undefined) return forced
    const fallback = staffHandler([OWNER_SHOP, STAFF_SHOP])(request)
    if (fallback.status !== 404) return fallback

    if (route === `GET ${base}/servicios`) return jsonResponse(200, { data: services })
    if (route === `GET ${base}/invitaciones`) return jsonResponse(200, { data: list })

    if (route === `POST ${base}/invitaciones`) {
      const body = (await request.json()) as { correo: string; servicios: string[] }
      bodies.push({ route, body })
      const created = invitation({
        id: `0190b1f0-0000-7000-8000-${String(next++).padStart(12, '0')}`,
        correo: body.correo.toLowerCase(),
        servicios: body.servicios,
        enviada: new Date().toISOString(),
        vence: new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString(),
      })
      list = [created, ...list.filter((item) => item.correo !== created.correo)]
      return jsonResponse(201, { data: created })
    }

    const cancel = route.match(new RegExp(`^DELETE ${base}/invitaciones/(.+)$`))
    if (cancel !== null) {
      const id = cancel[1]
      if (!list.some((item) => item.id === id)) return problem(404, { type: 'no-encontrado' })
      list = list.filter((item) => item.id !== id)
      return new Response(null, { status: 204 })
    }
    return problem(404, { type: 'no-encontrado' })
  })

  return { shopId: SHOP_A, base, calls, bodies, fetchMock, current: () => list }
}

export const LINK_TOKEN = 'a1b2c3d4e5'

export const INVALID_LINK =
  'El enlace de la invitación no es válido o ya venció. Pida a la barbería que le envíe otra.'

/**
 * The public routes of an invitation, in memory: what the link invites to and its acceptance. `known` is the token the API
 * knows; any other is an unknown link. `existing` says the email already has an account. `bodies` lists the JSON of every
 * call and `accepted` how many times the link was accepted.
 */
export function invitationLinkApi({
  known = LINK_TOKEN,
  existing = false,
  barbershop = 'Pura Navaja',
  email = 'lucia@correo.cr',
  override = () => undefined,
}: {
  known?: string
  existing?: boolean
  barbershop?: string
  email?: string
  override?: Override
} = {}) {
  const calls: string[] = []
  const bodies: Array<{ route: string; body: Record<string, unknown> }> = []
  let accepted = 0

  const fetchMock = mockFetch(async (request) => {
    const route = pathOf(request)
    const forced = override(request)
    if (forced !== undefined) return forced
    if (
      route !== 'POST /api/v1/invitaciones/consulta' &&
      route !== 'POST /api/v1/invitaciones/aceptacion'
    ) {
      return problem(404, { type: 'no-encontrado' })
    }
    calls.push(route)
    const body = (await request.json()) as Record<string, unknown>
    bodies.push({ route, body })

    if (body.token !== known || accepted > 0) {
      return problem(422, {
        type: 'datos-invalidos',
        errores: { token: [INVALID_LINK] },
      })
    }
    if (route === 'POST /api/v1/invitaciones/consulta') {
      return jsonResponse(200, {
        data: { barberia: barbershop, correo: email, cuenta_existente: existing },
      })
    }
    accepted++
    return new Response(null, { status: 204 })
  })

  return { fetchMock, calls, bodies, accepted: () => accepted }
}
