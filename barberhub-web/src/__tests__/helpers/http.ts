import { vi } from 'vitest'

/** Respuesta JSON; las de error usan application/problem+json como la API. */
export function jsonResponse(status: number, body?: unknown, headers: HeadersInit = {}): Response {
  const contentType = status >= 400 ? 'application/problem+json' : 'application/json'
  return new Response(body === undefined ? null : JSON.stringify(body), {
    status,
    headers: { 'Content-Type': contentType, ...headers },
  })
}

export function problem(
  status: number,
  body: Record<string, unknown> = {},
  headers: HeadersInit = {},
): Response {
  return jsonResponse(status, { status, ...body }, headers)
}

/** Simula fetch: ninguna prueba llama a la API real. */
export function mockFetch(handler: (request: Request) => Response | Promise<Response>) {
  const fetchMock = vi.fn(async (request: Request) => handler(request))
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

export function pathOf(request: Request): string {
  return `${request.method} ${new URL(request.url).pathname}`
}
