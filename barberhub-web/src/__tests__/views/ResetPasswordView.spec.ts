import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import ResetPasswordView from '@/views/ResetPasswordView.vue'
import { useSessionStore } from '@/stores/session'
import { signIn } from '../helpers/account'
import { jsonResponse, mockFetch, pathOf, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'

const TOKEN = 'a1b2c3d4e5'
const NEW = 'Nueva#Clave9'
const INVALID_LINK = 'El enlace para restablecer la contraseña no es válido o ya venció.'

beforeEach(() => sessionStorage.clear())
afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

async function open(query = `?token=${TOKEN}`) {
  const mounted = await mountView(ResetPasswordView, `/restablecer-contrasena${query}`)
  await flushPromises()
  return mounted
}

type Mounted = Awaited<ReturnType<typeof open>>['wrapper']

const field = (wrapper: Mounted, name: string) => wrapper.get(`input[name="${name}"]`)

async function fillAndSubmit(wrapper: Mounted, next = NEW, confirmation = next) {
  await field(wrapper, 'new-password').setValue(next)
  await field(wrapper, 'confirm-password').setValue(confirmation)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

describe('ResetPasswordView (RF-41)', () => {
  it('asks for the new password and its confirmation, with the list of SE-07', async () => {
    const { wrapper } = await open()

    expect(wrapper.findAll('h1').map((heading) => heading.text())).toEqual([
      'Crear nueva contraseña',
    ])
    expect(wrapper.findAll('label').map((label) => label.text())).toEqual([
      'Contraseña nueva',
      'Confirmar contraseña nueva',
    ])
    expect(field(wrapper, 'new-password').attributes('autocomplete')).toBe('new-password')
    expect(field(wrapper, 'new-password').attributes('aria-describedby')).toContain(
      'password-checklist',
    )
    expect(wrapper.findAll('li[data-state]')).toHaveLength(5)
  })

  it('takes the token out of the address once it has read it', async () => {
    const { router } = await open()

    expect(router.currentRoute.value.name).toBe('reset-password')
    expect(router.currentRoute.value.query.token).toBeUndefined()
    expect(router.currentRoute.value.fullPath).not.toContain(TOKEN)
  })

  it('sends the token and the new password, then says it is done and closes the local session', async () => {
    signIn('marco@example.test')
    const fetchMock = mockFetch(() => jsonResponse(204))
    const { wrapper } = await open()
    const session = useSessionStore()
    expect(session.isAuthenticated).toBe(true)

    await fillAndSubmit(wrapper)

    const [request] = fetchMock.mock.calls[0] ?? []
    expect(pathOf(request as Request)).toBe('POST /api/v1/contrasena/restablecimiento')
    expect(await (request as Request).json()).toEqual({
      token: TOKEN,
      contrasena_nueva: NEW,
    })
    expect(wrapper.get('h1').text()).toBe('Contraseña actualizada')
    expect(wrapper.get('[role="status"]').text()).toContain('Su contraseña se actualizó.')
    expect(wrapper.get('a[href="/ingresar"]').text()).toBe('Ir a iniciar sesión')
    expect(session.isAuthenticated).toBe(false)
  })

  it('does not call the API when the confirmation differs', async () => {
    const fetchMock = mockFetch(() => jsonResponse(204))
    const { wrapper } = await open()

    await fillAndSubmit(wrapper, NEW, 'Otra#Clave8')

    expect(wrapper.text()).toContain('Las contraseñas no coinciden.')
    expect(field(wrapper, 'confirm-password').attributes('aria-invalid')).toBe('true')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('says which requirement is missing, as the register does, and does not call the API', async () => {
    const fetchMock = mockFetch(() => jsonResponse(204))
    const { wrapper } = await open()

    await fillAndSubmit(wrapper, 'debil')

    expect(wrapper.text()).toContain('La contraseña nueva no cumple los requisitos.')
    const missing = wrapper.findAll('li[data-state="missing"]').map((item) => item.text())
    expect(missing).toEqual([
      'Falta: Mínimo 8 caracteres',
      'Falta: Una letra mayúscula',
      'Falta: Un número',
      'Falta: Un signo especial',
    ])
    expect(wrapper.find('li[data-state="met"]').text()).toContain('Una letra minúscula')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('asks for a password when the field is empty', async () => {
    const { wrapper } = await open()

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Escriba la contraseña nueva.')
  })

  it('shows a password refused by the API on its field and keeps the form', async () => {
    mockFetch(() =>
      problem(422, {
        errores: { contrasena_nueva: ['La contraseña debe incluir al menos un signo especial.'] },
      }),
    )
    const { wrapper } = await open()

    await fillAndSubmit(wrapper)

    expect(wrapper.text()).toContain('La contraseña debe incluir al menos un signo especial.')
    expect(wrapper.find('form').exists()).toBe(true)
  })

  it('an expired, used or unknown link shows the invalid-link state with the way out', async () => {
    mockFetch(() => problem(422, { errores: { token: [INVALID_LINK] } }))
    const { wrapper } = await open()

    await fillAndSubmit(wrapper)

    expect(wrapper.get('h1').text()).toBe('Enlace no válido')
    expect(wrapper.get('[role="alert"]').text()).toContain('Este enlace no sirve o ya venció.')
    expect(wrapper.get('a[href="/olvide-mi-contrasena"]').text()).toBe('Solicitar un enlace nuevo')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('a link without a token is the invalid-link state and never calls the API', async () => {
    const fetchMock = mockFetch(() => jsonResponse(204))
    const { wrapper } = await open('')

    expect(wrapper.get('h1').text()).toBe('Enlace no válido')
    expect(wrapper.find('input').exists()).toBe(false)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('disables the fields and the button while it sends', async () => {
    let release: (response: Response) => void = () => {}
    mockFetch(() => new Promise<Response>((resolve) => (release = resolve)))
    const { wrapper } = await open()

    await field(wrapper, 'new-password').setValue(NEW)
    await field(wrapper, 'confirm-password').setValue(NEW)
    await wrapper.get('form').trigger('submit')

    expect(wrapper.get('main').attributes('aria-busy')).toBe('true')
    expect(field(wrapper, 'new-password').attributes('disabled')).toBeDefined()
    expect(wrapper.get('button[type="submit"]').text()).toContain('Guardando')

    release(jsonResponse(204))
    await flushPromises()
    expect(wrapper.get('h1').text()).toBe('Contraseña actualizada')
  })

  it('a network failure offers «Reintentar» and keeps what was typed', async () => {
    const fetchMock = mockFetch(() => {
      throw new TypeError('Failed to fetch')
    })
    const { wrapper } = await open()

    await fillAndSubmit(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain(
      'No pudimos comunicarnos con el servidor.',
    )
    expect((field(wrapper, 'new-password').element as HTMLInputElement).value).toBe(NEW)
    fetchMock.mockImplementation(async () => jsonResponse(204))
    const retry = wrapper.findAll('button').find((button) => button.text() === 'Reintentar')
    await retry?.trigger('click')
    await flushPromises()
    expect(wrapper.get('h1').text()).toBe('Contraseña actualizada')
  })
})
