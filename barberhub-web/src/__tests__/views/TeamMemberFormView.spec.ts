import { flushPromises, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { meetsPasswordPolicy } from '@/composables/passwordPolicy'
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

const isPut = (suffix: string) => (r: Request) => r.method === 'PUT' && r.url.endsWith(suffix)
const isAccountPost = (r: Request) =>
  r.method === 'POST' && r.url.endsWith('/administracion/colaboradores')

async function open(path: string, options: Parameters<typeof teamApi>[0] = {}) {
  const api = teamApi(options)
  const mounted = await mountView(App, `/barberias/${api.shopId}/equipo/colaboradores${path}`)
  await flushPromises()
  return { ...mounted, ...api }
}

type Mounted = Awaited<ReturnType<typeof open>>
type Root = VueWrapper

/** The input of a field by the text of its label. */
function field(wrapper: Root, label: string): DOMWrapper<HTMLInputElement> {
  const found = wrapper.findAll('label').find((l) => l.text() === label)
  if (found === undefined) throw new Error(`No field «${label}»`)
  return wrapper.get(`#${found.attributes('for')}`) as DOMWrapper<HTMLInputElement>
}

function switchOf(wrapper: Root, label: string): DOMWrapper<HTMLButtonElement> {
  const found = wrapper
    .findAll('[role="switch"]')
    .find((s) => document.getElementById(s.attributes('aria-labelledby')!)?.textContent === label)
  if (found === undefined) throw new Error(`No switch «${label}»`)
  return found as DOMWrapper<HTMLButtonElement>
}

function checkbox(wrapper: Root, name: string): DOMWrapper<HTMLButtonElement> {
  const found = wrapper
    .findAll('[role="checkbox"]')
    .find((c) =>
      document.getElementById(c.attributes('aria-labelledby')!)?.textContent?.includes(name),
    )
  if (found === undefined) throw new Error(`No checkbox «${name}»`)
  return found as DOMWrapper<HTMLButtonElement>
}

async function submit(wrapper: Root) {
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

/** Fills a valid new collaborator: data, one service and a Monday shift with a break. */
async function fillValid(wrapper: Root) {
  await field(wrapper, 'Nombre completo').setValue('Daniela Mora')
  await field(wrapper, 'Correo').setValue('daniela@correo.cr')
  await checkbox(wrapper, 'Corte clásico').trigger('click')
  await switchOf(wrapper, 'Trabaja el lunes').trigger('click')
  await field(wrapper, 'Inicio del turno 1 del lunes').setValue('0900')
  await field(wrapper, 'Fin del turno 1 del lunes').setValue('1700')
  await wrapper
    .findAll('button')
    .find((b) => b.text() === 'Agregar pausa')!
    .trigger('click')
  await field(wrapper, 'Inicio de la pausa 1 del lunes').setValue('1230')
  await field(wrapper, 'Fin de la pausa 1 del lunes').setValue('1330')
}

function writes(api: Mounted): string[] {
  return api.calls.filter((c) => !c.startsWith('GET'))
}

function passwordSent(api: Mounted): string {
  const body = api.bodies.find((b) => b.route.startsWith('POST'))!.body as {
    contrasena_temporal: string
  }
  return body.contrasena_temporal
}

describe('TeamMemberFormView: new collaborator', () => {
  it('shows the data, the services and the week; no phone, no invitations', async () => {
    const { wrapper } = await open('/nuevo')
    expect(wrapper.get('h2#member-title').text()).toBe('Nuevo colaborador')
    expect(wrapper.text()).toContain(
      'Se crea su cuenta con una contraseña temporal que verá una sola vez al guardar.',
    )
    expect(wrapper.findAll('label').map((l) => l.text())).toEqual(
      expect.arrayContaining(['Nombre completo', 'Correo']),
    )
    expect(wrapper.text()).not.toMatch(/Teléfono|invitación|invitar|API pendiente/i)
    expect(wrapper.text()).toContain('Solo se reserva con esta persona lo que marque aquí.')
    expect(wrapper.text()).toContain('30 min · ₡7.000')
    expect(wrapper.findAll('[role="switch"]')).toHaveLength(7)
    expect(wrapper.text()).toContain('Barbería: 09:00–19:00')
    expect(wrapper.text()).toContain('Barbería: Cerrada')
  })

  it('«Trabaja» adds an empty shift row with no hour invented, and turning it off clears the day', async () => {
    const { wrapper } = await open('/nuevo')
    const monday = switchOf(wrapper, 'Trabaja el lunes')
    await monday.trigger('click')
    expect((field(wrapper, 'Inicio del turno 1 del lunes').element as HTMLInputElement).value).toBe(
      '',
    )
    expect((field(wrapper, 'Fin del turno 1 del lunes').element as HTMLInputElement).value).toBe('')
    await monday.trigger('click')
    expect(wrapper.findAll('label').some((l) => l.text().startsWith('Inicio del turno'))).toBe(
      false,
    )
  })

  it('checks the form before any trip and sends nothing', async () => {
    const { wrapper, calls } = await open('/nuevo')
    await switchOf(wrapper, 'Trabaja el lunes').trigger('click')
    await submit(wrapper)

    const text = wrapper.text()
    expect(text).toContain('Escriba el nombre completo.')
    expect(text).toContain('Escriba el correo electrónico.')
    expect(text).toContain('Elija al menos un servicio.')
    expect(text).toContain('Escriba las horas en formato HH:MM, de 00:00 a 23:59.')
    expect(writes({ calls } as Mounted)).toEqual([])
  })

  it('creates the account, then the services, then the schedule, in that order', async () => {
    const api = await open('/nuevo')
    await fillValid(api.wrapper)
    await submit(api.wrapper)

    expect(writes(api)).toEqual([
      `POST ${api.base}/colaboradores`,
      expect.stringMatching(/^PUT .*\/servicios$/),
      expect.stringMatching(/^PUT .*\/horario$/),
    ])
    const [account, services, schedule] = api.bodies
    expect(account!.body).toMatchObject({ nombre: 'Daniela Mora', correo: 'daniela@correo.cr' })
    expect(services!.body).toEqual({ servicios: ['svc-clasico'] })
    expect(schedule!.body).toEqual({
      turnos: [{ dia: 1, inicia: '09:00', termina: '17:00' }],
      pausas: [{ dia: 1, inicia: '12:30', termina: '13:30' }],
    })
  })

  it('makes a temporary password that meets the policy and shows it once, with the summary', async () => {
    const api = await open('/nuevo')
    await fillValid(api.wrapper)
    await submit(api.wrapper)

    const password = passwordSent(api)
    expect(meetsPasswordPolicy(password)).toBe(true)
    expect(password.length).toBeGreaterThanOrEqual(8)
    const shown = api.wrapper.get('[data-testid="temporary-password"]').text()
    expect(shown).toBe(password)
    const text = api.wrapper.text()
    expect(text).toContain('Colaborador creado')
    expect(text).toContain('Solo la verá ahora')
    expect(text).toContain('Por seguridad no se vuelve a mostrar.')
    expect(text).toContain('Ingresará con daniela@correo.cr.')
    expect(text).toContain('Servicios habilitados')
    expect(text).toMatch(/1 de 2/)
    expect(text).toContain('Lunes, 09:00 a 17:00')
    // The form is gone and the password is not kept anywhere in the page storage.
    expect(api.wrapper.find('form').exists()).toBe(false)
    expect(JSON.stringify({ ...sessionStorage })).not.toContain(password)
    expect(JSON.stringify({ ...localStorage })).not.toContain(password)
  })

  it('copies the password and says so; if it cannot copy it asks to do it by hand', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText } })
    const api = await open('/nuevo')
    await fillValid(api.wrapper)
    await submit(api.wrapper)

    const copy = () =>
      api.wrapper.findAll('button').find((b) => /Copiar contraseña|Copiada/.test(b.text()))!
    await copy().trigger('click')
    await flushPromises()
    expect(writeText).toHaveBeenCalledWith(passwordSent(api))
    expect(api.wrapper.text()).toContain('Contraseña copiada al portapapeles.')
    expect(copy().text()).toBe('Copiada')

    writeText.mockRejectedValue(new Error('blocked'))
    await copy().trigger('click')
    await flushPromises()
    expect(api.wrapper.text()).toContain('No pudimos copiarla. Selecciónela y cópiela a mano.')
  })

  it('«Listo» asks if the password was copied: «No, volver» stays and «Sí, salir» leaves', async () => {
    const api = await open('/nuevo')
    await fillValid(api.wrapper)
    await submit(api.wrapper)
    await api.wrapper
      .findAll('button')
      .find((b) => b.text() === 'Listo')!
      .trigger('click')

    const dialog = () => document.body.querySelector('[role="alertdialog"]')!
    expect(dialog().textContent).toContain('¿Ya copió la contraseña?')
    expect(dialog().textContent).toContain('Al salir de esta pantalla no se vuelve a mostrar.')
    ;(
      Array.from(dialog().querySelectorAll('button')).find((b) =>
        b.textContent?.includes('No, volver'),
      ) as HTMLButtonElement
    ).click()
    await flushPromises()
    expect(api.router.currentRoute.value.name).toBe('staff-team-member-new')
    expect(api.wrapper.get('[data-testid="temporary-password"]').exists()).toBe(true)

    await api.wrapper
      .findAll('button')
      .find((b) => b.text() === 'Listo')!
      .trigger('click')
    ;(
      Array.from(dialog().querySelectorAll('button')).find((b) =>
        b.textContent?.includes('Sí, salir'),
      ) as HTMLButtonElement
    ).click()
    await vi.waitFor(() => expect(api.router.currentRoute.value.name).toBe('staff-team-members'))
  })

  it('leaving by another way (a link, back) also asks before the password is lost', async () => {
    const api = await open('/nuevo')
    await fillValid(api.wrapper)
    await submit(api.wrapper)

    await api.router.push({ name: 'staff-team', params: { barberia: api.shopId } })
    await flushPromises()
    expect(api.router.currentRoute.value.name).toBe('staff-team-member-new')
    expect(document.body.querySelector('[role="alertdialog"]')!.textContent).toContain(
      '¿Ya copió la contraseña?',
    )
  })

  it('a repeated email is a 422 shown in the email field and nothing else is sent', async () => {
    const api = await open('/nuevo', {
      collaborators: [collaborator({ correo: 'daniela@correo.cr' })],
    })
    await fillValid(api.wrapper)
    await submit(api.wrapper)

    expect(api.wrapper.text()).toContain('Este correo ya está registrado.')
    expect(api.wrapper.get('input[type="email"]').attributes('aria-invalid')).toBe('true')
    expect(writes(api)).toEqual([`POST ${api.base}/colaboradores`])
    // The wording does not send the owner to an invitation (#100).
    expect(api.wrapper.text()).not.toMatch(/invitaci/i)
  })

  it('if the services fail the account exists: a new try sends only the services, never the account again', async () => {
    let fail = true
    const api = await open('/nuevo', {
      override: (r) => (fail && isPut('/servicios')(r) ? problem(500) : undefined),
    })
    await fillValid(api.wrapper)
    await submit(api.wrapper)

    expect(api.wrapper.get('[data-testid="partial-notice"]').text()).toContain('ya se creó')
    // The password stays within reach, since the account exists.
    expect(api.wrapper.get('[data-testid="partial-notice"]').text()).toContain(passwordSent(api))
    expect(api.wrapper.find('[data-testid="temporary-password"]').exists()).toBe(false)

    fail = false
    await submit(api.wrapper)

    expect(api.calls.filter((c) => c === `POST ${api.base}/colaboradores`)).toHaveLength(1)
    expect(api.calls.filter((c) => c.startsWith('PUT') && c.endsWith('/servicios'))).toHaveLength(2)
    expect(api.calls.filter((c) => c.startsWith('PUT') && c.endsWith('/horario'))).toHaveLength(1)
    expect(api.wrapper.get('[data-testid="temporary-password"]').text()).toBe(passwordSent(api))
    // It is the same password of the first try.
    expect(api.bodies.filter((b) => b.route.startsWith('POST'))).toHaveLength(1)
  })

  it('if the schedule fails it names the row, and a new try sends only the schedule', async () => {
    let fail = true
    const api = await open('/nuevo', {
      override: (r) =>
        fail && isPut('/horario')(r)
          ? problem(422, {
              type: 'datos-invalidos',
              errores: {
                'turnos.0': ['El turno debe caer dentro del horario de la barbería de ese día.'],
                'pausas.0': ['La pausa debe caer dentro de un turno del mismo día.'],
              },
            })
          : undefined,
    })
    await fillValid(api.wrapper)
    await submit(api.wrapper)

    const errors = api.wrapper.findAll('section p[role="alert"]').map((p) => p.text())
    expect(errors).toEqual([
      'El turno debe caer dentro del horario de la barbería de ese día.',
      'La pausa debe caer dentro de un turno del mismo día.',
    ])
    expect(field(api.wrapper, 'Inicio del turno 1 del lunes').attributes('aria-invalid')).toBe(
      'true',
    )
    expect(field(api.wrapper, 'Inicio de la pausa 1 del lunes').attributes('aria-invalid')).toBe(
      'true',
    )

    fail = false
    await field(api.wrapper, 'Fin del turno 1 del lunes').setValue('1800')
    await submit(api.wrapper)

    expect(api.calls.filter((c) => c === `POST ${api.base}/colaboradores`)).toHaveLength(1)
    // The services already went through with this same list: they are not sent again.
    expect(api.calls.filter((c) => c.startsWith('PUT') && c.endsWith('/servicios'))).toHaveLength(1)
    expect(api.calls.filter((c) => c.startsWith('PUT') && c.endsWith('/horario'))).toHaveLength(2)
    expect(api.wrapper.get('[data-testid="temporary-password"]').exists()).toBe(true)
  })

  it('puts the error of `turnos.N` in the right row when there are shifts on several days', async () => {
    const api = await open('/nuevo', {
      override: (r) =>
        isPut('/horario')(r)
          ? problem(422, {
              type: 'datos-invalidos',
              errores: { 'turnos.1': ['El turno se traslapa con otro del mismo día.'] },
            })
          : undefined,
    })
    await fillValid(api.wrapper)
    await switchOf(api.wrapper, 'Trabaja el martes').trigger('click')
    await field(api.wrapper, 'Inicio del turno 1 del martes').setValue('0900')
    await field(api.wrapper, 'Fin del turno 1 del martes').setValue('1200')
    await submit(api.wrapper)

    expect(
      field(api.wrapper, 'Inicio del turno 1 del lunes').attributes('aria-invalid'),
    ).toBeUndefined()
    expect(field(api.wrapper, 'Inicio del turno 1 del martes').attributes('aria-invalid')).toBe(
      'true',
    )
  })

  it('shows the error of a service the API does not know in the services list', async () => {
    const api = await open('/nuevo', {
      override: (r) =>
        isPut('/servicios')(r)
          ? problem(422, {
              type: 'datos-invalidos',
              errores: { 'servicios.0': ['El servicio no existe en esta barbería.'] },
            })
          : undefined,
    })
    await fillValid(api.wrapper)
    await submit(api.wrapper)
    expect(api.wrapper.get('fieldset p[role="alert"]').text()).toBe(
      'El servicio no existe en esta barbería.',
    )
  })

  it('without services in the shop it sends to create one first', async () => {
    const { wrapper } = await open('/nuevo', { services: [] })
    expect(wrapper.text()).toContain('Primero cree un servicio.')
    expect(wrapper.find('form').exists()).toBe(false)
  })
})

describe('TeamMemberFormView: the profile of the owner (CO-02)', () => {
  it('asks for no account data, says the owner stays the owner and creates only the profile', async () => {
    const api = await open('/mi-perfil')
    expect(api.wrapper.get('h2#member-title').text()).toBe('Crear mi perfil de colaborador')
    expect(api.wrapper.text()).toContain(
      'Usted sigue siendo dueño. Con este perfil tendrá agenda propia y podrá recibir reservas.',
    )
    expect(api.wrapper.findAll('label').some((l) => l.text() === 'Nombre completo')).toBe(false)

    await checkbox(api.wrapper, 'Corte clásico').trigger('click')
    await switchOf(api.wrapper, 'Trabaja el lunes').trigger('click')
    await field(api.wrapper, 'Inicio del turno 1 del lunes').setValue('0900')
    await field(api.wrapper, 'Fin del turno 1 del lunes').setValue('1700')
    await submit(api.wrapper)

    expect(writes(api)).toEqual([
      `POST ${api.base}/colaboradores/mi-perfil`,
      expect.stringMatching(/^PUT .*\/servicios$/),
      expect.stringMatching(/^PUT .*\/horario$/),
    ])
    expect(api.wrapper.text()).toContain('Perfil creado.')
    expect(api.wrapper.find('[data-testid="temporary-password"]').exists()).toBe(false)
  })

  it('a 409 says the owner already has a profile', async () => {
    const api = await open('/mi-perfil', { collaborators: [collaborator({ es_dueno: true })] })
    await checkbox(api.wrapper, 'Corte clásico').trigger('click')
    await submit(api.wrapper)
    expect(api.wrapper.get('[role="alert"]').text()).toContain(
      'Usted ya tiene un perfil de colaborador.',
    )
    expect(writes(api)).toEqual([`POST ${api.base}/colaboradores/mi-perfil`])
  })
})

describe('TeamMemberFormView: edit', () => {
  const KEVIN = collaborator({
    id: 'kevin-id',
    nombre: 'Kevin Araya',
    correo: 'kevin@correo.cr',
    servicios: ['svc-clasico'],
    turnos: [{ dia: 1, inicia: '09:00', termina: '17:00' }],
    pausas: [{ dia: 1, inicia: '12:00', termina: '13:00' }],
  })

  it('fills the services and the schedule, and shows name and email without a way to change them', async () => {
    const { wrapper } = await open('/kevin-id', { collaborators: [KEVIN] })
    expect(wrapper.get('h2#member-title').text()).toBe('Editar colaborador')
    expect(wrapper.text()).toContain('Kevin Araya')
    expect(wrapper.text()).toContain('kevin@correo.cr')
    expect(
      wrapper.findAll('label').some((l) => ['Nombre completo', 'Correo'].includes(l.text())),
    ).toBe(false)
    expect(checkbox(wrapper, 'Corte clásico').attributes('aria-checked')).toBe('true')
    expect(checkbox(wrapper, 'Rapado').attributes('aria-checked')).toBe('false')
    expect(switchOf(wrapper, 'Trabaja el lunes').attributes('aria-checked')).toBe('true')
    expect((field(wrapper, 'Inicio del turno 1 del lunes').element as HTMLInputElement).value).toBe(
      '09:00',
    )
    expect((field(wrapper, 'Fin de la pausa 1 del lunes').element as HTMLInputElement).value).toBe(
      '13:00',
    )
  })

  it('saves only services and schedule: it never creates anything', async () => {
    const api = await open('/kevin-id', { collaborators: [KEVIN] })
    await checkbox(api.wrapper, 'Rapado').trigger('click')
    await field(api.wrapper, 'Fin del turno 1 del lunes').setValue('1800')
    await submit(api.wrapper)

    expect(writes(api)).toEqual([
      expect.stringMatching(/^PUT .*\/colaboradores\/kevin-id\/servicios$/),
      expect.stringMatching(/^PUT .*\/colaboradores\/kevin-id\/horario$/),
    ])
    expect(api.bodies[0]!.body).toEqual({ servicios: ['svc-clasico', 'svc-rapado'] })
    expect(api.bodies[1]!.body).toEqual({
      turnos: [{ dia: 1, inicia: '09:00', termina: '18:00' }],
      pausas: [{ dia: 1, inicia: '12:00', termina: '13:00' }],
    })
    expect(api.wrapper.text()).toContain('Cambios guardados.')
  })

  it('clearing every day sends an empty schedule', async () => {
    const api = await open('/kevin-id', { collaborators: [KEVIN] })
    await switchOf(api.wrapper, 'Trabaja el lunes').trigger('click')
    await submit(api.wrapper)
    expect(api.bodies[1]!.body).toEqual({ turnos: [], pausas: [] })
  })

  it('says so when the collaborator is not in the shop', async () => {
    const { wrapper } = await open('/otro-id', { collaborators: [KEVIN] })
    expect(wrapper.get('[role="alert"]').text()).toContain('No encontramos al colaborador')
    expect(wrapper.find('form').exists()).toBe(false)
  })
})

describe('TeamMemberFormView: access', () => {
  it('a collaborator of the shop does not reach the form', async () => {
    const api = teamApi({ role: 'colaborador' })
    const { router } = await mountView(App, `/barberias/${api.shopId}/equipo/colaboradores/nuevo`)
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('staff-agenda')
  })

  it('an account is only created with the shop in the route, never in the body', async () => {
    const api = await open('/nuevo')
    await fillValid(api.wrapper)
    await submit(api.wrapper)
    const post = api.fetchMock.mock.calls.map((c) => c[0] as Request).find(isAccountPost)!
    expect(new URL(post.url).pathname).toBe(
      `/api/v1/barberias/${api.shopId}/administracion/colaboradores`,
    )
    const body = api.bodies.find((b) => b.route.startsWith('POST'))!.body as Record<string, unknown>
    expect(Object.keys(body).sort()).toEqual(['contrasena_temporal', 'correo', 'nombre'])
  })
})
