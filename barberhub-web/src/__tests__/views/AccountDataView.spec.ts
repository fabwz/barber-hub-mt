import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import App from '@/App.vue'
import { useSessionStore } from '@/stores/session'
import { ACCOUNT, accountHandler, signIn, type AccountRoutes } from '../helpers/account'
import { jsonResponse, mockFetch, pathOf, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'

beforeEach(() => {
  sessionStorage.clear()
  signIn()
})
afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

async function open(routes: AccountRoutes = {}) {
  const handler = accountHandler(routes)
  const mounted = await mountView(App, '/cuenta/datos', [], { knownAccount: false })
  await flushPromises()
  return { ...mounted, ...handler }
}

type Mounted = Awaited<ReturnType<typeof open>>['wrapper']

const nameInput = (wrapper: Mounted) => wrapper.get('input[name="name"]')
const phoneInput = (wrapper: Mounted) => wrapper.get('input[name="phone"]')
const emailInput = (wrapper: Mounted) => wrapper.get('input[name="email"]')
const submitButton = (wrapper: Mounted) => wrapper.get('button[type="submit"]')

async function submit(wrapper: Mounted) {
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

describe('«Mi cuenta · Datos»: loaded', () => {
  it('shows the data of the account in labelled fields', async () => {
    const { wrapper } = await open()

    expect(wrapper.get('h2').text()).toBe('Datos personales')
    expect(wrapper.get('label[for]').text()).toBe('Nombre completo')
    expect((nameInput(wrapper).element as HTMLInputElement).value).toBe('Laura Brenes')
    // The API keeps the digits; the field shows them as 1234-5678.
    expect((phoneInput(wrapper).element as HTMLInputElement).value).toBe('8888-1234')
    expect((emailInput(wrapper).element as HTMLInputElement).value).toBe('laura.brenes@correo.cr')
    expect(wrapper.findAll('label').map((label) => label.text())).toEqual([
      'Nombre completo',
      'Teléfono',
      'Correo electrónico',
    ])
    expect(submitButton(wrapper).text()).toBe('Guardar cambios')
  })

  it('filters what is typed: no digits in the name, only 8 digits in the phone', async () => {
    const { wrapper } = await open()

    await nameInput(wrapper).setValue('Ana 123 Mora')
    await phoneInput(wrapper).setValue('99998888777')

    expect((nameInput(wrapper).element as HTMLInputElement).value).toBe('Ana  Mora')
    expect((phoneInput(wrapper).element as HTMLInputElement).value).toBe('9999-8888')
  })

  it('has the fields without error and no notice before saving', async () => {
    const { wrapper } = await open()

    expect(wrapper.find('[aria-invalid]').exists()).toBe(false)
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })
})

describe('«Mi cuenta · Datos»: states of the read', () => {
  /** The router's read fails, so the screen reads again: that second read is the one the test controls. */
  async function openAfterAFailedRead(second: () => Promise<Response> | Response) {
    let reads = 0
    mockFetch((request) => {
      if (pathOf(request) !== 'GET /api/v1/cuenta') return jsonResponse(404, {})
      reads += 1
      return reads === 1 ? problem(500, {}) : second()
    })
    const mounted = await mountView(App, '/cuenta/datos', [], { knownAccount: false })
    return mounted
  }

  it('shows a skeleton with aria-busy while it loads', async () => {
    let release: (response: Response) => void = () => {}
    const { wrapper } = await openAfterAFailedRead(
      () => new Promise<Response>((resolve) => (release = resolve)),
    )
    await flushPromises()

    expect(wrapper.get('section').attributes('aria-busy')).toBe('true')
    expect(wrapper.text()).toContain('Cargando sus datos')
    expect(wrapper.find('form').exists()).toBe(false)

    release(jsonResponse(200, { data: ACCOUNT }))
    await flushPromises()

    expect(wrapper.find('form').exists()).toBe(true)
    expect(wrapper.find('section[aria-busy="true"]').exists()).toBe(false)
  })

  it('shows the error with «Reintentar», and the retry brings the form', async () => {
    let reads = 0
    mockFetch((request) => {
      if (pathOf(request) !== 'GET /api/v1/cuenta') return jsonResponse(404, {})
      reads += 1
      return reads <= 2 ? problem(500, {}) : jsonResponse(200, { data: ACCOUNT })
    })
    const { wrapper } = await mountView(App, '/cuenta/datos', [], { knownAccount: false })
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar sus datos')
    expect(wrapper.get('[role="alert"]').text()).toContain('Revise su conexión')
    expect(wrapper.find('form').exists()).toBe(false)

    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect((nameInput(wrapper).element as HTMLInputElement).value).toBe('Laura Brenes')
  })
})

describe('«Mi cuenta · Datos»: saving', () => {
  it('sends the corrected data (the phone as digits) and confirms with a status', async () => {
    const { wrapper, calls } = await open()
    await nameInput(wrapper).setValue('Laura B. Mora')
    await phoneInput(wrapper).setValue('8999-0000')
    await emailInput(wrapper).setValue('laura.mora@correo.cr')

    await submit(wrapper)

    expect(calls.at(-1)).toEqual({
      route: 'PUT /api/v1/cuenta',
      body: { nombre: 'Laura B. Mora', telefono: '89990000', correo: 'laura.mora@correo.cr' },
    })
    expect(wrapper.get('[role="status"]').text()).toBe('Sus datos se guardaron.')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    // The email is the login: the menu shows the new one and the session keeps it.
    expect(useSessionStore().person?.email).toBe('laura.mora@correo.cr')
  })

  it('while it saves, the fields and the button are blocked, with «Guardando…» and aria-busy', async () => {
    let release: (response: Response) => void = () => {}
    const { wrapper } = await open({
      put: () => {
        throw new Error('replaced below')
      },
    })
    mockFetch((request) =>
      pathOf(request) === 'PUT /api/v1/cuenta'
        ? new Promise<Response>((resolve) => (release = resolve))
        : jsonResponse(404, {}),
    )

    await wrapper.get('form').trigger('submit')
    await vi.waitFor(() => expect(submitButton(wrapper).attributes('disabled')).toBeDefined())

    expect(submitButton(wrapper).text()).toBe('Guardando…')
    expect(submitButton(wrapper).attributes('aria-busy')).toBe('true')
    expect(wrapper.get('section').attributes('aria-busy')).toBe('true')
    for (const input of wrapper.findAll('input')) {
      expect(input.attributes('disabled')).toBeDefined()
    }

    release(jsonResponse(200, { data: ACCOUNT }))
    await flushPromises()

    expect(submitButton(wrapper).text()).toBe('Guardar cambios')
    expect(wrapper.get('[role="status"]').text()).toBe('Sus datos se guardaron.')
  })

  it('an email that another account uses is refused on its field, and what was typed stays', async () => {
    const { wrapper } = await open({
      put: () =>
        problem(422, {
          type: 'http://localhost/problemas/datos-invalidos',
          errores: { correo: ['Ya existe una cuenta con ese correo.'] },
        }),
    })
    await emailInput(wrapper).setValue('luis@correo.cr')
    await nameInput(wrapper).setValue('Laura Nueva')

    await submit(wrapper)

    const email = emailInput(wrapper)
    expect(email.attributes('aria-invalid')).toBe('true')
    const message = wrapper.get(`#${email.attributes('aria-describedby')}`)
    expect(message.text()).toBe('Este correo ya está registrado. Use otro correo.')
    // Text and icon: the error does not depend on the color alone.
    expect(message.find('svg').exists()).toBe(true)
    expect(document.activeElement).toBe(email.element)
    expect((email.element as HTMLInputElement).value).toBe('luis@correo.cr')
    expect((nameInput(wrapper).element as HTMLInputElement).value).toBe('Laura Nueva')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    expect(submitButton(wrapper).attributes('disabled')).toBeUndefined()
    // The session still shows the email it had.
    expect(useSessionStore().person?.email).toBe('laura.brenes@correo.cr')
  })

  it('writing in the field again clears its error and the success notice', async () => {
    const { wrapper } = await open()
    await submit(wrapper)
    expect(wrapper.find('[role="status"]').exists()).toBe(true)

    await nameInput(wrapper).setValue('Otro Nombre')

    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('checks the fields before calling the API', async () => {
    const { wrapper, calls } = await open()
    await nameInput(wrapper).setValue('')
    await phoneInput(wrapper).setValue('123')
    await emailInput(wrapper).setValue('sin-arroba')

    await submit(wrapper)

    expect(wrapper.text()).toContain('Escriba su nombre completo.')
    expect(wrapper.text()).toContain('El teléfono debe tener 8 dígitos.')
    expect(wrapper.text()).toContain('Al correo le falta el @.')
    expect(wrapper.findAll('[aria-invalid="true"]')).toHaveLength(3)
    expect(document.activeElement).toBe(nameInput(wrapper).element)
    expect(calls.some((call) => call.route === 'PUT /api/v1/cuenta')).toBe(false)
  })

  it('another failure shows a banner and keeps what was typed', async () => {
    const { wrapper } = await open({
      put: () => problem(500, { title: 'Error', detail: 'No se pudo completar la operación.' }),
    })
    await nameInput(wrapper).setValue('Laura Nueva')

    await submit(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos guardar sus datos.')
    expect((nameInput(wrapper).element as HTMLInputElement).value).toBe('Laura Nueva')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('works for a collaborator whose account has no phone yet: it must be given', async () => {
    const { wrapper, calls } = await open({
      account: { ...ACCOUNT, telefono: null as unknown as string },
    })
    expect((phoneInput(wrapper).element as HTMLInputElement).value).toBe('')

    await submit(wrapper)
    expect(wrapper.text()).toContain('Escriba su teléfono.')
    expect(calls.some((call) => call.route === 'PUT /api/v1/cuenta')).toBe(false)

    await phoneInput(wrapper).setValue('7777-1111')
    await submit(wrapper)
    expect(calls.at(-1)?.body).toMatchObject({ telefono: '77771111' })
  })
})
