import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import RegisterView from '@/views/RegisterView.vue'
import { jsonResponse, mockFetch, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'

beforeEach(() => sessionStorage.clear())
afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

type Wrapper = Awaited<ReturnType<typeof mountView>>['wrapper']

async function fillText(wrapper: Wrapper, password = 'Abcdef1!') {
  await wrapper.get('input[name="name"]').setValue('Laura Brenes Mora')
  await wrapper.get('input[name="email"]').setValue('laura@example.test')
  await wrapper.get('input[name="phone"]').setValue('88888888')
  await wrapper.get('input[name="password"]').setValue(password)
}

async function check(wrapper: Wrapper, which: 'both' | 'terms' | 'privacy' = 'both') {
  const boxes = wrapper.findAll('[role="checkbox"]')
  if (which !== 'privacy') await boxes[0]!.trigger('click')
  if (which !== 'terms') await boxes[1]!.trigger('click')
}

async function submit(wrapper: Wrapper) {
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

describe('RegisterView', () => {
  it('has the right attributes, visible labels and one h1', async () => {
    const { wrapper } = await mountView(RegisterView, '/registro')

    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('input[name="name"]').attributes('autocomplete')).toBe('name')
    expect(wrapper.get('input[name="email"]').attributes('type')).toBe('email')
    expect(wrapper.get('input[name="phone"]').attributes('type')).toBe('tel')
    expect(wrapper.get('input[name="phone"]').attributes('inputmode')).toBe('numeric')
    expect(wrapper.get('input[name="password"]').attributes('autocomplete')).toBe('new-password')
    for (const label of ['Nombre completo', 'Correo electrónico', 'Teléfono', 'Contraseña']) {
      expect(wrapper.text()).toContain(label)
    }
    expect(wrapper.text()).not.toMatch(/olvid|recuperar|temporal/i)
  })

  it('has two separate required checkboxes linking to the legal pages in the same tab', async () => {
    const { wrapper } = await mountView(RegisterView, '/registro')
    const boxes = wrapper.findAll('[role="checkbox"]')
    const links = wrapper.findAll('a[href="/terminos"], a[href="/privacidad"]')

    expect(boxes).toHaveLength(2)
    for (const box of boxes) expect(box.attributes('aria-required')).toBe('true')
    expect(links.map((link) => link.attributes('href'))).toEqual(['/terminos', '/privacidad'])
    for (const link of links) expect(link.attributes('target')).toBeUndefined()
  })

  it('shows only the requirements sentence while typing: nothing turns red until a failed submit', async () => {
    const { wrapper } = await mountView(RegisterView, '/registro')
    const sentence = () => wrapper.get('#register-password-requirements p')

    expect(sentence().text()).toBe(
      'Mínimo 8 caracteres, una mayúscula, una minúscula, un número y un símbolo.',
    )
    await wrapper.get('input[name="password"]').setValue('abc')

    expect(sentence().classes()).toContain('text-fg-subtle')
    expect(wrapper.find('#register-password-requirements li').exists()).toBe(false)
  })

  it('turns the sentence red after a failed submit and back to normal once everything is met', async () => {
    const { wrapper } = await mountView(RegisterView, '/registro')
    const sentence = () => wrapper.get('#register-password-requirements p')
    await fillText(wrapper, 'abc')
    await check(wrapper)

    await submit(wrapper)
    expect(sentence().classes()).toEqual(expect.arrayContaining(['text-danger', 'font-medium']))

    await wrapper.get('input[name="password"]').setValue('Abcdef1!')
    expect(sentence().classes()).toContain('text-fg-subtle')
  })

  it('shows and hides the password with the eye button', async () => {
    const { wrapper } = await mountView(RegisterView, '/registro')
    const input = wrapper.get('input[name="password"]')
    const eye = wrapper.get('button[aria-label="Mostrar contraseña"]')

    expect(input.attributes('type')).toBe('password')
    expect(eye.attributes('type')).toBe('button')
    expect(eye.attributes('aria-pressed')).toBe('false')

    await eye.trigger('click')
    expect(input.attributes('type')).toBe('text')
    expect(eye.attributes('aria-pressed')).toBe('true')

    await eye.trigger('click')
    expect(input.attributes('type')).toBe('password')
  })

  it('keeps only letters, spaces, apostrophes, hyphens and periods in the name', async () => {
    const { wrapper } = await mountView(RegisterView, '/registro')
    const input = wrapper.get('input[name="name"]')

    await input.setValue("María-José O'Brien Jr. 2 #")

    expect((input.element as HTMLInputElement).value).toBe("María-José O'Brien Jr.  ")
  })

  it('keeps only 8 digits in the phone and shows them as 1234-5678', async () => {
    const { wrapper } = await mountView(RegisterView, '/registro')
    const input = wrapper.get('input[name="phone"]')
    const value = () => (input.element as HTMLInputElement).value

    expect(input.attributes('placeholder')).toBe('1234-5678')
    expect(input.attributes('maxlength')).toBe('9')

    await input.setValue('1234')
    expect(value()).toBe('1234')
    await input.setValue('12345')
    expect(value()).toBe('1234-5')
    await input.setValue('abc 1234-5678 99')
    expect(value()).toBe('1234-5678')
  })

  it('drops the hyphen when the 5th digit is deleted', async () => {
    const { wrapper } = await mountView(RegisterView, '/registro')
    const input = wrapper.get('input[name="phone"]')

    await input.setValue('1234-5')
    await input.setValue('1234-')

    expect((input.element as HTMLInputElement).value).toBe('1234')
  })

  it('an incomplete phone says it needs 8 digits and the API receives only digits', async () => {
    const fetchMock = mockFetch(() => jsonResponse(201, { data: {} }))
    const { wrapper } = await mountView(RegisterView, '/registro')
    await fillText(wrapper)
    await check(wrapper)
    await wrapper.get('input[name="phone"]').setValue('1234-56')

    await submit(wrapper)

    expect(fetchMock).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('El teléfono debe tener 8 dígitos. Ejemplo: 1234-5678.')

    await wrapper.get('input[name="phone"]').setValue('12345678')
    await submit(wrapper)

    expect(await fetchMock.mock.calls[0]![0].clone().json()).toMatchObject({ telefono: '12345678' })
  })

  it('the legal names are links, the lead-in words are lighter', async () => {
    const { wrapper } = await mountView(RegisterView, '/registro')

    const links = wrapper.findAll('a[href="/terminos"], a[href="/privacidad"]')
    // Bold and no underline (DESIGN.md: the link inside the checkbox has no underline).
    expect(links.map((link) => link.classes())).toEqual([
      expect.arrayContaining(['font-medium', 'no-underline']),
      expect.arrayContaining(['font-medium', 'no-underline']),
    ])
    const leadIns = wrapper.findAll('[role="checkbox"] + span > span')
    expect(leadIns.map((node) => node.text())).toEqual(['Acepto los', 'Acepto la'])
    expect(leadIns[0]!.classes()).toContain('text-fg-muted')
  })

  it.each([
    ['laura.correo.cr', 'Al correo le falta el @. Ejemplo: nombre@correo.cr.'],
    ['@correo.cr', 'Al correo le falta lo que va antes del @.'],
    ['laura@correo', 'Al correo le falta el dominio. Ejemplo: nombre@correo.cr.'],
    ['laura@.cr', 'Al correo le falta el dominio.'],
    ['la ura@correo.cr', 'El correo no puede llevar espacios.'],
  ])('says what the email %s is missing and does not call the API', async (value, message) => {
    const fetchMock = mockFetch(() => jsonResponse(201, { data: {} }))
    const { wrapper } = await mountView(RegisterView, '/registro')
    await fillText(wrapper)
    await wrapper.get('input[name="email"]').setValue(value)
    await check(wrapper)

    await submit(wrapper)

    expect(fetchMock).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain(message)
    expect(document.activeElement).toBe(wrapper.get('input[name="email"]').element)
  })

  it.each(['laura@correo.cr', 'laura@correo.com', 'laura.m@empresa.co.cr', 'l+a@mail.org'])(
    'accepts the email %s whatever its ending',
    async (value) => {
      const fetchMock = mockFetch(() => jsonResponse(201, { data: {} }))
      const { wrapper } = await mountView(RegisterView, '/registro')
      await fillText(wrapper)
      await wrapper.get('input[name="email"]').setValue(value)
      await check(wrapper)

      await submit(wrapper)

      expect(fetchMock).toHaveBeenCalledTimes(1)
    },
  )

  it('with empty fields it says each one is required, does not call the API and focuses the first', async () => {
    const fetchMock = mockFetch(() => jsonResponse(201, { data: {} }))
    const { wrapper } = await mountView(RegisterView, '/registro')

    await submit(wrapper)

    expect(fetchMock).not.toHaveBeenCalled()
    for (const message of [
      'Escriba su nombre completo.',
      'Escriba su correo electrónico.',
      'Escriba su teléfono.',
      'Escriba una contraseña.',
    ]) {
      expect(wrapper.text()).toContain(message)
    }
    expect(document.activeElement).toBe(wrapper.get('input[name="name"]').element)
  })

  it('without the two checkboxes it does not call the API, shows both errors and focuses the first', async () => {
    const fetchMock = mockFetch(() => jsonResponse(201, { data: {} }))
    const { wrapper } = await mountView(RegisterView, '/registro')
    await fillText(wrapper)

    await submit(wrapper)

    expect(fetchMock).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain(
      'Debe aceptar los Términos y condiciones para crear su cuenta.',
    )
    expect(wrapper.text()).toContain('Debe aceptar la Política de privacidad para crear su cuenta.')
    expect(document.activeElement).toBe(wrapper.findAll('[role="checkbox"]')[0]!.element)
  })

  it('needs each checkbox on its own: only the missing one shows an error', async () => {
    mockFetch(() => jsonResponse(201, { data: {} }))
    const { wrapper } = await mountView(RegisterView, '/registro')
    await fillText(wrapper)
    await check(wrapper, 'terms')

    await submit(wrapper)

    expect(wrapper.text()).not.toContain('Debe aceptar los Términos')
    expect(wrapper.text()).toContain('Debe aceptar la Política de privacidad')
    expect(document.activeElement).toBe(wrapper.findAll('[role="checkbox"]')[1]!.element)
  })

  it('a weak password reads the requirements to screen readers and focuses the password', async () => {
    const fetchMock = mockFetch(() => jsonResponse(201, { data: {} }))
    const { wrapper } = await mountView(RegisterView, '/registro')
    await fillText(wrapper, 'laura1234')
    await check(wrapper)

    await submit(wrapper)

    expect(fetchMock).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('No cumplido: Una mayúscula')
    expect(wrapper.text()).toContain('Cumplido: Un número')
    expect(wrapper.text()).toContain('La contraseña no cumple los requisitos.')
    expect(document.activeElement).toBe(wrapper.get('input[name="password"]').element)
  })

  it('shows the 422 messages of the API per field and focuses the first with an error', async () => {
    mockFetch(() =>
      problem(422, {
        errores: {
          correo: ['Este correo ya tiene una cuenta. Ingrese con ese correo o use otro.'],
          telefono: ['El teléfono no es válido.'],
        },
      }),
    )
    const { wrapper } = await mountView(RegisterView, '/registro')
    await fillText(wrapper)
    await check(wrapper)

    await submit(wrapper)

    const email = wrapper.get('input[name="email"]')
    expect(email.attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('Este correo ya tiene una cuenta.')
    expect(wrapper.get('input[name="phone"]').attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('El teléfono no es válido.')
    expect(wrapper.get('input[name="name"]').attributes('aria-invalid')).toBeUndefined()
    expect(document.activeElement).toBe(email.element)
  })

  it('on success goes to /ingresar with the success flag and clears the form', async () => {
    const fetchMock = mockFetch(() => jsonResponse(201, { data: { id: '1' } }))
    const { wrapper, router } = await mountView(RegisterView, '/registro?redirect=/agenda')
    await fillText(wrapper)
    await check(wrapper)

    await submit(wrapper)

    expect(await fetchMock.mock.calls[0]![0].clone().json()).toMatchObject({
      nombre: 'Laura Brenes Mora',
      correo: 'laura@example.test',
      telefono: '88888888',
      acepta_terminos: true,
      acepta_privacidad: true,
    })
    await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/ingresar'))
    expect(router.currentRoute.value.query).toEqual({ registrado: '1', redirect: '/agenda' })
    expect((wrapper.get('input[name="password"]').element as HTMLInputElement).value).toBe('')
  })

  it('a network error shows the general notice, keeps what was typed and can retry', async () => {
    let online = false
    mockFetch(() => {
      if (!online) throw new TypeError('offline')
      return jsonResponse(201, { data: { id: '1' } })
    })
    const { wrapper, router } = await mountView(RegisterView, '/registro')
    await fillText(wrapper)
    await check(wrapper)

    await submit(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos crear su cuenta.')
    expect(wrapper.get('[role="alert"]').text()).toContain('Lo que escribió se mantiene.')
    expect((wrapper.get('input[name="email"]').element as HTMLInputElement).value).toBe(
      'laura@example.test',
    )

    online = true
    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()
    await vi.waitFor(() => expect(router.currentRoute.value.path).toBe('/ingresar'))
  })

  it('while sending the button is busy and the checkboxes are disabled', async () => {
    let release!: () => void
    mockFetch(
      () =>
        new Promise<Response>((resolve) => {
          release = () => resolve(jsonResponse(201, { data: {} }))
        }),
    )
    const { wrapper } = await mountView(RegisterView, '/registro')
    await fillText(wrapper)
    await check(wrapper)

    await wrapper.get('form').trigger('submit')

    expect(wrapper.get('button[type="submit"]').attributes('aria-busy')).toBe('true')
    expect(wrapper.get('main').attributes('aria-busy')).toBe('true')
    for (const box of wrapper.findAll('[role="checkbox"]')) {
      expect(box.attributes('disabled')).toBeDefined()
    }
    release()
    await flushPromises()
  })
})
