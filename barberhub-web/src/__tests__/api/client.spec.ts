import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  API_BASE_URL,
  ApiError,
  createApiClient,
  setTokenProvider,
  setUnauthorizedHandler,
} from '@/api'

// Node no resuelve URL relativas en Request (el navegador sí): las pruebas usan una base absoluta ficticia.
const api = createApiClient('http://localhost/api/v1')
const fetchMock = vi.fn<typeof fetch>()

function problem(status: number, body: Record<string, unknown>, headers: HeadersInit = {}) {
  return new Response(JSON.stringify({ status, ...body }), {
    status,
    headers: { 'Content-Type': 'application/problem+json', ...headers },
  })
}

/** Ejecuta una llamada que debe fallar y devuelve el ApiError. */
async function failure(call: () => Promise<unknown>): Promise<ApiError> {
  try {
    await call()
  } catch (error) {
    expect(error).toBeInstanceOf(ApiError)
    return error as ApiError
  }
  throw new Error('Se esperaba un ApiError')
}

const login = () =>
  api.POST('/tokens', {
    body: { correo: 'ana@example.test', contrasena: 'no-real', dispositivo: 'prueba' },
  })

beforeEach(() => {
  fetchMock.mockReset()
  vi.stubGlobal('fetch', fetchMock)
  setTokenProvider(() => null)
  setUnauthorizedHandler(() => {})
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('cliente HTTP', () => {
  it('usa /api/v1 como dirección base relativa', () => {
    expect(API_BASE_URL).toBe('/api/v1')
  })

  it('devuelve los datos de una respuesta correcta', async () => {
    fetchMock.mockResolvedValue(
      Response.json({ nombre: 'BarberHub', version: 'v1' }, { status: 200 }),
    )

    const { data } = await api.GET('/')

    expect(data).toEqual({ nombre: 'BarberHub', version: 'v1' })
    const request = fetchMock.mock.calls[0]![0] as Request
    expect(new URL(request.url).pathname).toBe('/api/v1/')
    expect(request.headers.get('Accept')).toBe('application/json')
  })

  it('agrega Authorization: Bearer cuando el proveedor devuelve un token', async () => {
    fetchMock.mockResolvedValue(Response.json({ nombre: 'BarberHub', version: 'v1' }))
    setTokenProvider(() => 'token-de-prueba')

    await api.GET('/')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.headers.get('Authorization')).toBe('Bearer token-de-prueba')
  })

  it('no agrega Authorization cuando no hay token', async () => {
    fetchMock.mockResolvedValue(Response.json({ nombre: 'BarberHub', version: 'v1' }))

    await api.GET('/')

    const request = fetchMock.mock.calls[0]![0] as Request
    expect(request.headers.has('Authorization')).toBe(false)
    expect(request.headers.get('Accept')).toBe('application/json')
  })

  it('convierte un 422 en ApiError con los errores por campo', async () => {
    fetchMock.mockResolvedValue(
      problem(422, {
        type: 'http://localhost/problemas/datos-invalidos',
        title: 'Datos inválidos',
        detail: 'Revise los datos enviados.',
        errores: { correo: ['El correo no es válido.'], nombre: ['Es obligatorio.', 'Muy corto.'] },
      }),
    )

    const error = await failure(login)

    expect(error.status).toBe(422)
    expect(error.type).toBe('http://localhost/problemas/datos-invalidos')
    expect(error.title).toBe('Datos inválidos')
    expect(error.detail).toBe('Revise los datos enviados.')
    expect(error.errors).toEqual({
      correo: ['El correo no es válido.'],
      nombre: ['Es obligatorio.', 'Muy corto.'],
    })
  })

  it('conserva las alternativas del 409 de espacio no disponible', async () => {
    const alternativas = [
      { barbero: 'b-1', inicio: '2030-03-05T10:00:00-06:00', fin: '2030-03-05T10:30:00-06:00' },
      { barbero: 'b-1', inicio: '2030-03-05T10:30:00-06:00', fin: '2030-03-05T11:00:00-06:00' },
    ]
    fetchMock.mockResolvedValue(
      problem(409, {
        type: 'http://localhost/problemas/espacio-no-disponible',
        title: 'Espacio no disponible',
        detail: 'El espacio ya no está disponible.',
        alternativas,
      }),
    )

    const error = await failure(() =>
      api.POST('/barberias/{barberia}/reservas', {
        params: { path: { barberia: '0190a000-0000-7000-8000-000000000000' } },
        body: { servicio: 's-1', barbero: 'b-1', inicio: '2030-03-05T10:00:00-06:00' },
      }),
    )

    expect(error.status).toBe(409)
    expect(error.type).toBe('http://localhost/problemas/espacio-no-disponible')
    expect(error.alternatives).toEqual(alternativas)
  })

  it('un 401 llama al manejador registrado y lanza el ApiError', async () => {
    const onUnauthorized = vi.fn()
    setUnauthorizedHandler(onUnauthorized)
    fetchMock.mockResolvedValue(
      problem(401, { type: 'http://localhost/problemas/no-autenticado', title: 'No autenticado' }),
    )

    const error = await failure(() => api.GET('/barberias'))

    expect(onUnauthorized).toHaveBeenCalledOnce()
    expect(error.status).toBe(401)
  })

  it('un 429 expone retryAfter en segundos', async () => {
    fetchMock.mockResolvedValue(
      problem(
        429,
        {
          type: 'http://localhost/problemas/demasiadas-solicitudes',
          title: 'Demasiadas solicitudes',
        },
        { 'Retry-After': '900' },
      ),
    )

    const error = await failure(login)

    expect(error.status).toBe(429)
    expect(error.retryAfter).toBe(900)
  })

  it('retryAfter es null cuando no hay encabezado', async () => {
    fetchMock.mockResolvedValue(problem(422, { title: 'Datos inválidos' }))

    expect((await failure(login)).retryAfter).toBeNull()
  })

  it('convierte una respuesta de error sin cuerpo problem+json en un ApiError genérico', async () => {
    fetchMock.mockResolvedValue(
      new Response('<html>Bad gateway</html>', {
        status: 502,
        headers: { 'Content-Type': 'text/html' },
      }),
    )

    const error = await failure(() => api.GET('/barberias'))

    expect(error.status).toBe(502)
    expect(error.type).toBe('about:blank')
    expect(error.title).not.toBe('')
    expect(error.detail).not.toBe('')
    expect(error.errors).toEqual({})
    expect(error.alternatives).toEqual([])
  })

  it('tolera un cuerpo JSON mal formado', async () => {
    fetchMock.mockResolvedValue(
      new Response('{no es json', {
        status: 500,
        headers: { 'Content-Type': 'application/problem+json' },
      }),
    )

    const error = await failure(() => api.GET('/barberias'))

    expect(error.status).toBe(500)
    expect(error.type).toBe('about:blank')
  })

  it('convierte un fallo de red en un ApiError con status 0 y tipo «red»', async () => {
    const cause = new TypeError('Failed to fetch')
    fetchMock.mockRejectedValue(cause)

    const error = await failure(() => api.GET('/barberias'))

    expect(error.status).toBe(0)
    expect(error.type).toBe('red')
    expect(error.cause).toBe(cause)
  })
})
