import { jsonResponse, mockFetch, pathOf } from './http'
import { service } from './services'
import { OWNER_SHOP, SHOP_A, SHOP_B, STAFF_SHOP, staffHandler } from './staff'

export const CLASSIC = service()
export const FADE = service({
  id: '0190b1f0-0000-7000-8000-0000000000a2',
  nombre: 'Corte degradado',
  duracion_minutos: 45,
  precio: 9000,
})
export const BEARD = service({
  id: '0190b1f0-0000-7000-8000-0000000000a3',
  nombre: 'Barba',
  duracion_minutos: 20,
  precio: 4000,
})
export const OLD_CUT = service({
  id: '0190b1f0-0000-7000-8000-0000000000a4',
  nombre: 'Corte antiguo',
  activo: false,
})

export function voucher(over: Record<string, unknown> = {}) {
  return {
    id: '0190b1f0-0000-7000-8000-0000000000b1',
    nombre: 'Bono 5 cortes',
    sesiones: 5,
    precio: 32000,
    vigencia_dias: 90,
    servicios: [CLASSIC.id, FADE.id],
    activo: true,
    tiene_ventas: false,
    ...over,
  }
}

export function soldVoucher(over: Record<string, unknown> = {}) {
  return {
    id: '0190b1f0-0000-7000-8000-0000000000c1',
    nombre: 'Bono 5 cortes',
    sesiones_totales: 5,
    saldo: 3,
    estado: 'activo',
    activado_en: '2026-10-17T09:00:00-06:00',
    vence_en: '2027-01-15T09:00:00-06:00',
    movimientos: [],
    cliente_nombre: 'Laura Brenes',
    ...over,
  }
}

type Override = (request: Request) => Response | undefined

/**
 * The vouchers API of the owner, in memory: the list, create and edit, the services catalog, the switches and the
 * sold vouchers by page. `override` gets each request first: a Response answers it, undefined lets the normal answer
 * through. `calls` lists «METHOD path?query» of every administration request.
 */
export function voucherDefinitionsApi({
  role = 'dueno',
  vouchers = [voucher()],
  services = [CLASSIC, FADE, BEARD],
  sold = [],
  vouchersEnabled = true,
  override = () => undefined,
}: {
  role?: 'dueno' | 'colaborador'
  vouchers?: unknown[]
  services?: unknown[]
  sold?: unknown[]
  vouchersEnabled?: boolean
  override?: Override
} = {}) {
  const shopId = role === 'dueno' ? SHOP_A : SHOP_B
  const base = `/api/v1/barberias/${shopId}/administracion`
  const calls: string[] = []
  /** The JSON body of every POST and PATCH, in order. */
  const bodies: Array<Record<string, unknown>> = []
  let list = [...vouchers] as Array<ReturnType<typeof voucher>>
  let nextId = 100

  const fetchMock = mockFetch(async (request) => {
    const route = pathOf(request)
    const url = new URL(request.url)
    if (url.pathname.includes('/administracion/'))
      calls.push(`${request.method} ${url.pathname}${url.search}`)
    const forced = override(request)
    if (forced !== undefined) return forced
    const fallback = staffHandler([OWNER_SHOP, STAFF_SHOP])(request)
    if (fallback.status !== 404) return fallback

    if (route === `GET ${base}/servicios`) return jsonResponse(200, { data: services })
    if (route === `GET ${base}/funciones`) {
      return jsonResponse(200, {
        data: {
          bonos_activos: vouchersEnabled,
          lista_espera_activa: true,
          aparece_en_directorio: false,
        },
      })
    }
    if (route === `GET ${base}/bonos`) return jsonResponse(200, { data: list })
    if (route === `POST ${base}/bonos`) {
      const body = (await request.json()) as Record<string, unknown>
      bodies.push(body)
      const id = `0190b1f0-0000-7000-8000-${String(nextId++).padStart(12, '0')}`
      const created = voucher({ ...body, id, activo: true, tiene_ventas: false })
      list = [...list, created]
      return jsonResponse(201, { data: created })
    }
    if (route === `GET ${base}/bonos-vendidos`) {
      const perPage = Number(url.searchParams.get('por_pagina') ?? 15)
      const page = Number(url.searchParams.get('pagina') ?? 1)
      return jsonResponse(200, {
        data: sold.slice((page - 1) * perPage, page * perPage),
        meta: {
          pagina: page,
          por_pagina: perPage,
          total: sold.length,
          ultima_pagina: Math.max(1, Math.ceil(sold.length / perPage)),
        },
      })
    }
    const match = url.pathname.match(new RegExp(`^${base}/bonos/([^/]+)$`))
    const current = list.find((item) => item.id === match?.[1])
    if (match !== null && current !== undefined && request.method === 'PATCH') {
      const body = (await request.json()) as Record<string, unknown>
      bodies.push(body)
      const updated = { ...current, ...body }
      list = list.map((item) => (item.id === updated.id ? updated : item))
      return jsonResponse(200, { data: updated })
    }
    return jsonResponse(404, { status: 404, type: 'no-encontrado' })
  })

  return { shopId, calls, bodies, fetchMock, base, current: () => list }
}
