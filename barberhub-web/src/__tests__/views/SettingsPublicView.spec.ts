import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { signIn } from '../helpers/account'
import { problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { settingsApi } from '../helpers/settings'
import { SHOP_A } from '../helpers/staff'

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

async function open(options: Parameters<typeof settingsApi>[0] = {}) {
  const api = settingsApi(options)
  const mounted = await mountView(App, `/barberias/${SHOP_A}/configuracion`)
  await flushPromises()
  return { ...mounted, ...api }
}

type Wrapper = Awaited<ReturnType<typeof open>>['wrapper']

function input(wrapper: Wrapper, label: string): HTMLInputElement {
  const field = wrapper.findAll('label').find((item) => item.text() === label)
  return wrapper.get(`#${field!.attributes('for')}`).element as HTMLInputElement
}

async function type(wrapper: Wrapper, label: string, value: string) {
  const element = input(wrapper, label)
  element.value = value
  element.dispatchEvent(new Event('input'))
  await flushPromises()
}

async function save(wrapper: Wrapper) {
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

async function choose(wrapper: Wrapper, index: number, file: File) {
  const field = wrapper.findAll('[data-testid="image-input"]')[index]!
  Object.defineProperty(field.element, 'files', { value: [file], configurable: true })
  await field.trigger('change')
  await flushPromises()
}

const png = (name = 'logo.png') => new File([new Uint8Array(2048)], name, { type: 'image/png' })

describe('SettingsPublicView: the route', () => {
  it('shows Configuración under the staff bar with its sections as links and the first one current', async () => {
    const { wrapper } = await open()

    expect(wrapper.get('h1').text()).toBe('Configuración')
    const links = wrapper.findAll('nav[aria-label="Secciones de Configuración"] a')
    expect(links.map((link) => link.text())).toEqual(['Datos públicos', 'Cobro y políticas'])
    expect(links[0]!.attributes('aria-current')).toBe('page')
    expect(links[1]!.attributes('aria-current')).toBeUndefined()
    // The staff bar links to Configuración and marks it current.
    const bar = wrapper
      .findAll('nav[aria-label="Principal"] a')
      .find((a) => a.text() === 'Configuración')
    expect(bar?.attributes('aria-current')).toBe('page')
  })

  it('marks «Configuración» in the bar also inside «Cobro y políticas»', async () => {
    settingsApi()
    const { wrapper } = await mountView(App, `/barberias/${SHOP_A}/configuracion/cobro`)
    await flushPromises()

    const bar = wrapper
      .findAll('nav[aria-label="Principal"] a')
      .find((a) => a.text() === 'Configuración')
    expect(bar?.attributes('aria-current')).toBe('page')
    const section = wrapper.findAll('nav[aria-label="Secciones de Configuración"] a')[1]!
    expect(section.attributes('aria-current')).toBe('page')
  })
})

describe('SettingsPublicView: loading', () => {
  it('shows a skeleton while it loads, then the data, the networks and the public link', async () => {
    let release: () => void = () => {}
    const gate = new Promise<void>((resolve) => (release = resolve))
    const api = settingsApi({
      override: async (request) => {
        if (request.url.includes('/administracion/negocio')) await gate
        return undefined
      },
    })
    const { wrapper } = await mountView(App, `/barberias/${SHOP_A}/configuracion`)
    await flushPromises()
    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true)

    release()
    await vi.waitFor(() => expect(wrapper.find('[aria-busy="true"]').exists()).toBe(false))
    expect(input(wrapper, 'Nombre').value).toBe('Pura Navaja')
    expect(input(wrapper, 'Ciudad').value).toBe('Ciudad Quesada')
    expect(input(wrapper, 'Teléfono').value).toBe('2460-1234')
    expect(input(wrapper, 'Dirección').value).toBe('200 m norte de la iglesia')
    expect(input(wrapper, 'WhatsApp').value).toBe('https://wa.me/50688123456')
    expect(input(wrapper, 'Facebook').value).toBe('')
    expect(wrapper.get('[data-testid="public-link"]').text()).toBe('/b/pura-navaja')
    // The negocio call carries no images: the public cover does, by the link.
    expect(api.calls).toContain('GET /api/v1/enlaces/pura-navaja')
  })

  it('shows the error with «Reintentar» and loads again', async () => {
    let fail = true
    const { wrapper } = await open({
      override: (request) =>
        fail && request.url.includes('/administracion/negocio') ? problem(500) : undefined,
    })
    expect(wrapper.text()).toContain('No pudimos cargar los datos de la barbería')

    fail = false
    await wrapper
      .findAll('button')
      .find((button) => button.text() === 'Reintentar')!
      .trigger('click')
    await flushPromises()
    expect(input(wrapper, 'Nombre').value).toBe('Pura Navaja')
  })
})

describe('SettingsPublicView: saving the data', () => {
  it('sends only what changed, empties an optional field with null and says it was saved', async () => {
    const { wrapper, bodies } = await open()
    await type(wrapper, 'Ciudad', 'San Carlos')
    await type(wrapper, 'Dirección', '')
    await type(wrapper, 'Facebook', 'https://www.facebook.com/puranavaja')
    await save(wrapper)

    expect(bodies).toEqual([
      {
        ciudad: 'San Carlos',
        direccion: null,
        redes: { facebook: 'https://www.facebook.com/puranavaja' },
      },
    ])
    expect(wrapper.text()).toContain('Datos guardados. Sus clientes ya ven los cambios.')
  })

  it('sends nothing when nothing changed', async () => {
    const { wrapper, calls } = await open()
    await save(wrapper)
    expect(calls.some((call) => call.startsWith('PATCH') || call.startsWith('POST'))).toBe(false)
  })

  it('shows the new name in the staff bar and keeps the public link', async () => {
    const { wrapper } = await open()
    await type(wrapper, 'Nombre', 'Navaja Dorada')
    await save(wrapper)

    expect(wrapper.get('[data-testid="active-shop"]').text()).toBe('Navaja Dorada')
    expect(wrapper.get('[data-testid="public-link"]').text()).toBe('/b/pura-navaja')
  })

  it('checks the required fields and the email before the trip', async () => {
    const { wrapper, calls } = await open()
    await type(wrapper, 'Nombre', '')
    await type(wrapper, 'Ciudad', '  ')
    await type(wrapper, 'Correo de contacto', 'contacto@')
    await save(wrapper)

    const text = wrapper.text()
    expect(text).toContain('Escriba el nombre de la barbería.')
    expect(text).toContain('Escriba la ciudad.')
    expect(text).toContain('Escriba un correo válido, como contacto@su-barberia.cr.')
    expect(calls.some((call) => call.startsWith('PATCH'))).toBe(false)
    expect(document.activeElement).toBe(input(wrapper, 'Nombre'))
  })

  it.each([
    [
      'WhatsApp',
      'wa.me/88123',
      'Escriba un enlace de WhatsApp válido, como https://wa.me/50688123456.',
    ],
    [
      'Instagram',
      'https://facebook.com/x',
      'Escriba un enlace de Instagram válido, como https://instagram.com/su_barberia.',
    ],
    [
      'Facebook',
      'http://facebook.com/x',
      'Escriba un enlace de Facebook válido, como https://facebook.com/su.barberia.',
    ],
    [
      'TikTok',
      'https://tiktok.com:8080/@x',
      'Escriba un enlace de TikTok válido, como https://tiktok.com/@su_barberia.',
    ],
  ])('rejects a bad %s link before the trip', async (label, value, message) => {
    const { wrapper, calls } = await open()
    await type(wrapper, label, value)
    await save(wrapper)

    expect(wrapper.text()).toContain(message)
    expect(input(wrapper, label).getAttribute('aria-invalid')).toBe('true')
    expect(calls.some((call) => call.startsWith('PATCH'))).toBe(false)
  })

  it('puts a 422 of the API in its field when the quick check did not catch it', async () => {
    const { wrapper } = await open({
      override: (request) =>
        request.method === 'PATCH'
          ? problem(422, {
              type: 'datos-invalidos',
              detail: 'Los datos enviados no son válidos.',
              errores: { direccion: ['La dirección debe ir en una sola línea.'] },
            })
          : undefined,
    })
    await type(wrapper, 'Dirección', 'Otra dirección')
    await save(wrapper)

    expect(wrapper.text()).toContain('La dirección debe ir en una sola línea.')
    expect(input(wrapper, 'Dirección').getAttribute('aria-invalid')).toBe('true')
    // The form stays editable with what was typed.
    expect(input(wrapper, 'Dirección').value).toBe('Otra dirección')
  })

  it('says «No pudimos guardar los datos» and keeps the form when the save fails', async () => {
    const { wrapper } = await open({
      override: (request) => (request.method === 'PATCH' ? problem(500) : undefined),
    })
    await type(wrapper, 'Ciudad', 'San Carlos')
    await save(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos guardar los datos.')
    expect(wrapper.text()).toContain('No se cambió nada.')
    expect(input(wrapper, 'Ciudad').value).toBe('San Carlos')
  })
})

describe('SettingsPublicView: images', () => {
  it('rejects a banner under 1.200 × 400 px with the message of the design and does not send it', async () => {
    vi.stubGlobal('createImageBitmap', async () => ({ width: 900, height: 300, close() {} }))
    const { wrapper, calls } = await open()
    await choose(
      wrapper,
      1,
      new File([new Uint8Array(140 * 1024)], 'banner-chico.jpg', { type: 'image/jpeg' }),
    )

    expect(wrapper.text()).toContain(
      'El banner mide 900 × 300 px. Debe medir al menos 1.200 × 400 px.',
    )
    expect(wrapper.text()).toContain('JPG · 140 KB · 900 × 300 px')
    await save(wrapper)
    expect(calls.some((call) => call.includes('/imagenes/banner'))).toBe(false)
  })

  it('shows the size of a banner that fits and uploads it as multipart after the data', async () => {
    vi.stubGlobal('createImageBitmap', async () => ({ width: 1600, height: 533, close() {} }))
    const { wrapper, calls, fetchMock } = await open()
    await type(wrapper, 'Ciudad', 'San Carlos')
    await choose(
      wrapper,
      1,
      new File([new Uint8Array(2048)], 'banner.webp', { type: 'image/webp' }),
    )
    expect(wrapper.text()).toContain('WebP · 2 KB · 1.600 × 533 px')
    await save(wrapper)

    const order = calls.filter((call) => call.startsWith('PATCH') || call.startsWith('POST'))
    expect(order).toEqual([
      `PATCH /api/v1/barberias/${SHOP_A}/administracion/negocio`,
      `POST /api/v1/barberias/${SHOP_A}/administracion/imagenes/banner`,
    ])
    const upload = fetchMock.mock.calls
      .map(([request]) => request as Request)
      .find((request) => request.url.endsWith('/imagenes/banner'))!
    expect(upload.headers.get('Content-Type')).toContain('multipart/form-data')
    expect(wrapper.text()).toContain('Datos guardados.')
  })

  it('uploads the logo and shows what the API answers as the current image', async () => {
    const { wrapper, calls } = await open()
    await choose(wrapper, 0, png())
    await save(wrapper)

    expect(calls).toContain(`POST /api/v1/barberias/${SHOP_A}/administracion/imagenes/logo`)
    expect(wrapper.find('img[src="/api/v1/archivos/barbershops/x/logo/nuevo.png"]').exists()).toBe(
      true,
    )
  })

  it('shows the API error of an image under its field', async () => {
    const { wrapper } = await open({
      override: (request) =>
        request.url.endsWith('/imagenes/logo')
          ? problem(422, { errores: { logo: ['El archivo no es una imagen válida.'] } })
          : undefined,
    })
    await choose(wrapper, 0, png())
    await save(wrapper)

    expect(wrapper.text()).toContain('El archivo no es una imagen válida.')
  })

  it('has no «Quitar» button: the API cannot remove an image', async () => {
    const { wrapper } = await open({
      cover: { logo: '/api/v1/archivos/l.png', banner: '/api/v1/archivos/b.png' },
    })
    expect(wrapper.text()).not.toContain('Quitar')
    expect(wrapper.text()).toContain('Cambiar imagen')
  })
})

describe('SettingsPublicView: partial save', () => {
  it('says what was saved and what was not, keeps the rest in the form and sends only the rest on a new try', async () => {
    vi.stubGlobal('createImageBitmap', async () => ({ width: 1600, height: 533, close() {} }))
    let failBanner = true
    const { wrapper, calls } = await open({
      override: (request) => {
        if (failBanner && request.url.endsWith('/imagenes/banner')) return problem(500)
        return undefined
      },
    })
    await type(wrapper, 'Ciudad', 'San Carlos')
    await choose(
      wrapper,
      1,
      new File([new Uint8Array(2048)], 'banner.webp', { type: 'image/webp' }),
    )
    await save(wrapper)

    const alert = wrapper.get('[role="alert"]').text()
    expect(alert).toContain('Se guardó los datos del negocio.')
    expect(alert).toContain('Falta guardar el banner.')
    // The data is saved: the form keeps showing it and the banner is still chosen.
    expect(input(wrapper, 'Ciudad').value).toBe('San Carlos')
    expect(wrapper.text()).toContain('banner.webp')

    calls.length = 0
    failBanner = false
    await save(wrapper)
    expect(calls.filter((call) => call.startsWith('PATCH'))).toEqual([])
    expect(calls).toContain(`POST /api/v1/barberias/${SHOP_A}/administracion/imagenes/banner`)
    expect(wrapper.text()).toContain('Datos guardados.')
  })
})

describe('SettingsPublicView: preview and directory', () => {
  it('previews the header with the monogram and compact header when there is no logo or banner', async () => {
    const { wrapper } = await open()
    expect(wrapper.text()).toContain('Así lo ven sus clientes')
    expect(wrapper.text()).toContain(
      'Sin banner: cabecera compacta · sin logo se usa el monograma con sus iniciales.',
    )
    expect(wrapper.find('[aria-label="Monograma de Pura Navaja"]').exists()).toBe(true)
  })

  it('previews the real logo and banner and follows what is typed', async () => {
    const { wrapper } = await open({
      cover: { logo: '/api/v1/archivos/l.png', banner: '/api/v1/archivos/b.png' },
    })
    expect(wrapper.text()).toContain('Con banner.')
    expect(wrapper.find('img[alt="Logo de Pura Navaja"]').attributes('src')).toBe(
      '/api/v1/archivos/l.png',
    )
    await type(wrapper, 'Nombre', 'Navaja Dorada')
    expect(wrapper.find('img[alt="Logo de Navaja Dorada"]').exists()).toBe(true)
  })

  it('sends only the directory switch and nothing else when it is the only change', async () => {
    const { wrapper, bodies, calls } = await open()
    const toggle = wrapper.get('[role="switch"]')
    expect(toggle.attributes('aria-checked')).toBe('false')
    await toggle.trigger('click')
    await save(wrapper)

    expect(bodies).toEqual([{ aparece_en_directorio: true }])
    expect(calls.filter((call) => call.startsWith('PATCH'))).toEqual([
      `PATCH /api/v1/barberias/${SHOP_A}/administracion/funciones`,
    ])
  })
})
