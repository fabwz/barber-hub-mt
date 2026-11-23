import { flushPromises, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { signIn } from '../helpers/account'
import { problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { scheduleApi, slot } from '../helpers/schedule'

beforeEach(() => signIn('marco@example.test'))
afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

const WEEK = [
  slot('s1', 1, '09:00', '19:00'),
  slot('s2', 2, '09:00', '12:30'),
  slot('s3', 2, '14:00', '19:00'),
]

const isSlotList = (r: Request) => r.method === 'GET' && r.url.endsWith('/administracion/horario')
const isSlotPost = (r: Request) => r.method === 'POST' && r.url.endsWith('/administracion/horario')

async function open(options: Parameters<typeof scheduleApi>[0] = {}) {
  const api = scheduleApi({ slots: WEEK, ...options })
  const mounted = await mountView(App, `/barberias/${api.shopId}/equipo`)
  await flushPromises()
  return { ...mounted, ...api }
}

type Root = VueWrapper

function day(wrapper: Root, label: string): DOMWrapper<HTMLElement> {
  // Each day is a section with exactly one heading; the section of the whole week has seven.
  return wrapper
    .findAll('section')
    .find((s) => s.findAll('h3').length === 1 && s.get('h3').text() === label)!
}

function button(scope: DOMWrapper<HTMLElement>, text: string): DOMWrapper<HTMLButtonElement> {
  return scope.findAll('button').find((b) => b.text() === text) as DOMWrapper<HTMLButtonElement>
}

async function type(scope: DOMWrapper<HTMLElement>, index: number, value: string) {
  await scope.findAll('input')[index]!.setValue(value)
}

describe('StaffTeamView: the section', () => {
  it('is Equipo with a link per section, the current one marked, and no tabs', async () => {
    const { wrapper } = await open()
    expect(wrapper.get('h1').text()).toBe('Equipo')
    expect(wrapper.text()).toContain('Colaboradores y el horario de la barbería.')
    const nav = wrapper.get('nav[aria-label="Secciones de Equipo"]')
    const links = nav.findAll('a')
    expect(links.map((l) => l.text())).toEqual(['Colaboradores', 'Horario de la barbería'])
    expect(links[0]!.attributes('aria-current')).toBeUndefined()
    expect(links[1]!.attributes('aria-current')).toBe('page')
    expect(wrapper.find('[role="tab"], [role="tablist"], [role="tabpanel"]').exists()).toBe(false)
  })

  it('the owner gets «Equipo» in the bar and the collaborator does not get in', async () => {
    const { wrapper } = await open()
    expect(wrapper.findAll('header nav a').map((a) => a.text())).toContain('Equipo')

    document.body.innerHTML = ''
    const api = scheduleApi({ role: 'colaborador' })
    const { router } = await mountView(App, `/barberias/${api.shopId}/equipo`)
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('staff-agenda')
    expect(api.calls.some((c) => c.includes('/administracion/'))).toBe(false)
  })
})

describe('TeamScheduleView: states', () => {
  it('shows the skeleton while it loads', async () => {
    const api = scheduleApi({ override: (r) => (isSlotList(r) ? new Response() : undefined) })
    const { wrapper } = await mountView(App, `/barberias/${api.shopId}/equipo`)
    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true)
  })

  it('shows the error and tries again', async () => {
    let fail = true
    const { wrapper } = await open({
      override: (r) => (fail && isSlotList(r) ? problem(500) : undefined),
    })
    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar el horario')
    fail = false
    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()
    expect(wrapper.findAll('section h3').map((h) => h.text())).toEqual(
      expect.arrayContaining([
        'Lunes',
        'Martes',
        'Miércoles',
        'Jueves',
        'Viernes',
        'Sábado',
        'Domingo',
      ]),
    )
  })

  it('shows the seven days: open ones with their slots, the others «Cerrado»', async () => {
    const { wrapper } = await open()
    const monday = day(wrapper, 'Lunes')
    expect(monday.text()).toContain('Abierto')
    expect(monday.findAll('input').map((i) => (i.element as HTMLInputElement).value)).toEqual([
      '09:00',
      '19:00',
    ])
    const tuesday = day(wrapper, 'Martes')
    expect(tuesday.findAll('input').map((i) => (i.element as HTMLInputElement).value)).toEqual([
      '09:00',
      '12:30',
      '14:00',
      '19:00',
    ])
    const sunday = day(wrapper, 'Domingo')
    expect(sunday.text()).toContain('Cerrado')
    expect(sunday.text()).toContain('No atiende este día.')
    expect(sunday.findAll('input')).toHaveLength(0)
  })
})

describe('TeamScheduleView: adding a slot', () => {
  it('opening a day only adds an empty row: no hour is invented and nothing is sent', async () => {
    const { wrapper, calls } = await open()
    const sunday = day(wrapper, 'Domingo')
    await button(sunday, 'Abrir este día').trigger('click')

    expect(sunday.findAll('input').map((i) => (i.element as HTMLInputElement).value)).toEqual([
      '',
      '',
    ])
    expect(sunday.text()).toContain('Cerrado')
    expect(calls.filter((c) => c.startsWith('POST'))).toEqual([])
  })

  it('does not save a row until it has valid hours', async () => {
    const { wrapper, calls } = await open()
    const sunday = day(wrapper, 'Domingo')
    await button(sunday, 'Abrir este día').trigger('click')
    await type(sunday, 0, '0900')
    await button(sunday, 'Guardar franja').trigger('click')

    expect(sunday.text()).toContain('Escriba las horas en formato HH:MM, de 00:00 a 23:59.')
    expect(sunday.findAll('input[aria-invalid="true"]')).toHaveLength(2)
    expect(calls.some((c) => c.startsWith('POST'))).toBe(false)
  })

  it('refuses a closing hour that is not after the opening, in the row', async () => {
    const { wrapper, calls } = await open()
    const sunday = day(wrapper, 'Domingo')
    await button(sunday, 'Abrir este día').trigger('click')
    await type(sunday, 0, '1200')
    await type(sunday, 1, '1200')
    await button(sunday, 'Guardar franja').trigger('click')

    expect(sunday.text()).toContain('La hora de cierre debe ser posterior a la de apertura.')
    expect(calls.some((c) => c.startsWith('POST'))).toBe(false)
  })

  it('saves a valid row at once, opens the day and says so', async () => {
    const { wrapper, bodies } = await open()
    const sunday = day(wrapper, 'Domingo')
    await button(sunday, 'Abrir este día').trigger('click')
    await type(sunday, 0, '0800')
    await type(sunday, 1, '1700')
    await button(sunday, 'Guardar franja').trigger('click')
    await flushPromises()

    expect(bodies).toEqual([{ dia: 7, abre: '08:00', cierra: '17:00' }])
    expect(wrapper.get('[role="status"]').text()).toBe('Franja agregada.')
    expect(sunday.text()).toContain('Abierto')
    expect(sunday.findAll('input').map((i) => (i.element as HTMLInputElement).value)).toEqual([
      '08:00',
      '17:00',
    ])
  })

  it('names the slot it overlaps with before sending anything', async () => {
    const { wrapper, calls } = await open()
    const tuesday = day(wrapper, 'Martes')
    await button(tuesday, 'Agregar franja').trigger('click')
    await type(tuesday, 4, '1200')
    await type(tuesday, 5, '1300')
    await button(tuesday, 'Guardar franja').trigger('click')

    expect(tuesday.text()).toContain(
      'Esta franja se solapa con la de 09:00 a 12:30. Corrija las horas.',
    )
    expect(calls.some((c) => c.startsWith('POST'))).toBe(false)
  })

  it('shows the 409 of the API in the row when the overlap was not seen here', async () => {
    const { wrapper } = await open({
      override: (r) => (isSlotPost(r) ? problem(409, { type: 'conflicto' }) : undefined),
    })
    const sunday = day(wrapper, 'Domingo')
    await button(sunday, 'Abrir este día').trigger('click')
    await type(sunday, 0, '0800')
    await type(sunday, 1, '1700')
    await button(sunday, 'Guardar franja').trigger('click')
    await flushPromises()

    expect(sunday.get('[role="alert"]').text()).toBe(
      'Esta franja se solapa con otra del mismo día. Corrija las horas.',
    )
    // The row stays so the hours can be corrected.
    expect(sunday.findAll('input')).toHaveLength(2)
    expect(sunday.text()).toContain('Cerrado')
  })

  it('shows the 422 of the API (closing not after opening) in the row', async () => {
    const { wrapper } = await open({
      override: (r) =>
        isSlotPost(r)
          ? problem(422, {
              type: 'datos-invalidos',
              errores: { cierra: ['El cierre debe ser posterior a la apertura.'] },
            })
          : undefined,
    })
    const sunday = day(wrapper, 'Domingo')
    await button(sunday, 'Abrir este día').trigger('click')
    await type(sunday, 0, '0800')
    await type(sunday, 1, '1700')
    await button(sunday, 'Guardar franja').trigger('click')
    await flushPromises()
    expect(sunday.get('[role="alert"]').text()).toBe('El cierre debe ser posterior a la apertura.')
  })

  it('«Quitar» on a row that is not saved only drops it', async () => {
    const { wrapper, calls } = await open()
    const sunday = day(wrapper, 'Domingo')
    await button(sunday, 'Abrir este día').trigger('click')
    await sunday.get('button[aria-label^="Quitar la franja sin guardar"]').trigger('click')
    expect(sunday.findAll('input')).toHaveLength(0)
    expect(sunday.text()).toContain('No atiende este día.')
    expect(calls.some((c) => c.startsWith('DELETE'))).toBe(false)
  })
})

describe('TeamScheduleView: removing a slot', () => {
  it('removes it at once and, being the last one, the day is «Cerrado»', async () => {
    const { wrapper, calls, base } = await open()
    const monday = day(wrapper, 'Lunes')
    await monday
      .get('button[aria-label="Quitar la franja de 09:00 a 19:00 del lunes"]')
      .trigger('click')
    await flushPromises()

    expect(calls).toContain(`DELETE ${base}/horario/s1`)
    expect(wrapper.get('[role="status"]').text()).toBe('Franja quitada.')
    expect(monday.text()).toContain('Cerrado')
  })

  it('shows the error in the row and keeps the slot when it cannot be removed', async () => {
    const { wrapper } = await open({
      override: (r) => (r.method === 'DELETE' ? problem(500) : undefined),
    })
    const monday = day(wrapper, 'Lunes')
    await monday.get('button[aria-label^="Quitar la franja de 09:00"]').trigger('click')
    await flushPromises()
    expect(monday.get('[role="alert"]').text()).toBe(
      'No pudimos quitar la franja. Inténtelo de nuevo.',
    )
    expect(monday.text()).toContain('Abierto')
  })
})

describe('TeamScheduleView: changing a slot', () => {
  async function edit(wrapper: Root) {
    const monday = day(wrapper, 'Lunes')
    await type(monday, 1, '1800')
    return monday
  }

  it('offers to save or discard a change, and discarding sends nothing', async () => {
    const { wrapper, calls } = await open()
    const monday = await edit(wrapper)
    expect(button(monday, 'Guardar cambio').exists()).toBe(true)
    await button(monday, 'Descartar').trigger('click')
    expect((monday.findAll('input')[1]!.element as HTMLInputElement).value).toBe('19:00')
    expect(calls.filter((c) => c !== `GET ${calls[0]}` && !c.startsWith('GET'))).toEqual([])
  })

  it('removes the old slot and then adds the new one, in that order', async () => {
    const { wrapper, calls, base, bodies, slots } = await open()
    const monday = await edit(wrapper)
    await button(monday, 'Guardar cambio').trigger('click')
    await flushPromises()

    const writes = calls.filter((c) => !c.startsWith('GET'))
    expect(writes).toEqual([`DELETE ${base}/horario/s1`, `POST ${base}/horario`])
    expect(bodies).toEqual([{ dia: 1, abre: '09:00', cierra: '18:00' }])
    expect(wrapper.get('[role="status"]').text()).toBe('Franja actualizada.')
    expect(
      slots()
        .filter((s) => s.dia === 1)
        .map((s) => [s.abre, s.cierra]),
    ).toEqual([['09:00', '18:00']])
  })

  it('if the new slot is refused, the old one comes back and the error says so', async () => {
    let posts = 0
    const { wrapper, calls, base, slots } = await open({
      // The first POST (the new slot) fails; the second one (the restoration) goes through.
      override: (r) =>
        isSlotPost(r) && posts++ === 0 ? problem(409, { type: 'conflicto' }) : undefined,
    })
    const monday = await edit(wrapper)
    await button(monday, 'Guardar cambio').trigger('click')
    await flushPromises()

    expect(calls.filter((c) => !c.startsWith('GET'))).toEqual([
      `DELETE ${base}/horario/s1`,
      `POST ${base}/horario`,
      `POST ${base}/horario`,
    ])
    // The week is as it was.
    expect(
      slots()
        .filter((s) => s.dia === 1)
        .map((s) => [s.abre, s.cierra]),
    ).toEqual([['09:00', '19:00']])
    expect((monday.findAll('input')[1]!.element as HTMLInputElement).value).toBe('19:00')
    expect(monday.get('[role="alert"]').text()).toBe(
      'Esta franja se solapa con otra del mismo día. Corrija las horas. Se mantuvo la franja de 09:00 a 19:00.',
    )
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('if the old slot cannot come back either, it reloads the week and says it', async () => {
    const { wrapper } = await open({ override: (r) => (isSlotPost(r) ? problem(500) : undefined) })
    const monday = await edit(wrapper)
    await button(monday, 'Guardar cambio').trigger('click')
    await flushPromises()

    const alert = day(wrapper, 'Lunes').get('[role="alert"]')
    expect(alert.text()).toBe(
      'No pudimos restaurar la franja de 09:00 a 19:00. Revise el horario y agréguela de nuevo.',
    )
    // What the API holds is what is shown: the day has no slot now.
    expect(day(wrapper, 'Lunes').text()).toContain('Cerrado')
  })

  it('if the old slot cannot be removed nothing changed and the error says so', async () => {
    const { wrapper, calls } = await open({
      override: (r) => (r.method === 'DELETE' ? problem(500) : undefined),
    })
    const monday = await edit(wrapper)
    await button(monday, 'Guardar cambio').trigger('click')
    await flushPromises()
    expect(monday.get('[role="alert"]').text()).toBe(
      'No pudimos guardar la franja. Inténtelo de nuevo. No se cambió nada.',
    )
    expect(calls.some((c) => c.startsWith('POST'))).toBe(false)
  })
})
