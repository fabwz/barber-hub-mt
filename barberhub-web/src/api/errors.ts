/**
 * Error tipado de la API. Todo fallo del cliente HTTP (respuesta con error,
 * cuerpo que no es problem+json o fallo de red) llega como un ApiError.
 */

export const NETWORK_ERROR_TYPE = 'red'
export const UNKNOWN_ERROR_TYPE = 'about:blank'

/** Mensajes de respaldo, solo cuando la API no trae `title` o `detail`. */
const FALLBACK = {
  title: 'Ocurrió un error inesperado',
  detail: 'No se pudo completar la operación. Intente de nuevo.',
  networkTitle: 'Sin conexión',
  networkDetail: 'No se pudo comunicar con el servidor. Revise su conexión e intente de nuevo.',
} as const

export interface ApiErrorInit {
  status: number
  type?: string
  title?: string
  detail?: string
  errors?: Record<string, string[]>
  alternatives?: unknown[]
  retryAfter?: number | null
  cause?: unknown
}

export class ApiError extends Error {
  /** Código HTTP; 0 cuando no hubo respuesta (fallo de red). */
  readonly status: number
  /** Tipo estable del error (`type` de problem+json), «red» o «about:blank». */
  readonly type: string
  readonly title: string
  readonly detail: string
  /** Mensajes por campo (`errores` de la API, 422). */
  readonly errors: Record<string, string[]>
  /** Espacios sugeridos del 409 `espacio-no-disponible`, tal cual los devuelve la API. */
  readonly alternatives: unknown[]
  /** Segundos de espera del encabezado `Retry-After` (429); null si no vino. */
  readonly retryAfter: number | null

  constructor(init: ApiErrorInit) {
    const title = init.title ?? FALLBACK.title
    const detail = init.detail ?? FALLBACK.detail
    super(detail, init.cause === undefined ? undefined : { cause: init.cause })
    this.name = 'ApiError'
    this.status = init.status
    this.type = init.type ?? UNKNOWN_ERROR_TYPE
    this.title = title
    this.detail = detail
    this.errors = init.errors ?? {}
    this.alternatives = init.alternatives ?? []
    this.retryAfter = init.retryAfter ?? null
  }
}

export function isApiError(value: unknown): value is ApiError {
  return value instanceof ApiError
}

/** Fallo de red: la petición no obtuvo respuesta. */
export function networkError(cause?: unknown): ApiError {
  return new ApiError({
    status: 0,
    type: NETWORK_ERROR_TYPE,
    title: FALLBACK.networkTitle,
    detail: FALLBACK.networkDetail,
    cause,
  })
}

function asNonEmptyString(value: unknown): string | undefined {
  return typeof value === 'string' && value !== '' ? value : undefined
}

/** El contrato declara `errores` como campo → lista de textos; se leen solo las entradas válidas. */
function readFieldErrors(value: unknown): Record<string, string[]> {
  const result: Record<string, string[]> = {}
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return result
  for (const [field, messages] of Object.entries(value)) {
    if (Array.isArray(messages)) {
      result[field] = messages.filter((m): m is string => typeof m === 'string')
    } else if (typeof messages === 'string') {
      result[field] = [messages]
    }
  }
  return result
}

/** `Retry-After` en segundos, o una fecha HTTP convertida a los segundos que faltan. */
function readRetryAfter(header: string | null): number | null {
  if (header === null || header.trim() === '') return null
  const text = header.trim()
  if (/^\d+$/.test(text)) return Number(text)
  const date = Date.parse(text)
  return Number.isNaN(date) ? null : Math.max(0, Math.ceil((date - Date.now()) / 1000))
}

async function readBody(response: Response): Promise<Record<string, unknown>> {
  const contentType = response.headers.get('Content-Type') ?? ''
  if (!contentType.includes('json')) return {}
  try {
    const body: unknown = await response.clone().json()
    return typeof body === 'object' && body !== null && !Array.isArray(body)
      ? (body as Record<string, unknown>)
      : {}
  } catch {
    return {}
  }
}

/**
 * Convierte una respuesta con error en un ApiError. Lee de forma defensiva el
 * cuerpo application/problem+json (el contrato no declara `alternativas` y no
 * declara los errores 409 ni 429); si no hay cuerpo válido usa mensajes genéricos.
 */
export async function readApiError(response: Response): Promise<ApiError> {
  const body = await readBody(response)
  return new ApiError({
    status: response.status,
    type: asNonEmptyString(body.type),
    title: asNonEmptyString(body.title),
    detail: asNonEmptyString(body.detail),
    errors: readFieldErrors(body.errores),
    alternatives: Array.isArray(body.alternativas) ? body.alternativas : [],
    retryAfter: readRetryAfter(response.headers.get('Retry-After')),
  })
}
