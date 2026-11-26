import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import ForgotPasswordView from '@/views/ForgotPasswordView.vue'
import { jsonResponse, mockFetch, pathOf, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'

beforeEach(() => sessionStorage.clear())
afterEach(() => {
  vi.unstubAllGlobals()
  vi.useRealTimers()
  document.body.innerHTML = ''
})

async function open() {
  return mountView(ForgotPasswordView, '/olvide-mi-contrasena')
}

type Mounted = Awaited<ReturnType<typeof open>>['wrapper']

async function submit(wrapper: Mounted, email = 'ana@example.test') {
  await wrapper.get('input[type="email"]').setValue(email)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

describe('ForgotPasswordView (RF-41)', () => {
  it('has one h1, a labelled email field and a link back to the login', async () => {
    const { wrapper } = await open()
    const email = wrapper.get('input[type="email"]')

    expect(wrapper.findAll('h1').map((heading) => heading.text())).toEqual(['Olvidé mi contraseña'])
    expect(wrapper.get('label').text()).toBe('Correo electrónico')
    expect(email.attributes('autocomplete')).toBe('email')
    expect(email.attributes('inputmode')).toBe('email')
    expect(wrapper.get('button[type="submit"]').text()).toBe('Enviar enlace')
    expect(wrapper.get('a[href="/ingresar"]').text()).toContain('Volver a iniciar sesión')
  })

  it('asks for the email on its field when it is empty, without calling the API', async () => {
    const fetchMock = mockFetch(() => jsonResponse(202))
    const { wrapper } = await open()

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Escriba su correo electrónico.')
    expect(wrapper.get('input[type="email"]').attributes('aria-invalid')).toBe('true')
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('sends the email to the API and always shows the same notice', async () => {
    const fetchMock = mockFetch(() => jsonResponse(202))
    const { wrapper } = await open()

    await submit(wrapper, '  ana@example.test ')

    const [request] = fetchMock.mock.calls[0] ?? []
    expect(pathOf(request as Request)).toBe('POST /api/v1/contrasena/recuperacion')
    expect(await (request as Request).json()).toEqual({ correo: 'ana@example.test' })
    const notice = wrapper.get('[role="status"]')
    expect(notice.text()).toContain('Revise su correo.')
    expect(notice.text()).toContain('Si hay una cuenta con ese correo')
    expect(notice.text()).toContain('60 minutos')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('shows the very same text for any email: nothing tells an account apart', async () => {
    mockFetch(() => jsonResponse(202))
    const first = await open()
    await submit(first.wrapper, 'ana@example.test')
    const known = first.wrapper.get('[role="status"]').text()
    first.wrapper.unmount()

    const second = await open()
    await submit(second.wrapper, 'nadie@example.test')

    expect(second.wrapper.get('[role="status"]').text()).toBe(known)
  })

  it('goes back to an empty form with «Usar otro correo»', async () => {
    mockFetch(() => jsonResponse(202))
    const { wrapper } = await open()
    await submit(wrapper)

    const other = wrapper.findAll('button').find((button) => button.text() === 'Usar otro correo')
    await other?.trigger('click')

    expect((wrapper.get('input[type="email"]').element as HTMLInputElement).value).toBe('')
  })

  it('disables the field and the button while it sends', async () => {
    let release: (response: Response) => void = () => {}
    mockFetch(() => new Promise<Response>((resolve) => (release = resolve)))
    const { wrapper } = await open()

    await wrapper.get('input[type="email"]').setValue('ana@example.test')
    await wrapper.get('form').trigger('submit')

    expect(wrapper.get('main').attributes('aria-busy')).toBe('true')
    expect(wrapper.get('input[type="email"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('button[type="submit"]').text()).toContain('Enviando')

    release(jsonResponse(202))
    await flushPromises()
    expect(wrapper.get('[role="status"]').exists()).toBe(true)
  })

  it('shows an invalid email on its field with what was typed kept', async () => {
    mockFetch(() =>
      problem(422, { errores: { correo: ['El correo debe ser una dirección válida.'] } }),
    )
    const { wrapper } = await open()

    await submit(wrapper, 'no-es-un-correo')

    expect(wrapper.text()).toContain('El correo debe ser una dirección válida.')
    expect((wrapper.get('input[type="email"]').element as HTMLInputElement).value).toBe(
      'no-es-un-correo',
    )
  })

  it('a 429 shows the countdown from Retry-After and holds the button until it ends', async () => {
    vi.useFakeTimers()
    mockFetch(() => problem(429, { type: 'demasiadas-solicitudes' }, { 'Retry-After': '125' }))
    const { wrapper } = await open()

    await submit(wrapper)

    expect(wrapper.get('[role="timer"]').text()).toBe('2:05')
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()
    vi.advanceTimersByTime(125_000)
    await flushPromises()
    expect(wrapper.find('[role="timer"]').exists()).toBe(false)
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeUndefined()
  })

  it('a 429 without Retry-After waits the hour of the rule', async () => {
    vi.useFakeTimers()
    mockFetch(() => problem(429))
    const { wrapper } = await open()

    await submit(wrapper)

    expect(wrapper.get('[role="timer"]').text()).toBe('60:00')
  })

  it('a network failure offers «Reintentar» and keeps the email', async () => {
    const fetchMock = mockFetch(() => {
      throw new TypeError('Failed to fetch')
    })
    const { wrapper } = await open()

    await submit(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain(
      'No pudimos comunicarnos con el servidor.',
    )
    expect((wrapper.get('input[type="email"]').element as HTMLInputElement).value).toBe(
      'ana@example.test',
    )
    fetchMock.mockImplementation(async () => jsonResponse(202))
    const retry = wrapper.findAll('button').find((button) => button.text() === 'Reintentar')
    await retry?.trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toContain('Revise su correo.')
  })

  it('any other error is shown as a general notice', async () => {
    mockFetch(() => problem(500, { detail: 'No se pudo completar la operación.' }))
    const { wrapper } = await open()

    await submit(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain('No se pudo completar la operación.')
  })
})
