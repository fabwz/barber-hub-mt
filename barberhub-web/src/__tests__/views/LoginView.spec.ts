import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import LoginView from '@/views/LoginView.vue'
import { jsonResponse, mockFetch, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { CLIENT_SHOP, OWNER_SHOP, STAFF_SHOP, staffHandler } from '../helpers/staff'

beforeEach(() => sessionStorage.clear())
afterEach(() => {
  vi.unstubAllGlobals()
  vi.useRealTimers()
  document.body.innerHTML = ''
})

async function fillAndSubmit(wrapper: Awaited<ReturnType<typeof mountView>>['wrapper']) {
  await wrapper.get('input[type="email"]').setValue('ana@example.test')
  await wrapper.get('input[type="password"]').setValue('Secreta1!')
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

describe('LoginView', () => {
  it('has the right attributes, visible labels and one h1', async () => {
    const { wrapper } = await mountView(LoginView, '/ingresar')
    const email = wrapper.get('input[type="email"]')
    const password = wrapper.get('input[type="password"]')

    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('Iniciar sesión')
    expect(email.attributes('autocomplete')).toBe('email')
    expect(email.attributes('name')).toBe('email')
    expect(email.attributes('inputmode')).toBe('email')
    expect(password.attributes('autocomplete')).toBe('current-password')
    expect(wrapper.text()).toContain('Correo electrónico')
    expect(wrapper.text()).toContain('Contraseña')
    expect(wrapper.text()).not.toMatch(/olvid|recuperar|mostrar/i)
    expect(wrapper.get('img').attributes('alt')).toBe('BarberHub')
  })

  it('shows and hides the password with the eye button', async () => {
    const { wrapper } = await mountView(LoginView, '/ingresar')
    const input = wrapper.get('input[name="password"]')
    const eye = wrapper.get('button[aria-label="Mostrar contraseña"]')

    expect(input.attributes('type')).toBe('password')
    await eye.trigger('click')
    expect(input.attributes('type')).toBe('text')
    expect(eye.attributes('aria-pressed')).toBe('true')
    await eye.trigger('click')
    expect(input.attributes('type')).toBe('password')
  })

  it('logs in and returns to the redirect route', async () => {
    const fetchMock = mockFetch(staffHandler([OWNER_SHOP, STAFF_SHOP]))
    const { wrapper, router } = await mountView(LoginView, '/ingresar?redirect=/agenda')

    await fillAndSubmit(wrapper)

    expect(router.currentRoute.value.path).toBe('/agenda')
    expect(sessionStorage.getItem('barberhub.session')).toContain('tok-1')
    // A valid redirect is respected as is: the shops are not even asked for.
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('without a redirect, several staff shops go to /negocios', async () => {
    mockFetch(staffHandler([OWNER_SHOP, STAFF_SHOP]))
    const { wrapper, router } = await mountView(LoginView, '/ingresar')

    await fillAndSubmit(wrapper)

    // The view is loaded lazily: wait for the navigation to finish.
    await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/negocios'))
  })

  it('without a redirect, a single staff shop also goes to /negocios (the view enters directly)', async () => {
    mockFetch(staffHandler([OWNER_SHOP]))
    const { wrapper, router } = await mountView(LoginView, '/ingresar')

    await fillAndSubmit(wrapper)

    // The view is loaded lazily: wait for the navigation to finish.
    await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/negocios'))
  })

  it('without a redirect, a customer with no staff shop goes to «/»', async () => {
    mockFetch(staffHandler([CLIENT_SHOP]))
    const { wrapper, router } = await mountView(LoginView, '/ingresar')

    await fillAndSubmit(wrapper)

    expect(router.currentRoute.value.path).toBe('/')
  })

  it('if the shops cannot be loaded the login still ends at «/»', async () => {
    mockFetch((request) =>
      request.method === 'POST'
        ? jsonResponse(201, { data: { token: 'tok-1', tipo: 'Bearer' } })
        : problem(500),
    )
    const { wrapper, router } = await mountView(LoginView, '/ingresar')

    await fillAndSubmit(wrapper)

    expect(router.currentRoute.value.path).toBe('/')
  })

  it.each(['//evil.com', 'https://evil.com'])(
    'ignores the external redirect %s',
    async (redirect) => {
      mockFetch(staffHandler([]))
      const { wrapper, router } = await mountView(
        LoginView,
        `/ingresar?redirect=${encodeURIComponent(redirect)}`,
      )

      await fillAndSubmit(wrapper)

      expect(router.currentRoute.value.path).toBe('/')
    },
  )

  it('shows the success notice after registering', async () => {
    const { wrapper } = await mountView(LoginView, '/ingresar?registrado=1')

    expect(wrapper.get('[role="status"]').text()).toContain(
      'Su cuenta ha sido registrada con éxito',
    )
  })

  it('a credentials error shows one generic message', async () => {
    mockFetch(() =>
      problem(401, { type: 'credenciales-invalidas', detail: 'No existe ese correo.' }),
    )
    const { wrapper, router } = await mountView(LoginView, '/ingresar')

    await fillAndSubmit(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain(
      'El correo o la contraseña no son correctos.',
    )
    expect(wrapper.text()).not.toContain('No existe ese correo')
    expect(router.currentRoute.value.path).toBe('/ingresar')
    expect(sessionStorage.getItem('barberhub.session')).toBeNull()
  })

  it('a 429 shows the countdown, disables the button and enables it at the end', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    mockFetch(() => problem(429, { type: 'demasiadas-solicitudes' }, { 'Retry-After': '125' }))
    const { wrapper } = await mountView(LoginView, '/ingresar')

    await fillAndSubmit(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain(
      'Demasiados intentos. Intente de nuevo en 2:05',
    )
    const button = wrapper.get('button[type="submit"]')
    expect(button.attributes('disabled')).toBeDefined()

    await vi.advanceTimersByTimeAsync(60_000)
    expect(wrapper.get('[role="timer"]').text()).toBe('1:05')

    await vi.advanceTimersByTimeAsync(65_000)
    expect(wrapper.find('[role="timer"]').exists()).toBe(false)
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeUndefined()
  })

  it('a 429 without Retry-After uses the 15 minutes of the rule', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    mockFetch(() => problem(429))
    const { wrapper } = await mountView(LoginView, '/ingresar')

    await fillAndSubmit(wrapper)

    expect(wrapper.get('[role="timer"]').text()).toBe('15:00')
  })

  it('a network error shows a notice with a retry action that works', async () => {
    let online = false
    mockFetch((request) => {
      if (!online) throw new TypeError('offline')
      return staffHandler([])(request)
    })
    const { wrapper, router } = await mountView(LoginView, '/ingresar')

    await fillAndSubmit(wrapper)
    expect(wrapper.get('[role="alert"]').text()).toContain(
      'No pudimos comunicarnos con el servidor',
    )

    online = true
    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/')
  })

  it('a 422 puts the message of the API on its field', async () => {
    mockFetch(() => problem(422, { errores: { correo: ['El correo no es válido.'] } }))
    const { wrapper } = await mountView(LoginView, '/ingresar')

    await fillAndSubmit(wrapper)

    expect(wrapper.get('input[type="email"]').attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('El correo no es válido.')
  })

  it('while sending the button is busy and the inputs are disabled', async () => {
    let release!: () => void
    mockFetch(
      () =>
        new Promise<Response>((resolve) => {
          release = () => resolve(jsonResponse(201, { data: { token: 't', tipo: 'Bearer' } }))
        }),
    )
    const { wrapper } = await mountView(LoginView, '/ingresar')

    await wrapper.get('input[type="email"]').setValue('ana@example.test')
    await wrapper.get('input[type="password"]').setValue('x')
    await wrapper.get('form').trigger('submit')

    expect(wrapper.get('button[type="submit"]').attributes('aria-busy')).toBe('true')
    expect(wrapper.get('input[type="email"]').attributes('disabled')).toBeDefined()
    release()
    await flushPromises()
  })
})
