import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import App from '@/App.vue'
import { useSessionStore } from '@/stores/session'
import { accountHandler, signIn, type AccountRoutes } from '../helpers/account'
import { jsonResponse, mockFetch, pathOf, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'

const CURRENT = 'Clave#segura1'
const NEW = 'Nueva#Clave9'

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
  const mounted = await mountView(App, '/cuenta/seguridad')
  await flushPromises()
  return { ...mounted, ...handler }
}

type Mounted = Awaited<ReturnType<typeof open>>['wrapper']

const field = (wrapper: Mounted, name: string) => wrapper.get(`input[name="${name}"]`)
const states = (wrapper: Mounted) =>
  wrapper.findAll('li[data-state]').map((item) => item.attributes('data-state'))

async function fill(wrapper: Mounted, current: string, next: string, confirmation = next) {
  await field(wrapper, 'current-password').setValue(current)
  await field(wrapper, 'new-password').setValue(next)
  await field(wrapper, 'confirm-password').setValue(confirmation)
}

async function submit(wrapper: Mounted) {
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

describe('«Mi cuenta · Seguridad»', () => {
  it('has the three password fields with their labels and the right autocomplete', async () => {
    const { wrapper } = await open()

    expect(wrapper.get('h2').text()).toBe('Cambiar contraseña')
    expect(wrapper.findAll('label').map((label) => label.text())).toEqual([
      'Contraseña actual',
      'Contraseña nueva',
      'Confirmar contraseña nueva',
    ])
    expect(field(wrapper, 'current-password').attributes('autocomplete')).toBe('current-password')
    expect(field(wrapper, 'new-password').attributes('autocomplete')).toBe('new-password')
    for (const input of wrapper.findAll('input')) expect(input.attributes('type')).toBe('password')
    expect(wrapper.get('button[type="submit"]').text()).toBe('Cambiar contraseña')
  })

  it('has an eye button for each field', async () => {
    const { wrapper } = await open()

    const eyes = wrapper.findAll('button[aria-label="Mostrar contraseña"]')
    expect(eyes).toHaveLength(3)
    await eyes[1]!.trigger('click')
    expect(field(wrapper, 'new-password').attributes('type')).toBe('text')
    expect(eyes[1]!.attributes('aria-pressed')).toBe('true')
    expect(field(wrapper, 'current-password').attributes('type')).toBe('password')
  })
})

describe('«Mi cuenta · Seguridad»: the requirements of SE-07', () => {
  it('lists the five, all pending, with an icon and a word for screen readers', async () => {
    const { wrapper } = await open()

    const items = wrapper.findAll('li[data-state]')
    expect(items.map((item) => item.text())).toEqual([
      'Pendiente: Mínimo 8 caracteres',
      'Pendiente: Una letra mayúscula',
      'Pendiente: Una letra minúscula',
      'Pendiente: Un número',
      'Pendiente: Un signo especial',
    ])
    for (const item of items) {
      expect(item.find('svg').exists()).toBe(true)
      expect(item.find('.sr-only').exists()).toBe(true)
    }
    expect(wrapper.text()).toContain('La contraseña nueva debe tener')
  })

  it('marks each requirement as «Cumplido» as it is met', async () => {
    const { wrapper } = await open()

    await field(wrapper, 'new-password').setValue('abcdefgh')
    expect(states(wrapper)).toEqual(['met', 'pending', 'met', 'pending', 'pending'])

    await field(wrapper, 'new-password').setValue('Abcdefg1!')
    expect(states(wrapper)).toEqual(['met', 'met', 'met', 'met', 'met'])
    expect(wrapper.findAll('li[data-state] .sr-only').map((word) => word.text())).toEqual(
      Array(5).fill('Cumplido:'),
    )
  })

  it('after a failed attempt what is missing says «Falta»', async () => {
    const { wrapper, calls } = await open()
    await fill(wrapper, CURRENT, 'Abcdefg1')

    await submit(wrapper)

    expect(states(wrapper)).toEqual(['met', 'met', 'met', 'met', 'missing'])
    expect(wrapper.findAll('li[data-state="missing"]').map((item) => item.text())).toEqual([
      'Falta: Un signo especial',
    ])
    expect(wrapper.text()).toContain('La contraseña nueva no cumple los requisitos.')
    expect(field(wrapper, 'new-password').attributes('aria-invalid')).toBe('true')
    expect(document.activeElement).toBe(field(wrapper, 'new-password').element)
    expect(calls.some((call) => call.route === 'PUT /api/v1/cuenta/contrasena')).toBe(false)
  })

  it('the new password field is described by the list', async () => {
    const { wrapper } = await open()

    const id = field(wrapper, 'new-password').attributes('aria-describedby')
    expect(wrapper.get(`#${id}`).text()).toContain('La contraseña nueva debe tener')
  })
})

describe('«Mi cuenta · Seguridad»: changing it', () => {
  it('the confirmation is checked only in the client', async () => {
    const { wrapper, calls } = await open()
    await fill(wrapper, CURRENT, NEW, 'Otra#Clave9')

    await submit(wrapper)

    const confirmation = field(wrapper, 'confirm-password')
    expect(confirmation.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get(`#${confirmation.attributes('aria-describedby')}`).text()).toBe(
      'Las contraseñas no coinciden.',
    )
    expect(document.activeElement).toBe(confirmation.element)
    expect(calls.some((call) => call.route === 'PUT /api/v1/cuenta/contrasena')).toBe(false)
  })

  it('asks for the current password', async () => {
    const { wrapper } = await open()
    await fill(wrapper, '', NEW)

    await submit(wrapper)

    expect(wrapper.text()).toContain('Escriba su contraseña actual.')
    expect(document.activeElement).toBe(field(wrapper, 'current-password').element)
  })

  it('a wrong current password is told on its field, and nothing typed is lost', async () => {
    const { wrapper } = await open({
      password: () =>
        problem(422, {
          type: 'http://localhost/problemas/datos-invalidos',
          errores: { contrasena_actual: ['La contraseña actual no es correcta.'] },
        }),
    })
    await fill(wrapper, 'Mala#Clave1', NEW)

    await submit(wrapper)

    const current = field(wrapper, 'current-password')
    expect(current.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get(`#${current.attributes('aria-describedby')}`).text()).toBe(
      'La contraseña actual es incorrecta.',
    )
    expect(document.activeElement).toBe(current.element)
    expect((field(wrapper, 'new-password').element as HTMLInputElement).value).toBe(NEW)
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('sends both passwords with the names of the contract, clears the fields and confirms', async () => {
    const { wrapper, calls } = await open()
    await fill(wrapper, CURRENT, NEW)

    await submit(wrapper)

    expect(calls.at(-1)).toEqual({
      route: 'PUT /api/v1/cuenta/contrasena',
      body: { contrasena_actual: CURRENT, contrasena_nueva: NEW },
    })
    expect(wrapper.get('[role="status"]').text()).toBe('Su contraseña se actualizó.')
    for (const input of wrapper.findAll('input')) {
      expect((input.element as HTMLInputElement).value).toBe('')
    }
    expect(states(wrapper)).toEqual(Array(5).fill('pending'))
  })

  it('keeps the session open: the API keeps this token and closes the others', async () => {
    const { wrapper, router } = await open()
    await fill(wrapper, CURRENT, NEW)

    await submit(wrapper)

    expect(useSessionStore().isAuthenticated).toBe(true)
    expect(sessionStorage.getItem('barberhub.session')).toContain('tok')
    expect(router.currentRoute.value.path).toBe('/cuenta/seguridad')
  })

  it('while it saves, the fields and the button are blocked, with «Guardando…» and aria-busy', async () => {
    const { wrapper } = await open()
    let release: (response: Response) => void = () => {}
    mockFetch((request) =>
      pathOf(request) === 'PUT /api/v1/cuenta/contrasena'
        ? new Promise<Response>((resolve) => (release = resolve))
        : jsonResponse(404, {}),
    )
    await fill(wrapper, CURRENT, NEW)

    await wrapper.get('form').trigger('submit')
    const button = wrapper.get('button[type="submit"]')
    await vi.waitFor(() => expect(button.attributes('disabled')).toBeDefined())

    expect(button.text()).toBe('Guardando…')
    expect(button.attributes('aria-busy')).toBe('true')
    expect(wrapper.get('form').attributes('aria-busy')).toBe('true')
    for (const input of wrapper.findAll('input')) {
      expect(input.attributes('disabled')).toBeDefined()
    }

    release(new Response(null, { status: 204 }))
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toBe('Su contraseña se actualizó.')
  })

  it('another failure shows a banner and keeps what was typed', async () => {
    const { wrapper } = await open({
      password: () =>
        problem(500, { title: 'Error', detail: 'No se pudo completar la operación.' }),
    })
    await fill(wrapper, CURRENT, NEW)

    await submit(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cambiar la contraseña.')
    expect((field(wrapper, 'new-password').element as HTMLInputElement).value).toBe(NEW)
  })
})

describe('«Mi cuenta · Seguridad»: a new password different from the current one', () => {
  const SAME = 'Elija una contraseña distinta de la actual.'

  it('checks it in the client: the same message on «Contraseña nueva» and no call to the API', async () => {
    const { wrapper, calls } = await open()
    await fill(wrapper, CURRENT, CURRENT)

    await submit(wrapper)

    const next = field(wrapper, 'new-password')
    expect(next.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get(`#${next.attributes('aria-describedby')!.split(' ').at(-1)}`).text()).toBe(
      SAME,
    )
    expect(document.activeElement).toBe(next.element)
    expect(calls.some((call) => call.route === 'PUT /api/v1/cuenta/contrasena')).toBe(false)
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('shows the error of the API on «Contraseña nueva» (422 in contrasena_nueva)', async () => {
    const { wrapper } = await open({
      password: () => problem(422, { errores: { contrasena_nueva: [SAME] } }),
    })
    await fill(wrapper, CURRENT, NEW)

    await submit(wrapper)

    const next = field(wrapper, 'new-password')
    expect(next.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get(`#${next.attributes('aria-describedby')!.split(' ').at(-1)}`).text()).toBe(
      SAME,
    )
    expect(document.activeElement).toBe(next.element)
    expect(field(wrapper, 'current-password').attributes('aria-invalid')).toBeUndefined()
  })
})
