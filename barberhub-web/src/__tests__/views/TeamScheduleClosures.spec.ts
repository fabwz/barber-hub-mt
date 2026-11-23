import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { addDays, formatDateWithYear, todayInCostaRica } from '@/composables/format'
import { signIn } from '../helpers/account'
import { problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { scheduleApi, type ClosureItem } from '../helpers/schedule'

beforeEach(() => signIn('marco@example.test'))
afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

const TODAY = todayInCostaRica()
const NEXT_WEEK = addDays(TODAY, 7)
const IN_TEN_DAYS = addDays(TODAY, 10)
const YESTERDAY = addDays(TODAY, -1)

const INVENTORY: ClosureItem = { id: 'c1', fecha: NEXT_WEEK, motivo: 'Cierre por inventario' }

const isClosurePost = (r: Request) =>
  r.method === 'POST' && r.url.endsWith('/administracion/cierres')

async function open(options: Parameters<typeof scheduleApi>[0] = {}) {
  const api = scheduleApi(options)
  const mounted = await mountView(App, `/barberias/${api.shopId}/equipo`)
  await flushPromises()
  return { ...mounted, ...api }
}

type Mounted = Awaited<ReturnType<typeof open>>

async function fillForm(wrapper: Mounted['wrapper'], date: string, reason = '') {
  await wrapper.get('input[type="date"]').setValue(date)
  if (reason !== '') await wrapper.get('input[maxlength="200"]').setValue(reason)
}

async function submit(wrapper: Mounted['wrapper']) {
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

function pressInDialog(text: string): void {
  const dialog = document.body.querySelector('[role="alertdialog"]')!
  Array.from(dialog.querySelectorAll('button'))
    .find((b) => b.textContent?.includes(text))!
    .click()
}

describe('Closures: the list', () => {
  it('says so when there are none', async () => {
    const { wrapper } = await open()
    expect(wrapper.text()).toContain('Excepciones por fecha')
    expect(wrapper.text()).toContain('No hay fechas cerradas.')
  })

  it('lists them by date with their reason', async () => {
    const { wrapper } = await open({
      closures: [INVENTORY, { id: 'c2', fecha: IN_TEN_DAYS, motivo: null }],
    })
    const items = wrapper.findAll('ul[aria-label="Fechas cerradas"] > li')
    expect(items).toHaveLength(2)
    expect(items[0]!.text()).toContain(formatDateWithYear(NEXT_WEEK))
    expect(items[0]!.text()).toContain('Cierre por inventario')
    expect(items[1]!.text()).toContain(formatDateWithYear(IN_TEN_DAYS))
  })

  it('the reason help is neutral and does not mention the team', async () => {
    const { wrapper } = await open()
    expect(wrapper.text()).toContain(
      'Una nota breve para recordar el motivo. Los clientes no la ven.',
    )
    expect(wrapper.text()).not.toContain('su equipo')
  })
})

describe('Closures: adding one', () => {
  it('the date field starts today: it has no earlier day to pick', async () => {
    const { wrapper } = await open()
    expect(wrapper.get('input[type="date"]').attributes('min')).toBe(TODAY)
  })

  it('asks for the date before sending anything', async () => {
    const { wrapper, calls } = await open()
    await submit(wrapper)
    expect(wrapper.text()).toContain('Elija la fecha del cierre.')
    expect(wrapper.get('input[type="date"]').attributes('aria-invalid')).toBe('true')
    expect(calls.some((c) => c.startsWith('POST'))).toBe(false)
  })

  it('refuses a past date before sending anything', async () => {
    const { wrapper, calls } = await open()
    await fillForm(wrapper, YESTERDAY)
    await submit(wrapper)
    expect(wrapper.text()).toContain('La fecha no puede ser anterior a hoy.')
    expect(calls.some((c) => c.startsWith('POST'))).toBe(false)
  })

  it('adds it with its reason, lists it, clears the form and says so', async () => {
    const { wrapper, bodies } = await open()
    await fillForm(wrapper, IN_TEN_DAYS, '  Feriado  ')
    await submit(wrapper)

    expect(bodies).toEqual([{ fecha: IN_TEN_DAYS, motivo: 'Feriado' }])
    expect(wrapper.get('[role="status"]').text()).toBe('Fecha cerrada agregada.')
    const items = wrapper.findAll('ul[aria-label="Fechas cerradas"] > li')
    expect(items).toHaveLength(1)
    expect(items[0]!.text()).toContain('Feriado')
    expect((wrapper.get('input[type="date"]').element as HTMLInputElement).value).toBe('')
  })

  it('sends no reason when it was left empty', async () => {
    const { wrapper, bodies } = await open()
    await fillForm(wrapper, IN_TEN_DAYS)
    await submit(wrapper)
    expect(bodies).toEqual([{ fecha: IN_TEN_DAYS }])
  })

  it('a date that already has a closure is a 409 shown in the date field', async () => {
    const { wrapper, calls } = await open({ closures: [INVENTORY] })
    await fillForm(wrapper, NEXT_WEEK)
    await submit(wrapper)

    expect(calls.filter((c) => c.startsWith('POST'))).toHaveLength(1)
    expect(wrapper.get('input[type="date"]').attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('Ya hay una fecha cerrada ese día.')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    expect(wrapper.findAll('ul[aria-label="Fechas cerradas"] > li')).toHaveLength(1)
  })

  it('shows the 422 of the API in its field (a date the API turns down)', async () => {
    const { wrapper } = await open({
      override: (r) =>
        isClosurePost(r)
          ? problem(422, { type: 'datos-invalidos', errores: { fecha: ['La fecha ya pasó.'] } })
          : undefined,
    })
    await fillForm(wrapper, IN_TEN_DAYS)
    await submit(wrapper)
    expect(wrapper.text()).toContain('La fecha ya pasó.')
  })

  it('a failure that is not about a field shows a general error and keeps what was typed', async () => {
    const { wrapper } = await open({
      override: (r) => (isClosurePost(r) ? problem(500) : undefined),
    })
    await fillForm(wrapper, IN_TEN_DAYS, 'Feriado')
    await submit(wrapper)
    expect(wrapper.get('[role="alert"]').text()).toBe(
      'No pudimos agregar la fecha cerrada. Inténtelo de nuevo.',
    )
    expect((wrapper.get('input[type="date"]').element as HTMLInputElement).value).toBe(IN_TEN_DAYS)
  })
})

describe('Closures: removing one', () => {
  it('asks first and changes nothing if the owner says no', async () => {
    const { wrapper, calls } = await open({ closures: [INVENTORY] })
    await wrapper.get('button[aria-label^="Quitar el cierre del"]').trigger('click')

    const dialog = document.body.querySelector('[role="alertdialog"]')!
    expect(dialog.textContent).toContain(`¿Quitar el cierre del ${formatDateWithYear(NEXT_WEEK)}?`)
    expect(dialog.textContent).toContain('Esa fecha volverá a regirse por el horario semanal.')
    pressInDialog('No, mantener el cierre')
    await flushPromises()

    expect(calls.some((c) => c.startsWith('DELETE'))).toBe(false)
    expect(wrapper.findAll('ul[aria-label="Fechas cerradas"] > li')).toHaveLength(1)
  })

  it('confirming removes it at once and says so', async () => {
    const { wrapper, calls, base } = await open({ closures: [INVENTORY] })
    await wrapper.get('button[aria-label^="Quitar el cierre del"]').trigger('click')
    pressInDialog('Sí, quitar el cierre')
    await flushPromises()

    expect(calls).toContain(`DELETE ${base}/cierres/c1`)
    expect(wrapper.get('[role="status"]').text()).toBe('Cierre quitado.')
    expect(wrapper.text()).toContain('No hay fechas cerradas.')
    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
  })

  it('keeps the closure and shows the error when it cannot be removed', async () => {
    const { wrapper } = await open({
      closures: [INVENTORY],
      override: (r) => (r.method === 'DELETE' ? problem(500) : undefined),
    })
    await wrapper.get('button[aria-label^="Quitar el cierre del"]').trigger('click')
    pressInDialog('Sí, quitar el cierre')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe(
      'No pudimos quitar el cierre. Inténtelo de nuevo.',
    )
    expect(wrapper.findAll('ul[aria-label="Fechas cerradas"] > li')).toHaveLength(1)
  })
})
