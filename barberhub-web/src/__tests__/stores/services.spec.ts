import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { isApiError } from '@/api'
import { useServiceAdminStore } from '@/stores/services'
import { SESSION_STORAGE_KEY } from '@/stores/session'
import { SHOP_A } from '../helpers/staff'
import { jsonResponse, mockFetch, pathOf, problem } from '../helpers/http'

const BASE = `/api/v1/barberias/${SHOP_A}/administracion/servicios`

export function service(over: Record<string, unknown> = {}) {
  return {
    id: '0190b1f0-0000-7000-8000-0000000000a1',
    nombre: 'Corte clásico',
    duracion_minutos: 30,
    precio: 7000,
    base: 6195,
    iva: 805,
    activo: true,
    imagen: null,
    ...over,
  }
}

beforeEach(() => {
  sessionStorage.setItem(
    SESSION_STORAGE_KEY,
    JSON.stringify({ token: 'tok', person: { email: 'marco@example.test' } }),
  )
  setActivePinia(createPinia())
})
afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
})

describe('useServiceAdminStore', () => {
  it('loads the services of the shop of the route', async () => {
    const fetchMock = mockFetch(() =>
      jsonResponse(200, {
        data: [service(), service({ id: 'x', nombre: 'Rapado', activo: false })],
      }),
    )
    const store = useServiceAdminStore()
    await store.load(SHOP_A)
    expect(pathOf(fetchMock.mock.calls[0]![0])).toBe(`GET ${BASE}`)
    expect(store.status).toBe('ready')
    expect(store.items.map((item) => item.nombre)).toEqual(['Corte clásico', 'Rapado'])
  })

  it('ends in error when the list does not load', async () => {
    mockFetch(() => problem(500))
    const store = useServiceAdminStore()
    await store.load(SHOP_A)
    expect(store.status).toBe('error')
  })

  it('creates a service and keeps the list sorted by name', async () => {
    const fetchMock = mockFetch((request) =>
      request.method === 'POST'
        ? jsonResponse(201, { data: service({ id: 'new', nombre: 'Afeitado' }) })
        : jsonResponse(200, { data: [service()] }),
    )
    const store = useServiceAdminStore()
    await store.load(SHOP_A)
    const created = await store.create(SHOP_A, {
      nombre: 'Afeitado',
      duracion_minutos: 20,
      precio: 4500,
    })
    expect(created.id).toBe('new')
    const post = fetchMock.mock.calls.at(-1)![0] as Request
    expect(await post.json()).toEqual({ nombre: 'Afeitado', duracion_minutos: 20, precio: 4500 })
    expect(store.items.map((item) => item.nombre)).toEqual(['Afeitado', 'Corte clásico'])
  })

  it('updates only the fields given and replaces the item', async () => {
    const fetchMock = mockFetch(() => jsonResponse(200, { data: service({ activo: false }) }))
    const store = useServiceAdminStore()
    store.items = [service()]
    await store.update(SHOP_A, service().id, { activo: false })
    const patch = fetchMock.mock.calls[0]![0] as Request
    expect(patch.method).toBe('PATCH')
    expect(await patch.json()).toEqual({ activo: false })
    expect(store.items[0]!.activo).toBe(false)
  })

  it('throws the typed error of the API with its fields (422)', async () => {
    mockFetch(() =>
      problem(422, {
        type: 'datos-invalidos',
        errores: { duracion_minutos: ['La duración debe ser múltiplo de 5.'] },
      }),
    )
    const store = useServiceAdminStore()
    const error = await store
      .create(SHOP_A, { nombre: 'X', duracion_minutos: 33, precio: 1000 })
      .catch((reason: unknown) => reason)
    expect(isApiError(error)).toBe(true)
    expect((error as { errors: Record<string, string[]> }).errors).toHaveProperty(
      'duracion_minutos',
    )
  })

  it('uploads the image as multipart/form-data in the field «imagen»', async () => {
    // Reading a multipart body back hangs in this test environment, so the form is watched where it is built.
    const append = vi.spyOn(FormData.prototype, 'append')
    let contentType: string | null = null
    mockFetch((request) => {
      contentType = request.headers.get('Content-Type')
      return jsonResponse(200, { data: service({ imagen: '/api/v1/archivos/a.webp' }) })
    })
    const store = useServiceAdminStore()
    store.items = [service()]
    const file = new File([new Uint8Array(2048)], 'corte.webp', { type: 'image/webp' })
    const updated = await store.uploadImage(SHOP_A, service().id, file)

    // The only part is the file under the name «imagen», with its own name and type.
    expect(append).toHaveBeenCalledTimes(1)
    const [field, sent] = append.mock.calls[0] as unknown as [string, File]
    expect(field).toBe('imagen')
    expect(sent).toBe(file)
    // The client never forces a JSON content type: the boundary comes with the form.
    expect(contentType).toMatch(/^multipart\/form-data; boundary=/)
    append.mockRestore()
    expect(updated.imagen).toBe('/api/v1/archivos/a.webp')
    expect(store.items[0]!.imagen).toBe('/api/v1/archivos/a.webp')
  })

  it('an image error (422) names the field «imagen»', async () => {
    mockFetch(() =>
      problem(422, { type: 'datos-invalidos', errores: { imagen: ['No es una imagen válida.'] } }),
    )
    const store = useServiceAdminStore()
    const file = new File(['x'], 'a.jpg', { type: 'image/jpeg' })
    const error = await store.uploadImage(SHOP_A, service().id, file).catch((r: unknown) => r)
    expect((error as { errors: Record<string, string[]> }).errors.imagen).toEqual([
      'No es una imagen válida.',
    ])
  })
})
