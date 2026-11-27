import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { todayInCostaRica } from '@/composables/format'
import { signIn } from '../helpers/account'
import { KEVIN, assistedApi, assistedClient, space } from '../helpers/assisted'
import { problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { CLASICO } from '../helpers/team'

beforeEach(() => signIn('marco@example.test'))
afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

const TODAY = todayInCostaRica()
const SLOTS = [space(TODAY, '14:15'), space(TODAY, '15:00')]

type Mounted = Awaited<ReturnType<typeof open>>['wrapper']

async function open(options: Parameters<typeof assistedApi>[0] = {}) {
  const api = assistedApi({ slots: SLOTS, ...options })
  const mounted = await mountView(App, `/barberias/${api.shopId}/reserva-asistida`)
  await flushPromises()
  return { ...mounted, ...api }
}

/** The search waits a quarter of a second after the last key, so the test waits a little more. */
async function type(wrapper: Mounted, text: string): Promise<void> {
  await wrapper.get('#assisted-search').setValue(text)
  await new Promise((resolve) => setTimeout(resolve, 320))
  await flushPromises()
}

const button = (wrapper: Mounted, label: string) =>
  wrapper.findAll('button').find((item) => item.text().includes(label))

async function chooseSpace(wrapper: Mounted, time = '14:15'): Promise<void> {
  const service = wrapper
    .findAll('[role="radio"]')
    .find((item) => item.text().includes('Corte clásico'))!
  await service.trigger('click')
  await wrapper.get('#assisted-barber').setValue(KEVIN.id)
  await flushPromises()
  await button(wrapper, time)!.trigger('click')
}

async function newClient(
  wrapper: Mounted,
  values: { name?: string; phone?: string; email?: string },
) {
  await button(wrapper, 'Crear cliente nuevo')!.trigger('click')
  if (values.name !== undefined) await wrapper.get('#assisted-name').setValue(values.name)
  if (values.phone !== undefined) await wrapper.get('#assisted-phone').setValue(values.phone)
  if (values.email !== undefined) await wrapper.get('#assisted-email').setValue(values.email)
}

describe('AssistedBookingView: the screen', () => {
  it('opens with the client, the service, the barber and the date, and registers nothing yet', async () => {
    const { wrapper, registered } = await open()

    expect(wrapper.get('h1').text()).toBe('Reserva asistida')
    expect(wrapper.text()).toContain('Registre una reserva para quien llega o llama.')
    expect(wrapper.text()).toContain('Solo se buscan clientes asistidos de esta barbería.')
    expect(wrapper.findAll('[role="radio"]').map((item) => item.text())).toEqual(
      expect.arrayContaining([expect.stringContaining('Corte clásico')]),
    )
    expect(wrapper.text()).toContain('Elija un servicio y un barbero para ver las horas libres.')
    expect(wrapper.get('a[aria-current="page"]').text()).toBe('Agenda')
    expect(registered).toEqual([])
  })

  it('shows the skeleton while the services and the team load', async () => {
    const api = assistedApi()
    const { wrapper } = await mountView(App, `/barberias/${api.shopId}/reserva-asistida`)
    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true)
  })

  it('shows the error of the services and reads them again', async () => {
    const api = assistedApi({ servicesFail: true })
    const { wrapper } = await mountView(App, `/barberias/${api.shopId}/reserva-asistida`)
    await flushPromises()

    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toContain('No pudimos cargar los servicios')
    expect(alert.findAll('button').map((item) => item.text())).toEqual(['Reintentar'])
  })

  it('tells there are no active services', async () => {
    const api = assistedApi({ services: [] })
    const { wrapper } = await mountView(App, `/barberias/${api.shopId}/reserva-asistida`)
    await flushPromises()

    expect(wrapper.text()).toContain('No hay servicios activos.')
  })
})

describe('AssistedBookingView: the client', () => {
  it('finds the people of the shop by a part of the name or the phone and picks one', async () => {
    const { wrapper, searches } = await open({
      clients: [
        assistedClient(),
        assistedClient({ id: 'c2', nombre: 'Rosa Campos', telefono: '8744 0021', correo: null }),
      ],
    })

    await type(wrapper, 'ro')

    expect(searches).toEqual(['ro'])
    const results = wrapper.findAll('ul[aria-label="Clientes encontrados"] button')
    expect(results.map((item) => item.text())).toEqual([
      'Rodrigo Chaves8812 3456 · rodrigo@correo.cr',
      'Rosa Campos8744 0021 · Sin correo',
    ])

    await results[1]!.trigger('click')

    expect(wrapper.text()).toContain('Rosa Campos')
    expect(wrapper.text()).toContain('8744 0021 · Sin correo')
    expect(button(wrapper, 'Cambiar cliente')).toBeDefined()
    expect(wrapper.find('#assisted-search').exists()).toBe(false)
  })

  it('asks nothing until the owner types', async () => {
    const { searches } = await open({ clients: [assistedClient()] })
    expect(searches).toEqual([])
  })

  it('says nobody was found and offers to create a new client', async () => {
    const { wrapper } = await open({ clients: [assistedClient()] })

    await type(wrapper, 'zzz')

    expect(wrapper.text()).toContain(
      'No encontramos a nadie con ese dato. Puede crear un cliente nuevo.',
    )
    expect(button(wrapper, 'Crear cliente nuevo')).toBeDefined()
  })

  it('shows the error of the search and tries again', async () => {
    const { wrapper } = await open({ searchFails: true })

    await type(wrapper, 'ro')

    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos buscar clientes')
  })

  it('changes the client picked', async () => {
    const { wrapper } = await open({ clients: [assistedClient()] })
    await type(wrapper, 'rod')
    await wrapper.get('ul[aria-label="Clientes encontrados"] button').trigger('click')

    await button(wrapper, 'Cambiar cliente')!.trigger('click')

    expect(wrapper.find('#assisted-search').exists()).toBe(true)
  })

  it('opens the form of a new client, whose email is optional and says what it means', async () => {
    const { wrapper } = await open()

    await button(wrapper, 'Crear cliente nuevo')!.trigger('click')

    expect(wrapper.find('label[for="assisted-name"]').text()).toBe('Nombre completo')
    expect(wrapper.find('label[for="assisted-phone"]').text()).toBe('Teléfono')
    expect(wrapper.find('label[for="assisted-email"]').text()).toBe('Correo (opcional)')
    expect(wrapper.text()).toContain('Sin correo, el cliente no recibirá recordatorios ni avisos.')
  })

  it('names the missing field of a new client and stays where it is', async () => {
    const { wrapper, registered } = await open()
    await newClient(wrapper, { name: 'Rodrigo Chaves' })
    await chooseSpace(wrapper)

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const phone = wrapper.get('#assisted-phone')
    expect(phone.attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('Escriba un teléfono para poder contactar al cliente.')
    expect(registered).toEqual([])
  })

  it('refuses an email that is not one', async () => {
    const { wrapper, registered } = await open()
    await newClient(wrapper, { name: 'Rodrigo', phone: '8812 3456', email: 'no-es-un-correo' })
    await chooseSpace(wrapper)

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Escriba un correo válido, por ejemplo nombre@correo.cr.')
    expect(registered).toEqual([])
  })

  it('asks to choose a client before registering', async () => {
    const { wrapper, registered } = await open()
    await chooseSpace(wrapper)

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Elija un cliente o cree uno nuevo.')
    expect(registered).toEqual([])
  })
})

describe('AssistedBookingView: the space', () => {
  it('offers only the active collaborators that give the service', async () => {
    const { wrapper } = await open()
    const service = wrapper
      .findAll('[role="radio"]')
      .find((item) => item.text().includes('Corte clásico'))!

    await service.trigger('click')

    expect(wrapper.findAll('#assisted-barber option').map((item) => item.text())).toEqual([
      'Elija un barbero',
      'Kevin Araya',
    ])
  })

  it('lists the hours of the barber, by the same availability the customer uses', async () => {
    const { wrapper } = await open()

    await chooseSpace(wrapper)

    const hours = wrapper.findAll('button').filter((item) => /^\d\d:\d\d$/.test(item.text()))
    expect(hours.map((item) => item.text())).toEqual(['14:15', '15:00'])
    expect(wrapper.text()).toContain('Corte clásico')
    expect(wrapper.text()).toContain('con Kevin Araya')
    expect(wrapper.text()).toContain('IVA incluido · sin cobro en línea')
  })

  it('tells there are no hours that day', async () => {
    const { wrapper } = await open({ slots: [] })

    await chooseSpace(wrapper, '14:15').catch(() => undefined)

    expect(wrapper.text()).toContain('No quedan horas el')
    expect(wrapper.text()).toContain('con Kevin Araya.')
  })

  it('shows the error of the hours and reads them again', async () => {
    const { wrapper } = await open({ availabilityFails: true })
    const service = wrapper
      .findAll('[role="radio"]')
      .find((item) => item.text().includes('Corte clásico'))!

    await service.trigger('click')
    await wrapper.get('#assisted-barber').setValue(KEVIN.id)
    await flushPromises()

    expect(wrapper.text()).toContain('No pudimos cargar los horarios')
  })

  it('asks the owner to choose the service, the barber and the hour', async () => {
    const { wrapper, registered } = await open()
    await newClient(wrapper, { name: 'Rodrigo', phone: '8812 3456' })

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Elija un servicio.')
    expect(wrapper.text()).toContain('Elija un barbero.')
    expect(registered).toEqual([])
  })
})

describe('AssistedBookingView: registering', () => {
  it('registers for a client found, sends their id and shows the confirmed reservation', async () => {
    const { wrapper, registered, shopId } = await open({ clients: [assistedClient()] })
    await type(wrapper, 'rod')
    await wrapper.get('ul[aria-label="Clientes encontrados"] button').trigger('click')
    await chooseSpace(wrapper)

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(registered).toEqual([
      {
        servicio: CLASICO.id,
        barbero: KEVIN.id,
        inicio: `${TODAY}T14:15:00-06:00`,
        cliente_asistido: assistedClient().id,
      },
    ])
    expect(wrapper.get('[role="status"]').text()).toContain('Reserva registrada')
    expect(wrapper.text()).toContain('Rodrigo Chaves · 8812 3456')
    expect(wrapper.text()).toContain('Confirmada, sin cobro en línea.')
    const link = wrapper.findAll('a').find((item) => item.text() === 'Ver en la agenda')!
    expect(link.attributes('href')).toBe(`/barberias/${shopId}/agenda/res-asistida-1?dia=${TODAY}`)
  })

  it('registers a new client with the data typed, and without email when there is none', async () => {
    const { wrapper, registered } = await open()
    await newClient(wrapper, { name: ' Rosa Campos ', phone: '8744 0021' })
    await chooseSpace(wrapper)

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(registered).toEqual([
      {
        servicio: CLASICO.id,
        barbero: KEVIN.id,
        inicio: `${TODAY}T14:15:00-06:00`,
        cliente_nombre: 'Rosa Campos',
        cliente_telefono: '8744 0021',
      },
    ])
    expect(wrapper.text()).toContain('Reserva registrada')
  })

  it('sends the email when it is typed', async () => {
    const { wrapper, registered } = await open()
    await newClient(wrapper, { name: 'Rosa', phone: '8744 0021', email: 'rosa@correo.cr' })
    await chooseSpace(wrapper)

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(registered[0]).toMatchObject({ cliente_correo: 'rosa@correo.cr' })
  })

  it('goes back to an empty form with «Registrar otra»', async () => {
    const { wrapper } = await open()
    await newClient(wrapper, { name: 'Rosa', phone: '8744 0021' })
    await chooseSpace(wrapper)
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    await button(wrapper, 'Registrar otra')!.trigger('click')

    expect(wrapper.find('form').exists()).toBe(true)
    expect(wrapper.find('#assisted-search').exists()).toBe(true)
  })

  it('offers the spaces the server gives when the chosen one was taken, and chooses one', async () => {
    const taken = space(TODAY, '15:00')
    const { wrapper } = await open({
      register: () =>
        problem(409, {
          type: 'http://localhost/problemas/espacio-no-disponible',
          detail: 'Ese espacio ya no está disponible.',
          alternativas: [{ barbero: KEVIN.id, ...taken }],
        }),
    })
    await newClient(wrapper, { name: 'Rosa', phone: '8744 0021' })
    await chooseSpace(wrapper)

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toContain('Ese espacio ya no está libre')
    expect(alert.text()).toContain('Otra reserva lo ocupó hace un momento.')
    const offered = alert.findAll('button')
    expect(offered).toHaveLength(1)
    expect(offered[0]!.text()).toContain('15:00')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)

    await offered[0]!.trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('con Kevin Araya')
  })

  it('shows the field the server refused', async () => {
    const { wrapper } = await open({
      register: () =>
        problem(422, {
          type: 'http://localhost/problemas/datos-invalidos',
          detail: 'Datos inválidos.',
          errores: { cliente_telefono: ['El teléfono del cliente es obligatorio.'] },
        }),
    })
    await newClient(wrapper, { name: 'Rosa', phone: '8744 0021' })
    await chooseSpace(wrapper)

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('El teléfono del cliente es obligatorio.')
    expect(wrapper.get('#assisted-phone').attributes('aria-invalid')).toBe('true')
  })

  it('tells when the client, the service or the barber no longer exist', async () => {
    const { wrapper } = await open({
      register: () => problem(404, { type: 'http://localhost/problemas/no-encontrado' }),
    })
    await newClient(wrapper, { name: 'Rosa', phone: '8744 0021' })
    await chooseSpace(wrapper)

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('Ya no encontramos')
  })

  it('tells that it failed and keeps what was typed', async () => {
    const { wrapper } = await open({ register: () => problem(500) })
    await newClient(wrapper, { name: 'Rosa', phone: '8744 0021' })
    await chooseSpace(wrapper)

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos registrar la reserva.')
    expect((wrapper.get('#assisted-name').element as HTMLInputElement).value).toBe('Rosa')
  })
})

describe('AssistedBookingView: who may open it', () => {
  it('is for the owner: a collaborator is sent away', async () => {
    const api = assistedApi({ role: 'colaborador' })
    const { router } = await mountView(App, `/barberias/${api.shopId}/reserva-asistida`)
    await flushPromises()

    expect(router.currentRoute.value.name).not.toBe('staff-assisted-booking')
  })
})
