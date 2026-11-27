import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import AcceptInvitationView from '@/views/AcceptInvitationView.vue'
import { signIn } from '../helpers/account'
import { jsonResponse, mockFetch, problem } from '../helpers/http'
import { INVALID_LINK, LINK_TOKEN, invitationLinkApi } from '../helpers/invitations'
import { mountView } from '../helpers/mountView'

const PASSWORD = 'Clave#segura1'

beforeEach(() => sessionStorage.clear())
afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

async function open(query = `?token=${LINK_TOKEN}`) {
  const mounted = await mountView(AcceptInvitationView, `/aceptar-invitacion${query}`)
  await flushPromises()
  return mounted
}

type Mounted = Awaited<ReturnType<typeof open>>['wrapper']

const field = (wrapper: Mounted, name: string) => wrapper.get(`input[name="${name}"]`)

async function fillAndSubmit(wrapper: Mounted, values: { name?: string; password?: string } = {}) {
  const { name = 'Lucía Mora', password = PASSWORD } = values
  await field(wrapper, 'name').setValue(name)
  await field(wrapper, 'new-password').setValue(password)
  await field(wrapper, 'confirm-password').setValue(password)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

describe('AcceptInvitationView (RF-06): reading the link', () => {
  it('reads what the link invites to and takes the token out of the address', async () => {
    const api = invitationLinkApi()
    const { wrapper, router } = await open()

    expect(api.calls).toEqual(['POST /api/v1/invitaciones/consulta'])
    expect(api.bodies[0]!.body).toEqual({ token: LINK_TOKEN })
    expect(wrapper.get('h1').text()).toBe('Pura Navaja le invita a su equipo')
    expect(wrapper.text()).toContain('Cree su cuenta con lucia@correo.cr')
    expect(router.currentRoute.value.name).toBe('accept-invitation')
    expect(router.currentRoute.value.fullPath).not.toContain(LINK_TOKEN)
  })

  it('shows the skeleton while it reads the link', async () => {
    mockFetch(() => new Promise<Response>(() => undefined))
    const { wrapper } = await mountView(
      AcceptInvitationView,
      `/aceptar-invitacion?token=${LINK_TOKEN}`,
    )

    expect(wrapper.get('[aria-busy="true"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Cargando la invitación')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('shows the error and tries again when the API cannot be reached; the link was not spent', async () => {
    let failing = true
    const api = invitationLinkApi({ override: () => (failing ? problem(500) : undefined) })
    const { wrapper } = await open()

    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar la invitación')
    expect(wrapper.find('form').exists()).toBe(false)

    failing = false
    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()

    expect(wrapper.find('form').exists()).toBe(true)
    expect(api.accepted()).toBe(0)
  })

  it('a link without a token is the empty state, and nothing is asked of the API', async () => {
    const fetchMock = mockFetch(() => jsonResponse(204))
    const { wrapper } = await open('')

    expect(wrapper.get('h1').text()).toBe('Enlace no válido')
    expect(wrapper.text()).toContain('Cada enlace vale 7 días y se puede usar una sola vez.')
    expect(wrapper.get('a[href="/ingresar"]').text()).toBe('Ir a iniciar sesión')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('an unknown, expired, used or cancelled link shows the same empty state', async () => {
    invitationLinkApi()
    const { wrapper } = await open('?token=otro')

    expect(wrapper.get('h1').text()).toBe('Enlace no válido')
    expect(wrapper.text()).toContain('Este enlace no sirve o ya venció.')
    expect(wrapper.find('form').exists()).toBe(false)
  })
})

describe('AcceptInvitationView: an email with no account', () => {
  it('asks for the name and the password with the list of SE-07, and no phone or code', async () => {
    invitationLinkApi()
    const { wrapper } = await open()

    expect(wrapper.findAll('label').map((label) => label.text())).toEqual([
      'Nombre completo',
      'Contraseña',
      'Confirmar contraseña',
    ])
    expect(field(wrapper, 'name').attributes('autocomplete')).toBe('name')
    expect(field(wrapper, 'new-password').attributes('autocomplete')).toBe('new-password')
    expect(wrapper.findAll('li[data-state]')).toHaveLength(5)
    expect(wrapper.get('button[type="submit"]').text()).toBe('Crear cuenta y unirme')
  })

  it('sends the token, the name and the password, and says the account is active', async () => {
    const api = invitationLinkApi()
    const { wrapper } = await open()

    await fillAndSubmit(wrapper)

    expect(api.bodies[1]).toEqual({
      route: 'POST /api/v1/invitaciones/aceptacion',
      body: { token: LINK_TOKEN, nombre: 'Lucía Mora', contrasena: PASSWORD },
    })
    expect(wrapper.get('h1').text()).toBe('Invitación aceptada')
    expect(wrapper.get('[role="status"]').text()).toContain(
      'Ya forma parte del equipo de Pura Navaja.',
    )
    expect(wrapper.get('a[href="/ingresar"]').text()).toBe('Ir a iniciar sesión')
    expect(wrapper.text()).not.toContain(PASSWORD)
  })

  it('does not call the API when the name is empty, the password is weak or the confirmation differs', async () => {
    const api = invitationLinkApi()
    const { wrapper } = await open()

    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('Escriba su nombre completo.')
    expect(wrapper.text()).toContain('Escriba la contraseña.')

    await fillAndSubmit(wrapper, { password: 'debil' })
    expect(wrapper.text()).toContain('La contraseña no cumple los requisitos.')
    expect(wrapper.findAll('li[data-state="missing"]')).toHaveLength(4)

    await field(wrapper, 'name').setValue('Lucía Mora')
    await field(wrapper, 'new-password').setValue(PASSWORD)
    await field(wrapper, 'confirm-password').setValue('Otra#Clave8')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('Las contraseñas no coinciden.')
    expect(field(wrapper, 'confirm-password').attributes('aria-invalid')).toBe('true')

    expect(api.calls).toEqual(['POST /api/v1/invitaciones/consulta'])
  })

  it('shows the refusal of the API on its field and keeps the form', async () => {
    invitationLinkApi({
      override: (request) =>
        request.url.endsWith('/aceptacion')
          ? problem(422, {
              type: 'datos-invalidos',
              errores: { contrasena: ['La contraseña debe incluir al menos un signo especial.'] },
            })
          : undefined,
    })
    const { wrapper } = await open()

    await fillAndSubmit(wrapper)

    expect(wrapper.text()).toContain('La contraseña debe incluir al menos un signo especial.')
    expect(field(wrapper, 'new-password').attributes('aria-invalid')).toBe('true')
    expect(wrapper.find('form').exists()).toBe(true)
  })

  it('a link that stopped working meanwhile shows the empty state', async () => {
    invitationLinkApi({
      override: (request) =>
        request.url.endsWith('/aceptacion')
          ? problem(422, { type: 'datos-invalidos', errores: { token: [INVALID_LINK] } })
          : undefined,
    })
    const { wrapper } = await open()

    await fillAndSubmit(wrapper)

    expect(wrapper.get('h1').text()).toBe('Enlace no válido')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('keeps what was written and offers to try again when the network fails', async () => {
    let offline = false
    invitationLinkApi({
      override: (request) => {
        if (!offline || !request.url.endsWith('/aceptacion')) return undefined
        throw new TypeError('Failed to fetch')
      },
    })
    const { wrapper } = await open()
    offline = true

    await fillAndSubmit(wrapper)

    expect(wrapper.text()).toContain('No pudimos comunicarnos con el servidor.')
    expect((field(wrapper, 'name').element as HTMLInputElement).value).toBe('Lucía Mora')

    offline = false
    await wrapper
      .findAll('button')
      .find((button) => button.text() === 'Reintentar')!
      .trigger('click')
    await flushPromises()
    expect(wrapper.get('h1').text()).toBe('Invitación aceptada')
  })
})

describe('AcceptInvitationView: an email that already has an account', () => {
  it('only asks to accept: no name and no password, and it says the password does not change', async () => {
    invitationLinkApi({ existing: true })
    const { wrapper } = await open()

    expect(wrapper.text()).toContain('Ya existe una cuenta con lucia@correo.cr.')
    expect(wrapper.text()).toContain('su contraseña no cambia')
    expect(wrapper.findAll('input')).toHaveLength(0)
    expect(wrapper.get('button[type="submit"]').text()).toBe('Aceptar invitación')
  })

  it('sends only the token and says the person can enter as always', async () => {
    const api = invitationLinkApi({ existing: true })
    const { wrapper } = await open()

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(api.bodies[1]).toEqual({
      route: 'POST /api/v1/invitaciones/aceptacion',
      body: { token: LINK_TOKEN },
    })
    expect(wrapper.text()).toContain('Ingrese con su contraseña de siempre.')
  })

  it('shows the 409 of a person who already has a role in the barbershop and keeps the link', async () => {
    invitationLinkApi({
      existing: true,
      override: (request) =>
        request.url.endsWith('/aceptacion')
          ? problem(409, {
              type: 'http://localhost/problemas/conflicto',
              detail: 'La persona ya tiene un rol en esta barbería.',
            })
          : undefined,
    })
    const { wrapper } = await open()

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain(
      'La persona ya tiene un rol en esta barbería.',
    )
    expect(wrapper.find('form').exists()).toBe(true)
  })

  it('with a session open, the way out leads to the shops of the person', async () => {
    signIn('lucia@correo.cr')
    invitationLinkApi({ existing: true })
    const { wrapper } = await open()

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('a[href="/negocios"]').text()).toBe('Ir a mis negocios')
  })
})
