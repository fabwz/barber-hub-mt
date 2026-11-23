import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import App from '@/App.vue'
import { useAccountStore } from '@/stores/account'
import { useSessionStore } from '@/stores/session'
import LoginView from '@/views/LoginView.vue'
import {
  ACCOUNT,
  accountHandler,
  signIn,
  temporaryPasswordProblem,
  type AccountRoutes,
} from '../helpers/account'
import { jsonResponse, pathOf, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { OWNER_SHOP, STAFF_SHOP, TOKEN_BODY } from '../helpers/staff'

const TEMPORARY = 'Temporal#2026'
const NEW = 'Nueva#Clave9'
const TEMPORARY_ACCOUNT = { ...ACCOUNT, contrasena_temporal: true }

beforeEach(() => {
  sessionStorage.clear()
  signIn()
})
afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

/** A person with the temporary password who opens the app: the router brings them to the change. */
async function open(path = '/negocios', routes: AccountRoutes = {}) {
  const handler = accountHandler({ account: TEMPORARY_ACCOUNT, ...routes })
  const mounted = await mountView(App, path, [], { knownAccount: false })
  await flushPromises()
  return { ...mounted, ...handler }
}

type Mounted = Awaited<ReturnType<typeof open>>['wrapper']

const field = (wrapper: Mounted, name: string) => wrapper.get(`input[name="${name}"]`)

async function change(wrapper: Mounted, current = TEMPORARY, next = NEW) {
  await field(wrapper, 'current-password').setValue(current)
  await field(wrapper, 'new-password').setValue(next)
  await field(wrapper, 'confirm-password').setValue(next)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

describe('the mandatory change of a temporary password', () => {
  it('is where the router leaves the person, with the destination they asked for', async () => {
    const { router } = await open('/negocios')

    expect(router.currentRoute.value.name).toBe('password-change')
    expect(router.currentRoute.value.query.redirect).toBe('/negocios')
  })

  it('has no navigation: no navbar, no account menu, only «Cerrar sesión»', async () => {
    const { wrapper } = await open()

    expect(wrapper.find('nav').exists()).toBe(false)
    expect(wrapper.findAll('header a')).toHaveLength(0)
    expect(
      wrapper.findAll('button').filter((button) => button.text() === 'Cerrar sesión'),
    ).toHaveLength(1)
    expect(wrapper.get('header img').attributes('alt')).toBe('BarberHub')
  })

  it('explains why, and asks for the temporary password, the new one and its confirmation', async () => {
    const { wrapper } = await open()

    expect(wrapper.findAll('h1').map((heading) => heading.text())).toEqual([
      'Cambie su contraseña temporal',
    ])
    expect(wrapper.text()).toContain('Su cuenta se creó con una contraseña temporal.')
    expect(wrapper.findAll('label').map((label) => label.text())).toEqual([
      'Contraseña temporal',
      'Contraseña nueva',
      'Confirmar contraseña nueva',
    ])
    expect(wrapper.findAll('li[data-state]')).toHaveLength(5)
  })

  it('does not let the person go anywhere else while the password is temporary', async () => {
    const { router } = await open()

    for (const path of ['/barberias', '/cuenta/datos', '/negocios', '/']) {
      await router.push(path)
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('password-change')
    }
  })

  it('says the temporary password is wrong on its field', async () => {
    const { wrapper } = await open('/negocios', {
      password: () =>
        problem(422, { errores: { contrasena_actual: ['La contraseña actual no es correcta.'] } }),
    })

    await change(wrapper, 'Mala#Clave1')

    const current = field(wrapper, 'current-password')
    expect(wrapper.get(`#${current.attributes('aria-describedby')}`).text()).toBe(
      'La contraseña actual es incorrecta.',
    )
    expect(document.activeElement).toBe(current.element)
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('shows «Guardando…» while it saves', async () => {
    const { wrapper } = await open()
    let release: (response: Response) => void = () => {}
    vi.stubGlobal(
      'fetch',
      vi.fn(async (request: Request) =>
        pathOf(request) === 'PUT /api/v1/cuenta/contrasena'
          ? new Promise<Response>((resolve) => (release = resolve))
          : jsonResponse(404, {}),
      ),
    )
    await field(wrapper, 'current-password').setValue(TEMPORARY)
    await field(wrapper, 'new-password').setValue(NEW)
    await field(wrapper, 'confirm-password').setValue(NEW)

    await wrapper.get('form').trigger('submit')
    const button = wrapper.get('button[type="submit"]')
    await vi.waitFor(() => expect(button.attributes('disabled')).toBeDefined())

    expect(button.text()).toBe('Guardando…')
    expect(wrapper.get('form').attributes('aria-busy')).toBe('true')
    for (const input of wrapper.findAll('input')) expect(input.attributes('disabled')).toBeDefined()

    release(new Response(null, { status: 204 }))
    await flushPromises()
  })

  it('after the change says so and offers «Continuar», which frees the account', async () => {
    const { wrapper, calls } = await open('/cuenta/seguridad')

    await change(wrapper)

    expect(calls.at(-1)).toEqual({
      route: 'PUT /api/v1/cuenta/contrasena',
      body: { contrasena_actual: TEMPORARY, contrasena_nueva: NEW },
    })
    expect(wrapper.get('[role="status"]').text()).toBe(
      'Su contraseña se actualizó. Ya puede usar BarberHub.',
    )
    expect(wrapper.find('form').exists()).toBe(false)
    expect(
      wrapper.findAll('button').filter((button) => button.text() === 'Continuar'),
    ).toHaveLength(1)
    expect(useAccountStore().temporaryPassword).toBe(false)
    // The API keeps this session open.
    expect(useSessionStore().isAuthenticated).toBe(true)
  })

  it('«Continuar» goes to the shop selector for staff with several shops', async () => {
    const { wrapper, router } = await open('/', {
      other: (request) =>
        pathOf(request) === 'GET /api/v1/mis-barberias'
          ? jsonResponse(200, { data: [OWNER_SHOP, STAFF_SHOP] })
          : jsonResponse(404, {}),
    })

    await change(wrapper)
    await wrapper.get('button.w-full').trigger('click')
    await flushPromises()

    await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/negocios'))
  })

  it('«Continuar» goes to the page the person was on, when there was one', async () => {
    const { wrapper, router } = await open('/cuenta/seguridad')

    await change(wrapper)
    await wrapper.get('button.w-full').trigger('click')
    await flushPromises()

    await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/cuenta/seguridad'))
  })

  it('«Continuar» goes to «/» for a customer, who has no shops as staff', async () => {
    const { wrapper, router } = await open('/', {
      other: (request) =>
        pathOf(request) === 'GET /api/v1/mis-barberias'
          ? jsonResponse(200, { data: [] })
          : jsonResponse(404, {}),
    })

    await change(wrapper)
    await wrapper.get('button.w-full').trigger('click')
    await flushPromises()

    await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/'))
  })

  it('«Cerrar sesión» revokes the token, forgets the account and goes to the start', async () => {
    const { wrapper, router, calls } = await open('/', {
      other: (request) =>
        pathOf(request) === 'DELETE /api/v1/tokens/actual'
          ? new Response(null, { status: 204 })
          : jsonResponse(404, {}),
    })

    await wrapper
      .findAll('button')
      .find((button) => button.text() === 'Cerrar sesión')!
      .trigger('click')
    await flushPromises()

    expect(calls.map((call) => call.route)).toContain('DELETE /api/v1/tokens/actual')
    expect(useSessionStore().isAuthenticated).toBe(false)
    expect(useAccountStore().temporaryPassword).toBeNull()
    expect(router.currentRoute.value.path).toBe('/')
  })

  it('a 403 «contrasena-temporal» from any screen brings the person here', async () => {
    sessionStorage.clear()
    signIn()
    accountHandler({
      other: (request) =>
        pathOf(request) === 'GET /api/v1/mis-barberias'
          ? temporaryPasswordProblem()
          : jsonResponse(404, {}),
    })
    const { router } = await mountView(App, '/negocios')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('password-change')
    expect(useAccountStore().temporaryPassword).toBe(true)
  })
})

describe('signing in with a temporary password', () => {
  async function signInWith(account: typeof ACCOUNT, path = '/ingresar') {
    sessionStorage.clear()
    const handler = accountHandler({
      account,
      other: (request) => {
        const route = pathOf(request)
        if (route === 'POST /api/v1/tokens') return jsonResponse(201, TOKEN_BODY)
        if (route === 'GET /api/v1/mis-barberias') {
          return jsonResponse(200, { data: [OWNER_SHOP, STAFF_SHOP] })
        }
        return jsonResponse(404, {})
      },
    })
    const mounted = await mountView(LoginView, path)
    await mounted.wrapper.get('input[type="email"]').setValue('kevin@barberhub.test')
    await mounted.wrapper.get('input[type="password"]').setValue(TEMPORARY)
    await mounted.wrapper.get('form').trigger('submit')
    await flushPromises()
    return { ...mounted, ...handler }
  }

  it('goes straight to the mandatory change, without asking for the shops', async () => {
    const { router, calls } = await signInWith(TEMPORARY_ACCOUNT)

    expect(router.currentRoute.value.name).toBe('password-change')
    expect(calls.map((call) => call.route)).toEqual(['POST /api/v1/tokens', 'GET /api/v1/cuenta'])
  })

  it('keeps the page the person was going to', async () => {
    const { router } = await signInWith(TEMPORARY_ACCOUNT, '/ingresar?redirect=/barberias')

    expect(router.currentRoute.value.name).toBe('password-change')
    expect(router.currentRoute.value.query.redirect).toBe('/barberias')
  })

  it('without a temporary password goes where it always did', async () => {
    const { router } = await signInWith(ACCOUNT)

    expect(router.currentRoute.value.path).toBe('/negocios')
  })
})

describe('the mandatory change: a new password different from the temporary one', () => {
  const SAME = 'Elija una contraseña distinta de la actual.'

  it('checks it in the client: the message on «Contraseña nueva» and no call to the API', async () => {
    const { wrapper, calls } = await open()

    await change(wrapper, TEMPORARY, TEMPORARY)

    const next = field(wrapper, 'new-password')
    expect(next.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get(`#${next.attributes('aria-describedby')!.split(' ').at(-1)}`).text()).toBe(
      SAME,
    )
    expect(document.activeElement).toBe(next.element)
    expect(calls.some((call) => call.route === 'PUT /api/v1/cuenta/contrasena')).toBe(false)
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('shows the error of the API on «Contraseña nueva» and the account stays locked', async () => {
    const { wrapper } = await open('/negocios', {
      password: () => problem(422, { errores: { contrasena_nueva: [SAME] } }),
    })

    await change(wrapper)

    const next = field(wrapper, 'new-password')
    expect(next.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get(`#${next.attributes('aria-describedby')!.split(' ').at(-1)}`).text()).toBe(
      SAME,
    )
    expect(wrapper.find('form').exists()).toBe(true)
    expect(useAccountStore().temporaryPassword).toBe(true)
  })
})
