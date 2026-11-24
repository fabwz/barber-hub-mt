import { jsonResponse, mockFetch, pathOf } from './http'
import { OWNER_SHOP, SHOP_A, staffHandler } from './staff'

export const BUSINESS = {
  id: SHOP_A,
  nombre: 'Pura Navaja',
  ciudad: 'Ciudad Quesada',
  telefono: '2460-1234',
  enlace: 'pura-navaja',
  direccion: '200 m norte de la iglesia',
  correo_contacto: 'contacto@puranavaja.cr',
  redes: {
    whatsapp: 'https://wa.me/50688123456',
    instagram: 'https://instagram.com/puranavaja',
    facebook: null as string | null,
    tiktok: null as string | null,
  },
}

export const FEATURES = {
  bonos_activos: true,
  lista_espera_activa: true,
  aparece_en_directorio: false,
}

export const BILLING = {
  modalidad: 'deposito',
  porcentaje_deposito: 30 as number | null,
  plazo_cancelacion_horas: 4,
  pasarela: 'onvo' as string | null,
}

type Override = (request: Request) => Response | Promise<Response> | undefined

/**
 * The configuration API of the owner, in memory. `override` gets each request first: a Response answers it,
 * `undefined` lets the normal answer through. `calls` lists «METHOD path» of every administration request and
 * `bodies` the JSON body of each PATCH, in order.
 */
export function settingsApi({
  business = BUSINESS,
  features = FEATURES,
  billing = BILLING,
  cover = { logo: null as string | null, banner: null as string | null },
  override = () => undefined,
}: {
  business?: typeof BUSINESS
  features?: typeof FEATURES
  billing?: typeof BILLING
  cover?: { logo: string | null; banner: string | null }
  override?: Override
} = {}) {
  const base = `/api/v1/barberias/${SHOP_A}/administracion`
  const calls: string[] = []
  const bodies: Array<Record<string, unknown>> = []
  let currentBusiness = structuredClone(business)
  let currentFeatures = { ...features }
  let currentBilling = { ...billing }
  let images = { ...cover }

  const fetchMock = mockFetch(async (request) => {
    const route = pathOf(request)
    if (route.startsWith('GET /api/v1/enlaces/') || route.includes('/administracion/')) {
      calls.push(route)
    }
    const forced = await override(request)
    if (forced !== undefined) return forced
    const fallback = staffHandler([OWNER_SHOP])(request)
    if (fallback.status !== 404) return fallback

    if (route === `GET /api/v1/enlaces/${currentBusiness.enlace}`) {
      return jsonResponse(200, {
        data: {
          id: SHOP_A,
          nombre: currentBusiness.nombre,
          ciudad: currentBusiness.ciudad,
          telefono: currentBusiness.telefono,
          lista_espera_activa: currentFeatures.lista_espera_activa,
          ...(images.logo === null ? {} : { logo: images.logo }),
          ...(images.banner === null ? {} : { banner: images.banner }),
        },
      })
    }
    if (route === `GET ${base}/negocio`) return jsonResponse(200, { data: currentBusiness })
    if (route === `GET ${base}/funciones`) return jsonResponse(200, { data: currentFeatures })
    if (route === `GET ${base}/cobro`) return jsonResponse(200, { data: currentBilling })

    if (route === `PATCH ${base}/negocio`) {
      const body = (await request.json()) as Record<string, unknown> & {
        redes?: Record<string, string | null>
      }
      bodies.push(body)
      const { redes, ...rest } = body
      currentBusiness = {
        ...currentBusiness,
        ...rest,
        redes: { ...currentBusiness.redes, ...redes },
      } as typeof BUSINESS
      return jsonResponse(200, { data: currentBusiness })
    }
    if (route === `PATCH ${base}/funciones`) {
      const body = (await request.json()) as Record<string, unknown>
      bodies.push(body)
      currentFeatures = { ...currentFeatures, ...body }
      return jsonResponse(200, { data: currentFeatures })
    }
    if (route === `PATCH ${base}/cobro`) {
      const body = (await request.json()) as Record<string, unknown>
      bodies.push(body)
      currentBilling = {
        ...currentBilling,
        ...(body.modalidad !== undefined ? { modalidad: body.modalidad as string } : {}),
        ...(body.modalidad !== undefined || body.porcentaje_deposito !== undefined
          ? { porcentaje_deposito: (body.porcentaje_deposito as number | undefined) ?? null }
          : {}),
        ...(body.plazo_cancelacion_horas !== undefined
          ? { plazo_cancelacion_horas: body.plazo_cancelacion_horas as number }
          : {}),
        ...(body.pasarela !== undefined ? { pasarela: body.pasarela as string } : {}),
      }
      return jsonResponse(200, { data: currentBilling })
    }
    if (route === `POST ${base}/imagenes/logo`) {
      images = { ...images, logo: '/api/v1/archivos/barbershops/x/logo/nuevo.png' }
      return jsonResponse(200, { data: images })
    }
    if (route === `POST ${base}/imagenes/banner`) {
      images = { ...images, banner: '/api/v1/archivos/barbershops/x/banner/nuevo.webp' }
      return jsonResponse(200, { data: images })
    }
    return jsonResponse(404, { status: 404 })
  })

  return { calls, bodies, fetchMock, shopId: SHOP_A }
}
