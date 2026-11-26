import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { signIn } from '../helpers/account'
import { jsonResponse, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import {
  BEARD,
  CLASSIC,
  FADE,
  OLD_CUT,
  voucher,
  voucherDefinitionsApi,
} from '../helpers/voucherDefinitions'

beforeEach(() => signIn('marco@example.test'))
afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

const VOUCHER_PATH = voucher().id

async function open(path: string, options: Parameters<typeof voucherDefinitionsApi>[0] = {}) {
  const api = voucherDefinitionsApi(options)
  const mounted = await mountView(App, `/barberias/${api.shopId}/bonos${path}`)
  await flushPromises()
  return { ...mounted, ...api }
}

type Wrapper = Awaited<ReturnType<typeof open>>['wrapper']

const textInputs = (wrapper: Wrapper) => wrapper.findAll('form input')
/** The checkbox of a covered service by its name. */
const checkbox = (wrapper: Wrapper, name: string) =>
  wrapper.findAll('form [role="checkbox"]').find(
    (box) =>
      box.attributes('aria-labelledby') !== undefined &&
      wrapper
        .get(`#${box.attributes('aria-labelledby')}`)
        .text()
        .startsWith(name),
  )!
const submit = async (wrapper: Wrapper) => {
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

async function fill(
  wrapper: Wrapper,
  values: { name?: string; sessions?: string; validity?: string; price?: string },
) {
  const [name, sessions, validity, price] = textInputs(wrapper)
  if (values.name !== undefined) await name!.setValue(values.name)
  if (values.sessions !== undefined) await sessions!.setValue(values.sessions)
  if (values.validity !== undefined) await validity!.setValue(values.validity)
  if (values.price !== undefined) await price!.setValue(values.price)
}

describe('VoucherDefinitionFormView: new', () => {
  it('shows the fields of the sheet and the services of the catalog with duration and price', async () => {
    const { wrapper } = await open('/nuevo', {
      services: [CLASSIC, FADE, OLD_CUT],
    })

    expect(wrapper.get('h1').text()).toBe('Nuevo bono')
    expect(wrapper.text()).toContain('Volver a bonos')
    const labels = wrapper.findAll('form label').map((label) => label.text())
    expect(labels).toEqual([
      'Nombre del bono',
      'Sesiones',
      'Vigencia (días)',
      'Precio (₡, IVA incluido)',
    ])
    expect(wrapper.text()).toContain('Cuántas visitas incluye.')
    expect(wrapper.text()).toContain('Cuenta desde que el cliente paga.')
    expect(wrapper.text()).toContain('En colones enteros.')
    expect(wrapper.text()).not.toContain('Cambiar el precio no cambia')
    const group = wrapper.get('fieldset')
    expect(group.get('legend').text()).toBe('Servicios cubiertos')
    // Only the active services are offered.
    expect(group.findAll('[role="checkbox"]')).toHaveLength(2)
    expect(group.text()).toContain('Corte clásico')
    expect(group.text()).toContain('30 min · ₡7.000')
    expect(group.text()).toContain('Corte degradado')
    expect(group.text()).not.toContain('Corte antiguo')
    expect(wrapper.text()).toContain('Guardar bono')
    expect(wrapper.text()).toContain('Cancelar')
  })

  it('creates the voucher with the numbers and the services chosen', async () => {
    const { wrapper, bodies, calls } = await open('/nuevo', { vouchers: [] })
    await fill(wrapper, { name: ' Bono 5 cortes ', sessions: '5', validity: '90', price: '32000' })
    await checkbox(wrapper, 'Corte clásico').trigger('click')
    await checkbox(wrapper, 'Corte degradado').trigger('click')
    await submit(wrapper)

    expect(bodies).toEqual([
      {
        nombre: 'Bono 5 cortes',
        sesiones: 5,
        precio: 32000,
        vigencia_dias: 90,
        servicios: [CLASSIC.id, FADE.id],
      },
    ])
    expect(calls.filter((c) => c.startsWith('POST'))).toHaveLength(1)
    expect(wrapper.get('[role="status"]').text()).toContain('Bono guardado. Ya está a la venta.')
  })

  it('refuses a voucher without sessions or without services, and sends nothing', async () => {
    const { wrapper, bodies } = await open('/nuevo', { vouchers: [] })
    await fill(wrapper, { name: 'Bono', sessions: '0', validity: '90', price: '32000' })
    await submit(wrapper)

    const sessions = wrapper.findAll('form input')[1]!
    expect(sessions.attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('Las sesiones deben ser al menos 1.')
    expect(wrapper.get('[data-testid="services-error"]').text()).toBe(
      'Elija al menos un servicio cubierto.',
    )
    expect(bodies).toEqual([])
    // The focus goes to the first field with a problem.
    expect(document.activeElement).toBe(sessions.element)
  })

  it('asks for the name, the validity and the price too', async () => {
    const { wrapper, bodies } = await open('/nuevo', { vouchers: [] })
    await submit(wrapper)

    expect(wrapper.text()).toContain('Escriba el nombre del bono.')
    expect(wrapper.text()).toContain('La vigencia debe ser de al menos 1 día.')
    expect(wrapper.text()).toContain('El precio debe ser mayor que ₡0, en colones enteros.')
    expect(bodies).toEqual([])
  })

  it('shows the 422 of the API in its field', async () => {
    const { wrapper } = await open('/nuevo', {
      vouchers: [],
      override: (r) =>
        r.method === 'POST'
          ? problem(422, {
              errores: {
                nombre: ['El nombre ya existe.'],
                precio: ['El precio es demasiado alto.'],
                vigencia_dias: ['La vigencia no es válida.'],
              },
            })
          : undefined,
    })
    await fill(wrapper, { name: 'Bono', sessions: '5', validity: '90', price: '32000' })
    await checkbox(wrapper, 'Barba').trigger('click')
    await submit(wrapper)

    expect(wrapper.text()).toContain('El nombre ya existe.')
    expect(wrapper.text()).toContain('El precio es demasiado alto.')
    expect(wrapper.text()).toContain('La vigencia no es válida.')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('shows a 422 of one service (`servicios.N`) under the services', async () => {
    const { wrapper } = await open('/nuevo', {
      vouchers: [],
      override: (r) =>
        r.method === 'POST'
          ? problem(422, { errores: { 'servicios.0': ['El servicio no existe en la barbería.'] } })
          : undefined,
    })
    await fill(wrapper, { name: 'Bono', sessions: '5', validity: '90', price: '32000' })
    await checkbox(wrapper, 'Barba').trigger('click')
    await submit(wrapper)

    expect(wrapper.get('[data-testid="services-error"]').text()).toBe(
      'El servicio no existe en la barbería.',
    )
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('with the vouchers switched off the API answers 409 and the form shows it', async () => {
    const { wrapper } = await open('/nuevo', {
      vouchers: [],
      override: (r) =>
        r.method === 'POST'
          ? problem(409, { type: 'conflicto', detail: 'Los bonos están desactivados.' })
          : undefined,
    })
    await fill(wrapper, { name: 'Bono', sessions: '5', validity: '90', price: '32000' })
    await checkbox(wrapper, 'Barba').trigger('click')
    await submit(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain('Los bonos están desactivados.')
  })

  it('only digits are accepted in the numbers', async () => {
    const { wrapper } = await open('/nuevo', { vouchers: [] })
    const [, sessions] = textInputs(wrapper)
    await sessions!.setValue('1a2')
    expect((sessions!.element as HTMLInputElement).value).toBe('12')
  })

  it('offers a way to create a service when there is none', async () => {
    const { wrapper } = await open('/nuevo', { vouchers: [], services: [OLD_CUT] })
    expect(wrapper.get('fieldset').text()).toContain('Aún no tiene servicios activos.')
    expect(wrapper.get('fieldset a').attributes('href')).toContain('/servicios')
  })

  it('the checkboxes and fields are reachable with the keyboard and have a label', async () => {
    const { wrapper } = await open('/nuevo', { vouchers: [] })
    for (const input of textInputs(wrapper)) {
      expect(wrapper.find(`label[for="${input.attributes('id')}"]`).exists()).toBe(true)
    }
    for (const box of wrapper.findAll('form [role="checkbox"]')) {
      expect(box.element.tagName).toBe('BUTTON')
      expect(box.attributes('aria-labelledby')).toBeTruthy()
    }
  })
})

describe('VoucherDefinitionFormView: edit', () => {
  it('fills the voucher and says a change of price does not touch the vouchers already bought', async () => {
    const { wrapper } = await open(`/${VOUCHER_PATH}`)

    expect(wrapper.get('h1').text()).toBe('Editar bono')
    const [name, sessions, validity, price] = textInputs(wrapper)
    expect((name!.element as HTMLInputElement).value).toBe('Bono 5 cortes')
    expect((sessions!.element as HTMLInputElement).value).toBe('5')
    expect((validity!.element as HTMLInputElement).value).toBe('90')
    expect((price!.element as HTMLInputElement).value).toBe('32000')
    expect(checkbox(wrapper, 'Corte clásico').attributes('aria-checked')).toBe('true')
    expect(checkbox(wrapper, 'Corte degradado').attributes('aria-checked')).toBe('true')
    expect(checkbox(wrapper, 'Barba').attributes('aria-checked')).toBe('false')
    expect(wrapper.text()).toContain('Cambiar el precio no cambia los bonos ya comprados.')
    expect(wrapper.find('[role="note"]').exists()).toBe(false)
  })

  it('sends only what changed and says the change applies to new vouchers', async () => {
    const { wrapper, bodies } = await open(`/${VOUCHER_PATH}`)
    await fill(wrapper, { price: '35000' })
    await submit(wrapper)

    expect(bodies).toEqual([{ precio: 35000 }])
    expect(wrapper.get('[role="status"]').text()).toContain('El cambio aplica a bonos nuevos')
    expect(wrapper.get('[role="status"]').text()).toContain('Ya está a la venta.')
  })

  it('changing the covered services sends the full list', async () => {
    const { wrapper, bodies } = await open(`/${VOUCHER_PATH}`)
    await checkbox(wrapper, 'Corte degradado').trigger('click')
    await checkbox(wrapper, 'Barba').trigger('click')
    await submit(wrapper)

    expect(bodies).toEqual([{ servicios: [CLASSIC.id, BEARD.id] }])
  })

  it('a withdrawn voucher is saved without saying it is on sale', async () => {
    const { wrapper } = await open(`/${VOUCHER_PATH}`, { vouchers: [voucher({ activo: false })] })
    await fill(wrapper, { price: '35000' })
    await submit(wrapper)

    expect(wrapper.get('[role="status"]').text()).not.toContain('Ya está a la venta.')
  })

  it('still shows an inactive service the voucher already covers', async () => {
    const { wrapper } = await open(`/${VOUCHER_PATH}`, {
      vouchers: [voucher({ servicios: [CLASSIC.id, OLD_CUT.id] })],
      services: [CLASSIC, FADE, OLD_CUT],
    })
    expect(checkbox(wrapper, 'Corte antiguo').attributes('aria-checked')).toBe('true')
  })

  it('a voucher with sales locks sessions, validity and services and says so', async () => {
    const { wrapper, bodies } = await open(`/${VOUCHER_PATH}`, {
      vouchers: [voucher({ tiene_ventas: true })],
    })

    const note = wrapper.get('[role="note"]')
    expect(note.text()).toContain(
      'Este bono ya tiene ventas: solo puede cambiar el nombre y el precio.',
    )
    expect(note.text()).toContain(
      'Las sesiones, la vigencia y los servicios cubiertos ya no se pueden modificar.',
    )
    const [name, sessions, validity, price] = textInputs(wrapper)
    expect(name!.attributes('disabled')).toBeUndefined()
    expect(price!.attributes('disabled')).toBeUndefined()
    expect(sessions!.attributes('disabled')).toBeDefined()
    expect(validity!.attributes('disabled')).toBeDefined()
    for (const box of wrapper.findAll('form [role="checkbox"]')) {
      expect(box.attributes('disabled')).toBeDefined()
    }
    expect(wrapper.text()).toContain('Cambiar el precio no cambia los bonos ya comprados.')

    await fill(wrapper, { name: 'Bono cinco cortes', price: '33000' })
    await submit(wrapper)
    expect(bodies).toEqual([{ nombre: 'Bono cinco cortes', precio: 33000 }])
  })

  it('a sale that lands while the form is open locks the terms after the 409', async () => {
    let sold = false
    const { wrapper } = await open(`/${VOUCHER_PATH}`, {
      override: (r) => {
        if (r.method === 'PATCH') {
          sold = true
          return problem(409, {
            type: 'bono-con-ventas',
            detail: 'Este bono ya tiene ventas.',
          })
        }
        if (sold && r.method === 'GET' && r.url.endsWith('/administracion/bonos')) {
          return jsonResponse(200, { data: [voucher({ tiene_ventas: true })] })
        }
        return undefined
      },
    })
    await fill(wrapper, { sessions: '10' })
    await submit(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain('Este bono ya tiene ventas.')
    expect(wrapper.find('[role="note"]').exists()).toBe(true)
    expect(wrapper.findAll('form input')[1]!.attributes('disabled')).toBeDefined()
  })

  it('shows «No encontramos el bono» for an id that is not in the shop', async () => {
    const { wrapper } = await open('/0190b1f0-0000-7000-8000-0000000000ff')
    expect(wrapper.get('[role="alert"]').text()).toContain('No encontramos el bono')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('shows the skeleton while it loads', async () => {
    const api = voucherDefinitionsApi({
      override: (r) => (r.url.endsWith('/administracion/bonos') ? new Response() : undefined),
    })
    const { wrapper } = await mountView(App, `/barberias/${api.shopId}/bonos/${VOUCHER_PATH}`)
    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true)
  })
})
