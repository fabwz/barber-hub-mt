import { jsonResponse, pathOf, problem } from './http'
import { collaborator, teamApi, CLASICO, RAPADO, type CollaboratorItem } from './team'

export interface AssistedClientItem {
  id: string
  nombre: string
  telefono: string
  correo: string | null
}

export function assistedClient(over: Partial<AssistedClientItem> = {}): AssistedClientItem {
  return {
    id: '0190b1f0-0000-7000-8000-0000000000e1',
    nombre: 'Rodrigo Chaves',
    telefono: '8812 3456',
    correo: 'rodrigo@correo.cr',
    ...over,
  }
}

export const KEVIN = collaborator({
  id: '0190b1f0-0000-7000-8000-0000000000b1',
  nombre: 'Kevin Araya',
  servicios: [CLASICO.id],
})
export const ANDRES = collaborator({
  id: '0190b1f0-0000-7000-8000-0000000000b2',
  nombre: 'Andrés Mora',
  correo: 'andres@correo.cr',
  servicios: [RAPADO.id],
})
export const INACTIVE = collaborator({
  id: '0190b1f0-0000-7000-8000-0000000000b3',
  nombre: 'Daniela Mora',
  activo: false,
  servicios: [CLASICO.id],
})

/** The ISO of a space of the day in Costa Rica. */
export function space(date: string, time: string): { inicio: string; fin: string } {
  const [hours, minutes] = time.split(':').map(Number)
  const end = new Date(Date.UTC(2000, 0, 1, hours, minutes + 30))
  const endTime = `${String(end.getUTCHours()).padStart(2, '0')}:${String(end.getUTCMinutes()).padStart(2, '0')}`
  return { inicio: `${date}T${time}:00-06:00`, fin: `${date}T${endTime}:00-06:00` }
}

export interface RegisteredBooking {
  [key: string]: unknown
}

/**
 * The API of the assisted reservation, in memory, on top of the team API (services and collaborators). `clients` answers the
 * search by name or phone; `slots` is what the availability gives for the chosen day; `register` decides the answer of
 * `POST /reservas/asistida` (default: 201 with the reservation). `searches` lists the terms asked and `registered` the bodies sent.
 */
export function assistedApi({
  role = 'dueno',
  services,
  servicesFail = false,
  clients = [],
  collaborators = [KEVIN, ANDRES, INACTIVE],
  slots = [],
  searchFails = false,
  availabilityFails = false,
  register,
}: {
  clients?: AssistedClientItem[]
  collaborators?: CollaboratorItem[]
  /** Spaces of every day asked, `{ inicio, fin }` of the barber chosen. */
  slots?: Array<{ inicio: string; fin: string }>
  searchFails?: boolean
  availabilityFails?: boolean
  register?: (body: RegisteredBooking) => Response
  role?: 'dueno' | 'colaborador'
  /** The services of the shop; the default is the pair of the team helper. */
  services?: unknown[]
  servicesFail?: boolean
} = {}) {
  const searches: string[] = []
  const registered: RegisteredBooking[] = []

  const api = teamApi({
    role,
    collaborators,
    ...(services === undefined ? {} : { services }),
    override: (request) => {
      const route = pathOf(request)
      const url = new URL(request.url)
      if (servicesFail && route.endsWith('/administracion/servicios') && request.method === 'GET') {
        return problem(500)
      }
      if (route.endsWith('/clientes-asistidos') && request.method === 'GET') {
        const term = (url.searchParams.get('busqueda') ?? '').toLowerCase()
        searches.push(term)
        if (searchFails) return problem(500)
        return jsonResponse(200, {
          data: clients.filter(
            (item) =>
              item.nombre.toLowerCase().includes(term) ||
              item.telefono.replace(/\s/g, '').includes(term.replace(/\s/g, '')),
          ),
        })
      }
      if (route.endsWith('/disponibilidad') && request.method === 'GET') {
        if (availabilityFails) return problem(500)
        const barber = url.searchParams.get('barbero') ?? ''
        const date = url.searchParams.get('fecha') ?? ''
        const name = collaborators.find((item) => item.id === barber)?.nombre ?? ''
        const today = slots.filter((item) => item.inicio.startsWith(date))
        return jsonResponse(200, {
          data: {
            servicio: CLASICO,
            fecha: date,
            barberos:
              today.length === 0
                ? []
                : [
                    {
                      id: barber,
                      nombre: name,
                      espacios: today.map((item) => ({ ...item, barbero: barber })),
                    },
                  ],
            dias_siguientes: [],
          },
        })
      }
      if (route.endsWith('/reservas/asistida') && request.method === 'POST') {
        return request.json().then((body: RegisteredBooking) => {
          registered.push(body)
          if (register !== undefined) return register(body)
          return jsonResponse(201, {
            data: {
              id: 'res-asistida-1',
              servicio: body.servicio,
              barbero: body.barbero,
              estado: 'confirmada',
              inicio: body.inicio,
              fin: String(body.inicio).replace(/T(\d\d):(\d\d)/, (_all, h: string, m: string) => {
                const total = Number(h) * 60 + Number(m) + 30
                return `T${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`
              }),
              precio: 7000,
              duracion_minutos: 30,
              plazo_cancelacion_horas: 24,
              expira_en: null,
              cliente_asistido: body.cliente_asistido ?? 'cliente-nuevo-id',
            },
          })
        }) as unknown as Response
      }
      return undefined
    },
  })

  return { ...api, searches, registered }
}
