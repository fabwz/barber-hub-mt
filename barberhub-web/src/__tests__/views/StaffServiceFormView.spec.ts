import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { signIn } from '../helpers/account'
import { problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { service, servicesApi } from '../helpers/services'

beforeEach(() => {
  signIn('marco@example.test')
  // jsdom does not build object URLs.
  URL.createObjectURL = vi.fn(() => 'blob:preview')
  URL.revokeObjectURL = vi.fn()
})
afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

const isImage = (r: Request) => r.method === 'POST' && r.url.endsWith('/imagen')

async function open(path: string, options: Parameters<typeof servicesApi>[0] = {}) {
  const api = servicesApi(options)
  const mounted = await mountView(App, `/barberias/${api.shopId}/servicios${path}`)
  await flushPromises()
  return { ...mounted, ...api }
}

type Wrapper = Awaited<ReturnType<typeof open>>['wrapper']

async function fill(
  wrapper: Wrapper,
  values: { name?: string; duration?: string; price?: string },
) {
  const inputs = wrapper.findAll('input[type="text"], input:not([type])')
  const [name, duration, price] = inputs
  if (values.name !== undefined) await name!.setValue(values.name)
  if (values.duration !== undefined) await duration!.setValue(values.duration)
  if (values.price !== undefined) await price!.setValue(values.price)
}

async function chooseImage(wrapper: Wrapper, file: File) {
  const input = wrapper.get('[data-testid="image-input"]')
  Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
  await input.trigger('change')
}

async function save(wrapper: Wrapper) {
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

const webp = () => new File([new Uint8Array(2048)], 'corte.webp', { type: 'image/webp' })

describe('StaffServiceFormView: new service', () => {
  it('shows the empty form with its helps, no state switch and no «Quitar imagen»', async () => {
    const { wrapper } = await open('/nuevo')
    expect(wrapper.get('h1').text()).toBe('Nuevo servicio')
    expect(wrapper.text()).toContain('De 10 a 180 minutos, en múltiplos de 5.')
    expect(wrapper.text()).toContain('En colones enteros, con el IVA de 13 % ya incluido.')
    expect(wrapper.text()).toContain('JPG, PNG o WebP de hasta 2 MB.')
    expect(wrapper.text()).toContain('Aún sin imagen')
    expect(wrapper.find('[role="switch"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Quitar imagen')
  })

  it('checks the fields before the trip and sends nothing', async () => {
    const { wrapper, calls } = await open('/nuevo')
    await fill(wrapper, { name: '', duration: '33', price: '0' })
    await save(wrapper)

    const text = wrapper.text()
    expect(text).toContain('Escriba el nombre del servicio.')
    expect(text).toContain('La duración debe estar entre 10 y 180 minutos, en múltiplos de 5.')
    expect(text).toContain('El precio debe ser mayor que ₡0, en colones enteros.')
    expect(calls.some((c) => c.startsWith('POST'))).toBe(false)
    expect(wrapper.findAll('input[aria-invalid="true"]')).toHaveLength(3)
  })

  it('keeps only digits in duration and price', async () => {
    const { wrapper } = await open('/nuevo')
    await fill(wrapper, { duration: '3a0', price: '7.000' })
    const [, duration, price] = wrapper.findAll('input[inputmode="numeric"], input')
    expect((duration!.element as HTMLInputElement).value).toBe('30')
    expect((price!.element as HTMLInputElement).value).toBe('7000')
  })

  it('creates the service with the shop in the route and shows it saved', async () => {
    const { wrapper, calls, bodies, base } = await open('/nuevo')
    await fill(wrapper, { name: 'Afeitado', duration: '20', price: '4500' })
    await save(wrapper)

    expect(calls.filter((c) => c.startsWith('POST'))).toEqual([`POST ${base}`])
    expect(bodies).toEqual([{ nombre: 'Afeitado', duracion_minutos: 20, precio: 4500 }])
    expect(wrapper.get('[role="status"]').text()).toBe('Servicio guardado. Ya aparece en su lista.')
  })

  it('creates the service and then uploads the image, in that order', async () => {
    const { wrapper, calls, base } = await open('/nuevo')
    await fill(wrapper, { name: 'Afeitado', duration: '20', price: '4500' })
    await chooseImage(wrapper, webp())
    expect(wrapper.get('[data-testid="image-name"]').text()).toContain('corte.webp')
    await save(wrapper)

    const writes = calls.filter((c) => c.startsWith('POST'))
    expect(writes).toHaveLength(2)
    expect(writes[0]).toBe(`POST ${base}`)
    expect(writes[1]).toMatch(new RegExp(`^POST ${base}/[^/]+/imagen$`))
    expect(wrapper.get('[role="status"]').text()).toContain('Servicio guardado')
    // The new image is the current one now.
    expect(wrapper.get('form img').attributes('src')).toBe('/api/v1/archivos/nueva.webp')
  })

  it('if the upload fails the service exists: the error is on the image and a new try sends only the image', async () => {
    let fail = true
    const { wrapper, calls } = await open('/nuevo', {
      override: (r) =>
        fail && isImage(r)
          ? problem(422, {
              type: 'datos-invalidos',
              errores: { imagen: ['La imagen no es válida.'] },
            })
          : undefined,
    })
    await fill(wrapper, { name: 'Afeitado', duration: '20', price: '4500' })
    await chooseImage(wrapper, webp())
    await save(wrapper)

    expect(wrapper.get('p.text-danger').text()).toBe('La imagen no es válida.')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    expect(calls.filter((c) => /POST .*servicios$/.test(c))).toHaveLength(1)

    fail = false
    await save(wrapper)

    // The service is not created again: one POST of the service, two of the image, no PATCH.
    expect(calls.filter((c) => /POST .*servicios$/.test(c))).toHaveLength(1)
    expect(calls.filter((c) => c.endsWith('/imagen'))).toHaveLength(2)
    expect(calls.some((c) => c.startsWith('PATCH'))).toBe(false)
    expect(wrapper.find('p.text-danger').exists()).toBe(false)
    expect(wrapper.get('[role="status"]').text()).toContain('Servicio guardado')
  })

  it('a network failure on the upload gives a general message on the image', async () => {
    const { wrapper } = await open('/nuevo', {
      override: (r) => (isImage(r) ? problem(500) : undefined),
    })
    await fill(wrapper, { name: 'Afeitado', duration: '20', price: '4500' })
    await chooseImage(wrapper, webp())
    await save(wrapper)
    expect(wrapper.get('p.text-danger').text()).toBe(
      'No pudimos subir la imagen. Inténtelo de nuevo.',
    )
  })

  it('a file over 2 MB or of another type is turned down in the field and never uploaded', async () => {
    const { wrapper, calls } = await open('/nuevo')
    await fill(wrapper, { name: 'Afeitado', duration: '20', price: '4500' })
    await chooseImage(
      wrapper,
      new File([new Uint8Array(3 * 1024 * 1024)], 'rapado.png', { type: 'image/png' }),
    )
    expect(wrapper.text()).toContain('El archivo pesa 3 MB.')
    await save(wrapper)
    expect(calls.some((c) => c.endsWith('/imagen'))).toBe(false)
    // The service itself is saved without image.
    expect(calls.filter((c) => c.startsWith('POST'))).toHaveLength(1)
  })

  it('shows each error of the API in its own field (422)', async () => {
    const { wrapper } = await open('/nuevo', {
      override: (r) =>
        r.method === 'POST' && r.url.endsWith('/servicios')
          ? problem(422, {
              type: 'datos-invalidos',
              errores: { nombre: ['El nombre ya existe.'], precio: ['El precio no es válido.'] },
            })
          : undefined,
    })
    await fill(wrapper, { name: 'Afeitado', duration: '20', price: '4500' })
    await save(wrapper)
    const errors = wrapper.findAll('p.text-danger').map((p) => p.text())
    expect(errors).toEqual(['El nombre ya existe.', 'El precio no es válido.'])
    // The focus goes to the first field with an error.
    expect(document.activeElement).toBe(wrapper.findAll('input')[0]!.element)
  })

  it('a failure that is not about a field shows a general error', async () => {
    const { wrapper } = await open('/nuevo', {
      override: (r) => (r.method === 'POST' ? problem(500, { detail: 'Algo falló.' }) : undefined),
    })
    await fill(wrapper, { name: 'Afeitado', duration: '20', price: '4500' })
    await save(wrapper)
    expect(wrapper.get('[role="alert"]').text()).toBe('Algo falló.')
  })
})

describe('StaffServiceFormView: edit service', () => {
  const CLASICO = service({ imagen: '/api/v1/archivos/a.webp' })
  const path = `/${CLASICO.id}`

  it('fills the form with the service, its state and its current image', async () => {
    const { wrapper } = await open(path, { services: [CLASICO] })
    expect(wrapper.get('h1').text()).toBe('Editar servicio')
    const [name, duration, price] = wrapper
      .findAll('input')
      .map((i) => (i.element as HTMLInputElement).value)
    expect([name, duration, price]).toEqual(['Corte clásico', '30', '7000'])
    expect(wrapper.get('[role="switch"]').attributes('aria-checked')).toBe('true')
    expect(wrapper.get('form img').attributes('src')).toBe('/api/v1/archivos/a.webp')
    expect(wrapper.text()).toContain('Cambiar imagen')
    // The API has no call to remove an image, so the form has no such button.
    expect(wrapper.text()).not.toContain('Quitar imagen')
  })

  it('sends only what changed', async () => {
    const { wrapper, bodies, calls } = await open(path, { services: [CLASICO] })
    await fill(wrapper, { price: '7500' })
    await save(wrapper)
    expect(bodies).toEqual([{ precio: 7500 }])
    expect(calls.some((c) => c.endsWith('/imagen'))).toBe(false)
    expect(wrapper.get('[role="status"]').text()).toContain('Servicio guardado')
  })

  it('inactivates from the switch', async () => {
    const { wrapper, bodies } = await open(path, { services: [CLASICO] })
    await wrapper.get('[role="switch"]').trigger('click')
    await save(wrapper)
    expect(bodies).toEqual([{ activo: false }])
  })

  it('replaces the image without touching the rest', async () => {
    const { wrapper, calls, bodies } = await open(path, { services: [CLASICO] })
    await chooseImage(wrapper, webp())
    await save(wrapper)
    expect(bodies).toEqual([])
    expect(calls.filter((c) => c.endsWith('/imagen'))).toHaveLength(1)
  })

  it('works after a reload: with no list in memory it loads it first', async () => {
    const { wrapper, calls } = await open(path, { services: [CLASICO] })
    expect(calls[0]).toMatch(/^GET .*\/administracion\/servicios$/)
    expect(wrapper.find('form').exists()).toBe(true)
  })

  it('says so when the service is not in the shop', async () => {
    const { wrapper } = await open('/0190b1f0-0000-7000-8000-0000000000ff', {
      services: [CLASICO],
    })
    expect(wrapper.get('[role="alert"]').text()).toContain('No encontramos el servicio')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('a collaborator does not reach the form', async () => {
    const api = servicesApi({ role: 'colaborador' })
    const { router } = await mountView(App, `/barberias/${api.shopId}/servicios/nuevo`)
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('staff-agenda')
  })
})
