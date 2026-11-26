import { vi } from 'vitest'

import { SESSION_STORAGE_KEY } from '@/stores/session'
import { jsonResponse, pathOf, problem } from './http'

export const ACCOUNT = {
  nombre: 'Laura Brenes',
  telefono: '88881234',
  correo: 'laura.brenes@correo.cr',
  contrasena_temporal: false,
}

export function signIn(email: string = ACCOUNT.correo): void {
  sessionStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify({ token: 'tok', person: { email } }))
}

/** The 403 the API gives to every route but the mandatory change while the password is temporary. */
export function temporaryPasswordProblem(): Response {
  return problem(403, {
    type: 'http://localhost/problemas/contrasena-temporal',
    title: 'Contraseña temporal',
    detail: 'Debe cambiar su contraseña temporal antes de usar el resto de la aplicación.',
  })
}

export interface AccountRoutes {
  /** What `GET /cuenta` and a successful `PUT /cuenta` answer. */
  account?: typeof ACCOUNT
  /** Response of `PUT /cuenta`; by default it echoes the body. */
  put?: (body: Record<string, string>) => Response
  /** Response of `PUT /cuenta/contrasena`; by default 204. */
  password?: (body: Record<string, string>) => Response
  /** What `GET /cuenta/avisos` answers: whether the reminders are on (they are, by default). */
  reminders?: boolean
  /** Response of `GET /cuenta/avisos`; by default 200 with `reminders`. */
  getNotices?: () => Response | Promise<Response>
  /** Response of `PUT /cuenta/avisos`; by default it echoes the body. */
  putNotices?: (body: { recordatorios_correo: unknown }) => Response | Promise<Response>
  /** Answers the routes of the account does not know. */
  other?: (request: Request) => Response
}

/** Handler for `mockFetch` with the three routes of «Mi cuenta» and a log of the requests. */
export function accountHandler(routes: AccountRoutes = {}) {
  const calls: Array<{ route: string; body: Record<string, string> | null }> = []
  const handler = async (request: Request): Promise<Response> => {
    const route = pathOf(request)
    const text = request.method === 'GET' ? '' : await request.clone().text()
    const body = text === '' ? null : (JSON.parse(text) as Record<string, string>)
    calls.push({ route, body })

    if (route === 'GET /api/v1/cuenta')
      return jsonResponse(200, { data: routes.account ?? ACCOUNT })
    if (route === 'PUT /api/v1/cuenta') {
      if (routes.put) return routes.put(body!)
      return jsonResponse(200, {
        data: {
          ...(routes.account ?? ACCOUNT),
          nombre: body!.nombre,
          telefono: body!.telefono,
          correo: body!.correo,
        },
      })
    }
    if (route === 'GET /api/v1/cuenta/avisos') {
      return routes.getNotices
        ? routes.getNotices()
        : jsonResponse(200, { data: { recordatorios_correo: routes.reminders ?? true } })
    }
    if (route === 'PUT /api/v1/cuenta/avisos') {
      const notices = body as unknown as { recordatorios_correo: unknown }
      return routes.putNotices
        ? routes.putNotices(notices)
        : jsonResponse(200, { data: { recordatorios_correo: notices.recordatorios_correo } })
    }
    if (route === 'PUT /api/v1/cuenta/contrasena') {
      return routes.password ? routes.password(body!) : new Response(null, { status: 204 })
    }
    if (routes.other) return routes.other(request)
    return jsonResponse(404, { status: 404 })
  }
  const fetchMock = vi.fn(handler)
  vi.stubGlobal('fetch', fetchMock)
  return { fetchMock, calls }
}
