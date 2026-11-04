import createClient, { type Middleware } from 'openapi-fetch'
import { networkError, readApiError } from './errors'
import type { paths } from './schema'

/**
 * Las rutas del contrato (`/barberias`, …) son relativas a `servers.url` =
 * `/api/v1`, así que ese es el prefijo. La dirección es relativa al origen del
 * cliente: en desarrollo el proxy de Vite la reenvía a VITE_API_URL.
 */
export const API_BASE_URL = '/api/v1'

type TokenProvider = () => string | null | undefined

let tokenProvider: TokenProvider = () => null
let unauthorizedHandler: () => void = () => {}

/** La tienda de sesión registra aquí cómo obtener el token vigente. */
export function setTokenProvider(provider: TokenProvider): void {
  tokenProvider = provider
}

/** La tienda de sesión registra aquí qué hacer ante un 401 (cerrar la sesión local). */
export function setUnauthorizedHandler(handler: () => void): void {
  unauthorizedHandler = handler
}

const middleware: Middleware = {
  onRequest({ request }) {
    const token = tokenProvider()
    if (token) request.headers.set('Authorization', `Bearer ${token}`)
    return request
  },

  async onResponse({ response }) {
    if (response.ok) return undefined
    const error = await readApiError(response)
    if (response.status === 401) unauthorizedHandler()
    throw error
  },

  onError({ error }) {
    // Una cancelación hecha por quien llama no es un fallo de red.
    if (error instanceof Error && error.name === 'AbortError') return error
    return networkError(error)
  },
}

/**
 * Crea un cliente tipado con el middleware común. Existe para poder apuntar a
 * otra dirección base en las pruebas; el resto del código usa `api`.
 */
export function createApiClient(baseUrl: string = API_BASE_URL) {
  const client = createClient<paths>({
    baseUrl,
    headers: { Accept: 'application/json' },
    // Se resuelve en cada llamada para que las pruebas puedan simular fetch.
    fetch: (...args) => globalThis.fetch(...args),
  })
  client.use(middleware)
  return client
}

/** Cliente HTTP único. Ante cualquier error lanza un ApiError. */
export const api = createApiClient()
