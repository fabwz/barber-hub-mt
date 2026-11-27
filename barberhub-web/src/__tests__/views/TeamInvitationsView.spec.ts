import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { signIn } from '../helpers/account'
import { problem } from '../helpers/http'
import { invitation, invitationsApi } from '../helpers/invitations'
import { mountView } from '../helpers/mountView'

beforeEach(() => signIn('marco@example.test'))
afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

const LUCIA = invitation({ id: 'lucia-id', correo: 'lucia@correo.cr' })
const ANDRES = invitation({
  id: 'andres-id',
  correo: 'andres@correo.cr',
  enviada: '2026-11-02T16:40:00-06:00',
  vence: '2026-11-09T16:40:00-06:00',
})

const isList = (r: Request) => r.method === 'GET' && r.url.endsWith('/administracion/invitaciones')

async function open(options: Parameters<typeof invitationsApi>[0] = {}) {
  const api = invitationsApi(options)
  const mounted = await mountView(App, `/barberias/${api.shopId}/equipo/invitaciones`)
  await flushPromises()
  return { ...mounted, ...api }
}

type Opened = Awaited<ReturnType<typeof open>>['wrapper']

const emailInput = (wrapper: Opened) => wrapper.get('input[name="email"]')

async function send(wrapper: Opened, email: string, services: string[] = []) {
  await emailInput(wrapper).setValue(email)
  for (const name of services) {
    const row = wrapper.findAll('fieldset li').find((item) => item.text().includes(name))!
    await row.get('[role="checkbox"], button').trigger('click')
  }
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

function pressInDialog(text: string): void {
  const dialog = document.body.querySelector('[role="alertdialog"]')!
  Array.from(dialog.querySelectorAll('button'))
    .find((b) => b.textContent?.includes(text))!
    .click()
}

describe('TeamInvitationsView: the section', () => {
  it('is a link of «Equipo» between «Colaboradores» and the schedule, and it is the current one', async () => {
    const { wrapper } = await open()

    const links = wrapper.findAll('nav[aria-label="Secciones de Equipo"] a')
    expect(links.map((l) => l.text())).toEqual([
      'Colaboradores',
      'Invitaciones',
      'Horario de la barbería',
    ])
    expect(links[1]!.attributes('aria-current')).toBe('page')
    expect(wrapper.get('h2').text()).toBe('Invitar por correo')
    expect(wrapper.text()).toContain('El enlace es válido por 7 días y de un solo uso.')
    expect(wrapper.text()).toContain('Recibirá un enlace para crear su propia contraseña.')
  })

  it('lists the services to choose, with duration and price', async () => {
    const { wrapper } = await open()

    const rows = wrapper.findAll('fieldset li')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('Corte clásico')
    expect(rows[1]!.text()).toContain('Rapado')
    expect(rows[1]!.text()).toContain('20 min')
  })
})

describe('TeamInvitationsView: the pending ones', () => {
  it('shows the email, when it was sent and when it stops working, and offers to cancel each one', async () => {
    const { wrapper } = await open({ invitations: [LUCIA, ANDRES] })

    const rows = wrapper.findAll('ul[aria-label="Invitaciones pendientes"] > li')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('lucia@correo.cr')
    expect(rows[0]!.text()).toContain('Enviada jue 5 nov 2026, 09:14')
    expect(rows[0]!.text()).toContain('jue 12 nov 2026, 09:14')
    expect(rows[1]!.text()).toContain('lun 2 nov 2026, 16:40')
    expect(rows[0]!.get('button').attributes('aria-label')).toBe(
      'Cancelar la invitación a lucia@correo.cr',
    )
    expect(rows[0]!.get('button').text()).toBe('Cancelar invitación')
  })

  it('shows the skeleton while it loads', async () => {
    const api = invitationsApi({ override: (r) => (isList(r) ? new Response() : undefined) })
    const { wrapper } = await mountView(App, `/barberias/${api.shopId}/equipo/invitaciones`)

    expect(wrapper.text()).toContain('Cargando las invitaciones')
    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true)
  })

  it('shows the empty state when nobody was invited', async () => {
    const { wrapper } = await open()

    expect(wrapper.text()).toContain('No hay invitaciones pendientes.')
    expect(wrapper.text()).toContain(
      'Cuando invite a alguien, aparecerá aquí hasta que cree su contraseña.',
    )
  })

  it('shows the error and tries again', async () => {
    let fail = true
    const { wrapper } = await open({
      override: (r) => (fail && isList(r) ? problem(500) : undefined),
    })

    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar las invitaciones')
    fail = false
    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('No hay invitaciones pendientes.')
  })
})

describe('TeamInvitationsView: inviting', () => {
  it('sends the email with the chosen services, says when it expires and clears the form', async () => {
    const { wrapper, bodies } = await open()

    await send(wrapper, '  Lucia@Correo.cr ', ['Rapado'])

    expect(bodies).toEqual([
      {
        route: expect.stringContaining('POST'),
        body: { correo: 'Lucia@Correo.cr', servicios: ['svc-rapado'] },
      },
    ])
    expect(wrapper.get('[role="status"]').text()).toBe(
      'Invitación enviada a lucia@correo.cr. Vence en 7 días.',
    )
    expect((emailInput(wrapper).element as HTMLInputElement).value).toBe('')
    expect(wrapper.findAll('ul[aria-label="Invitaciones pendientes"] > li')).toHaveLength(1)
    expect(wrapper.text()).not.toContain('No hay invitaciones pendientes.')
  })

  it('inviting an email again replaces its pending invitation in the list', async () => {
    const { wrapper } = await open({ invitations: [LUCIA, ANDRES] })

    await send(wrapper, 'lucia@correo.cr')

    const rows = wrapper.findAll('ul[aria-label="Invitaciones pendientes"] > li')
    expect(rows.map((row) => row.get('p').text())).toEqual(['lucia@correo.cr', 'andres@correo.cr'])
  })

  it('does not call the API with an email that is not valid, and says how it looks', async () => {
    const { wrapper, bodies } = await open()

    await send(wrapper, 'no-es-un-correo')

    expect(wrapper.text()).toContain('Escriba un correo válido, como nombre@correo.cr.')
    expect(emailInput(wrapper).attributes('aria-invalid')).toBe('true')
    expect(bodies).toEqual([])
  })

  it('shows the refusal of the API on the field', async () => {
    const { wrapper } = await open({
      override: (r) =>
        r.method === 'POST' && r.url.endsWith('/invitaciones')
          ? problem(422, {
              type: 'datos-invalidos',
              errores: { correo: ['El correo no es válido.'] },
            })
          : undefined,
    })

    await send(wrapper, 'lucia@correo.cr')

    expect(wrapper.text()).toContain('El correo no es válido.')
    expect(emailInput(wrapper).attributes('aria-invalid')).toBe('true')
  })

  it('shows a service the barbershop does not have under the list of services', async () => {
    const { wrapper } = await open({
      override: (r) =>
        r.method === 'POST' && r.url.endsWith('/invitaciones')
          ? problem(422, {
              type: 'datos-invalidos',
              errores: { 'servicios.0': ['El servicio no existe en la barbería.'] },
            })
          : undefined,
    })

    await send(wrapper, 'lucia@correo.cr', ['Rapado'])

    expect(wrapper.get('fieldset').text()).toContain('El servicio no existe en la barbería.')
  })

  it('keeps what was written and offers to try again when the network fails', async () => {
    let offline = true
    const { wrapper } = await open({
      override: (r) => {
        if (offline && r.method === 'POST') throw new TypeError('Failed to fetch')
        return undefined
      },
    })

    await send(wrapper, 'lucia@correo.cr')

    expect(wrapper.text()).toContain('No pudimos comunicarnos con el servidor.')
    expect((emailInput(wrapper).element as HTMLInputElement).value).toBe('lucia@correo.cr')

    offline = false
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Reintentar')!
      .trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toContain('Invitación enviada a lucia@correo.cr.')
  })

  it('works without services when they cannot be loaded, and says so', async () => {
    const { wrapper } = await open({
      override: (r) => (r.url.endsWith('/administracion/servicios') ? problem(500) : undefined),
    })

    expect(wrapper.get('fieldset').text()).toContain('No pudimos cargar los servicios')
    await send(wrapper, 'lucia@correo.cr')
    expect(wrapper.get('[role="status"]').text()).toContain('Invitación enviada')
  })
})

describe('TeamInvitationsView: cancelling', () => {
  it('asks first, and keeping it changes nothing', async () => {
    const { wrapper, calls } = await open({ invitations: [LUCIA] })

    await wrapper
      .get('button[aria-label="Cancelar la invitación a lucia@correo.cr"]')
      .trigger('click')
    await flushPromises()

    const dialog = document.body.querySelector('[role="alertdialog"]')!
    expect(dialog.textContent).toContain('¿Cancelar la invitación a lucia@correo.cr?')
    expect(dialog.textContent).toContain('El enlace dejará de funcionar.')
    pressInDialog('No, mantenerla')
    await flushPromises()

    expect(calls.some((call) => call.startsWith('DELETE'))).toBe(false)
    expect(wrapper.findAll('ul[aria-label="Invitaciones pendientes"] > li')).toHaveLength(1)
  })

  it('cancels it, says the link stopped working and takes it out of the list', async () => {
    const { wrapper, calls } = await open({ invitations: [LUCIA, ANDRES] })

    await wrapper
      .get('button[aria-label="Cancelar la invitación a lucia@correo.cr"]')
      .trigger('click')
    await flushPromises()
    pressInDialog('Sí, cancelar invitación')
    await flushPromises()

    expect(calls.filter((call) => call.startsWith('DELETE'))).toEqual([
      expect.stringContaining('/invitaciones/lucia-id'),
    ])
    expect(wrapper.get('[role="status"]').text()).toBe(
      'Invitación cancelada. El enlace dejó de funcionar.',
    )
    expect(wrapper.findAll('ul[aria-label="Invitaciones pendientes"] > li')).toHaveLength(1)
    expect(wrapper.text()).not.toContain('lucia@correo.cr')
  })

  it('reads the list again when the invitation was gone already (404)', async () => {
    const api = invitationsApi({ invitations: [LUCIA, ANDRES] })
    const { wrapper } = await mountView(App, `/barberias/${api.shopId}/equipo/invitaciones`)
    await flushPromises()
    api.current().splice(0, 1)

    await wrapper
      .get('button[aria-label="Cancelar la invitación a lucia@correo.cr"]')
      .trigger('click')
    await flushPromises()
    pressInDialog('Sí, cancelar invitación')
    await flushPromises()

    expect(wrapper.text()).not.toContain('lucia@correo.cr')
    expect(wrapper.text()).toContain('andres@correo.cr')
  })

  it('says it could not cancel when the server fails, and keeps the invitation', async () => {
    const { wrapper } = await open({
      invitations: [LUCIA],
      override: (r) => (r.method === 'DELETE' ? problem(500) : undefined),
    })

    await wrapper
      .get('button[aria-label="Cancelar la invitación a lucia@correo.cr"]')
      .trigger('click')
    await flushPromises()
    pressInDialog('Sí, cancelar invitación')
    await flushPromises()

    expect(wrapper.text()).toContain('No pudimos cancelar la invitación.')
    expect(wrapper.text()).toContain('lucia@correo.cr')
  })
})
