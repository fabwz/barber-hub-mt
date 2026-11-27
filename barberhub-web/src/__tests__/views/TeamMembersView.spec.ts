import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { signIn } from '../helpers/account'
import { problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { collaborator, teamApi } from '../helpers/team'

beforeEach(() => signIn('marco@example.test'))
afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

const KEVIN = collaborator({
  id: 'kevin-id',
  nombre: 'Kevin Araya',
  correo: 'kevin@correo.cr',
  servicios: ['svc-clasico', 'svc-rapado'],
})
const DANIELA = collaborator({
  id: 'daniela-id',
  nombre: 'Daniela Mora',
  servicios: ['svc-clasico'],
  activo: false,
})
const OWNER = collaborator({
  id: 'owner-id',
  nombre: 'Marco Solís',
  correo: 'marco@correo.cr',
  es_dueno: true,
  servicios: ['svc-clasico', 'svc-rapado'],
})

const isList = (r: Request) => r.method === 'GET' && r.url.endsWith('/administracion/colaboradores')

async function open(options: Parameters<typeof teamApi>[0] = {}) {
  const api = teamApi(options)
  const mounted = await mountView(App, `/barberias/${api.shopId}/equipo/colaboradores`)
  await flushPromises()
  return { ...mounted, ...api }
}

function pressInDialog(text: string): void {
  const dialog = document.body.querySelector('[role="alertdialog"]')!
  Array.from(dialog.querySelectorAll('button'))
    .find((b) => b.textContent?.includes(text))!
    .click()
}

describe('TeamMembersView: the list', () => {
  it('shows each collaborator with status and services, the owner marked, and no phone', async () => {
    const { wrapper } = await open({ collaborators: [OWNER, KEVIN, DANIELA] })

    const rows = wrapper.findAll('ul[aria-label="Colaboradores de la barbería"] > li')
    expect(rows).toHaveLength(3)
    expect(rows[0]!.text()).toContain('Marco Solís · Dueño')
    expect(rows[0]!.text()).toContain('marco@correo.cr')
    expect(rows[0]!.text()).toContain('2 de 2 servicios')
    expect(rows[1]!.text()).toContain('Kevin Araya')
    expect(rows[1]!.text()).not.toContain('Dueño')
    expect(rows[1]!.text()).toContain('Activo')
    expect(rows[2]!.text()).toContain('1 de 2 servicios')
    expect(rows[2]!.text()).toContain('Inactivo')
    expect(rows[2]!.text()).toContain('Activar')
    expect(wrapper.text()).not.toMatch(/Teléfono|\d{4}[ -]\d{4}/)
  })

  it('the section links show the three sections and mark «Colaboradores» as current', async () => {
    const { wrapper } = await open({ collaborators: [KEVIN] })
    const links = wrapper.findAll('nav[aria-label="Secciones de Equipo"] a')
    expect(links.map((l) => l.text())).toEqual([
      'Colaboradores',
      'Invitaciones',
      'Bloqueos de agenda',
      'Horario de la barbería',
    ])
    expect(links[0]!.attributes('aria-current')).toBe('page')
    expect(links[1]!.attributes('aria-current')).toBeUndefined()
  })

  it('«Editar» only offers services and schedule: its address is the one of the form', async () => {
    const { wrapper, shopId } = await open({ collaborators: [KEVIN] })
    const edit = wrapper.get('a[aria-label="Editar a Kevin Araya"]')
    expect(edit.attributes('href')).toBe(`/barberias/${shopId}/equipo/colaboradores/kevin-id`)
  })

  it('shows the skeleton while it loads', async () => {
    const api = teamApi({ override: (r) => (isList(r) ? new Response() : undefined) })
    const { wrapper } = await mountView(App, `/barberias/${api.shopId}/equipo/colaboradores`)
    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true)
  })

  it('shows the empty state', async () => {
    const { wrapper } = await open()
    expect(wrapper.text()).toContain('Aún no tiene colaboradores.')
  })

  it('shows the error and tries again', async () => {
    let fail = true
    const { wrapper } = await open({
      collaborators: [KEVIN],
      override: (r) => (fail && isList(r) ? problem(500) : undefined),
    })
    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar los colaboradores')
    fail = false
    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()
    expect(wrapper.findAll('ul[aria-label="Colaboradores de la barbería"] > li')).toHaveLength(1)
  })
})

describe('TeamMembersView: the profile of the owner (CO-02)', () => {
  it('without a profile, it says so and offers «Crear mi perfil de colaborador»', async () => {
    const { wrapper, shopId } = await open({ collaborators: [KEVIN] })
    expect(wrapper.text()).toContain('Usted todavía no tiene perfil de colaborador.')
    expect(wrapper.text()).toContain('seguirá siendo dueño')
    const link = wrapper.findAll('a').find((a) => a.text() === 'Crear mi perfil de colaborador')!
    expect(link.attributes('href')).toBe(`/barberias/${shopId}/equipo/colaboradores/mi-perfil`)
  })

  it('with a profile, the notice is gone (the owner is told apart by `es_dueno`)', async () => {
    const { wrapper } = await open({ collaborators: [OWNER, KEVIN] })
    expect(wrapper.text()).not.toContain('Usted todavía no tiene perfil')
  })
})

describe('TeamMembersView: deactivating (CO-03)', () => {
  it('asks first, with neutral words, and changes nothing if the owner says no', async () => {
    const { wrapper, calls } = await open({ collaborators: [KEVIN] })
    await wrapper.get('button[aria-label="Desactivar a Kevin Araya"]').trigger('click')

    const dialog = document.body.querySelector('[role="alertdialog"]')!
    expect(dialog.textContent).toContain('¿Desactivar a Kevin Araya?')
    expect(dialog.textContent).toContain(
      'Dejará de ofrecerse para reservar y de ingresar a BarberHub.',
    )
    // No word that depends on the gender of the person.
    expect(dialog.textContent).not.toMatch(/\b(activa|activo|ella|él|la|lo)\b(?= cuando)/)
    expect(dialog.textContent).toContain('No, conservar')
    expect(dialog.textContent).toContain('Sí, desactivar')
    pressInDialog('No, conservar')
    await flushPromises()

    expect(calls.some((c) => c.startsWith('PATCH'))).toBe(false)
    expect(wrapper.get('ul[aria-label="Colaboradores de la barbería"] li').text()).toContain(
      'Activo',
    )
  })

  it('the text for the profile of the owner says the owner stays the owner', async () => {
    const { wrapper } = await open({ collaborators: [OWNER] })
    await wrapper.get('button[aria-label="Desactivar a Marco Solís"]').trigger('click')
    const dialog = document.body.querySelector('[role="alertdialog"]')!
    expect(dialog.textContent).toContain('Usted seguirá siendo dueño')
    expect(dialog.textContent).not.toContain('de ingresar a BarberHub')
  })

  it('confirming sends `activo: false`, says so and shows the new status', async () => {
    const { wrapper, bodies, base } = await open({ collaborators: [KEVIN] })
    await wrapper.get('button[aria-label="Desactivar a Kevin Araya"]').trigger('click')
    pressInDialog('Sí, desactivar')
    await flushPromises()

    expect(bodies).toEqual([
      { route: `PATCH ${base}/colaboradores/kevin-id`, body: { activo: false } },
    ])
    expect(wrapper.get('[role="status"]').text()).toBe(
      'Kevin Araya quedó inactivo y ya no recibe reservas.',
    )
    expect(wrapper.get('[data-testid="collaborator-kevin-id"]').text()).toContain('Inactivo')
  })

  it('reactivating asks nothing', async () => {
    const { wrapper } = await open({ collaborators: [DANIELA] })
    await wrapper.get('button[aria-label="Activar a Daniela Mora"]').trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
    expect(wrapper.get('[role="status"]').text()).toBe(
      'Daniela Mora quedó activo y ya puede recibir reservas.',
    )
  })

  it('a 409 with reservations shows the block and the list, and keeps the collaborator active', async () => {
    const { wrapper, shopId } = await open({
      collaborators: [KEVIN],
      blocking: {
        'kevin-id': [
          {
            id: 'res-1',
            inicio: '2026-11-06T17:30:00-06:00',
            servicio: 'Rapado',
            cliente: 'Ana Quirós',
          },
          {
            id: 'res-2',
            inicio: '2026-11-07T16:30:00-06:00',
            servicio: 'Corte clásico',
            cliente: 'Luis Mora',
          },
        ],
      },
    })
    await wrapper.get('button[aria-label="Desactivar a Kevin Araya"]').trigger('click')
    pressInDialog('Sí, desactivar')
    await flushPromises()

    const dialog = document.body.querySelector('[role="alertdialog"]')!
    expect(dialog.textContent).toContain('No se puede desactivar a Kevin Araya')
    expect(dialog.textContent).toContain('Tiene 2 reservas futuras confirmadas.')
    expect(dialog.textContent).toContain('Reprograme o cancele cada una desde la agenda')
    const items = Array.from(dialog.querySelectorAll('ul[aria-label="Reservas por resolver"] > li'))
    expect(items).toHaveLength(2)
    expect(items[0]!.textContent).toContain('vie 6 nov, 17:30 · Ana Quirós')
    expect(items[0]!.textContent).toContain('Rapado')
    expect(items[0]!.querySelector('a')!.getAttribute('href')).toBe(
      `/barberias/${shopId}/agenda/res-1?dia=2026-11-06`,
    )
    // One button only: «Entendido».
    expect(Array.from(dialog.querySelectorAll('button')).map((b) => b.textContent?.trim())).toEqual(
      ['Entendido'],
    )
    // Nothing changed.
    expect(wrapper.get('[data-testid="collaborator-kevin-id"]').text()).toContain('Activo')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)

    pressInDialog('Entendido')
    await flushPromises()
    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
  })

  it('says how many more there are when the list is shorter than the total', async () => {
    const { wrapper } = await open({
      collaborators: [KEVIN],
      blocking: {
        'kevin-id': [
          { id: 'res-1', inicio: '2026-11-06T17:30:00-06:00', servicio: 'Rapado', cliente: 'Ana' },
        ],
      },
      total: { 'kevin-id': 31 },
    })
    await wrapper.get('button[aria-label="Desactivar a Kevin Araya"]').trigger('click')
    pressInDialog('Sí, desactivar')
    await flushPromises()

    const dialog = document.body.querySelector('[role="alertdialog"]')!
    expect(dialog.textContent).toContain('Tiene 31 reservas futuras confirmadas.')
    expect(dialog.textContent).toContain('y 30 más.')
  })

  it('any other failure shows the error and «Reintentar» sends it again', async () => {
    let fail = true
    const { wrapper, calls } = await open({
      collaborators: [KEVIN],
      override: (r) => (fail && r.method === 'PATCH' ? problem(500) : undefined),
    })
    await wrapper.get('button[aria-label="Desactivar a Kevin Araya"]').trigger('click')
    pressInDialog('Sí, desactivar')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain(
      'No pudimos cambiar el estado de Kevin Araya',
    )

    fail = false
    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()
    expect(calls.filter((c) => c.startsWith('PATCH'))).toHaveLength(2)
    expect(wrapper.get('[role="status"]').text()).toContain('quedó inactivo')
  })

  it('a collaborator of the shop does not get in: the agenda opens instead', async () => {
    const api = teamApi({ role: 'colaborador' })
    const { router } = await mountView(App, `/barberias/${api.shopId}/equipo/colaboradores`)
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('staff-agenda')
  })
})
