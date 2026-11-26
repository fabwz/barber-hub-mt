import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import App from '@/App.vue'
import { ACTIVE_SHOP_STORAGE_KEY } from '@/stores/activeShop'
import { accountHandler, signIn, type AccountRoutes } from '../helpers/account'
import { jsonResponse, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { OWNER_SHOP, SHOP_A, staffHandler } from '../helpers/staff'

beforeEach(() => {
  sessionStorage.clear()
  localStorage.clear()
  signIn()
})
afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

async function open(routes: AccountRoutes = {}) {
  const handler = accountHandler(routes)
  const mounted = await mountView(App, '/cuenta/avisos')
  await flushPromises()
  return { ...mounted, ...handler }
}

type Mounted = Awaited<ReturnType<typeof open>>['wrapper']

const toggle = (wrapper: Mounted) => wrapper.get('[role="switch"]')

/** A promise the test settles by hand, to look at the screen while a request is on its way. */
function pending() {
  let settle: (response: Response) => void = () => {}
  const promise = new Promise<Response>((resolve) => (settle = resolve))
  return { promise, settle }
}

const NOTE =
  'Siempre le enviaremos por correo la confirmación de sus reservas, las ofertas de espacio, las cancelaciones o reprogramaciones de la barbería, los reembolsos y el restablecimiento de contraseña. Estos avisos no se pueden desactivar.'

describe('«Mi cuenta · Avisos»', () => {
  it('shows the title, the row with its switch and the fixed note (lámina 22a)', async () => {
    const { wrapper } = await open()

    expect(wrapper.get('h2').text()).toBe('Avisos')
    expect(wrapper.text()).toContain('Elija qué correos recordatorios quiere recibir.')
    const row = toggle(wrapper).element.closest('.rounded-card')
    expect(row).not.toBeNull()
    expect(row!.classList.contains('border-line')).toBe(true)
    expect(row!.textContent).toContain('Recordatorios por correo')
    expect(row!.textContent).toContain(
      'Un correo 24 horas antes y otro 2 horas antes de cada cita. Aplica a sus reservas en todas las barberías.',
    )
    expect(wrapper.text().replace(/\s+/g, ' ')).toContain(NOTE)
  })

  it('the switch is a labelled 56 x 44 px control that is on by default', async () => {
    const { wrapper } = await open()
    const control = toggle(wrapper)

    expect(control.element.tagName).toBe('BUTTON')
    expect(control.attributes('aria-checked')).toBe('true')
    expect(control.classes()).toEqual(expect.arrayContaining(['h-11', 'w-14']))
    const label = wrapper.get(`[id="${control.attributes('aria-labelledby')}"]`)
    expect(label.text()).toBe('Recordatorios por correo')
    const description = wrapper.get(`[id="${control.attributes('aria-describedby')}"]`)
    expect(description.text()).toContain('24 horas antes')
  })

  it('shows the reminders off when the account has them off (lámina 22b)', async () => {
    const { wrapper } = await open({ reminders: false })

    expect(toggle(wrapper).attributes('aria-checked')).toBe('false')
  })

  it('shows a skeleton while loading, with no switch yet', async () => {
    const gate = pending()
    accountHandler({ getNotices: () => gate.promise })
    const { wrapper } = await mountView(App, '/cuenta/avisos')
    await flushPromises()

    expect(wrapper.find('[role="switch"]').exists()).toBe(false)
    expect(wrapper.get('section').attributes('aria-busy')).toBe('true')
    expect(wrapper.text()).toContain('Cargando sus avisos')

    gate.settle(jsonResponse(200, { data: { recordatorios_correo: true } }))
    await flushPromises()
    expect(wrapper.find('[role="switch"]').exists()).toBe(true)
  })

  it('a failed read shows the error with «Reintentar», which reads again', async () => {
    let fail = true
    const { wrapper } = await open({
      getNotices: () =>
        fail
          ? problem(500, { type: 'error-interno' })
          : jsonResponse(200, { data: { recordatorios_correo: false } }),
    })

    expect(wrapper.find('[role="switch"]').exists()).toBe(false)
    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar sus avisos')

    fail = false
    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(toggle(wrapper).attributes('aria-checked')).toBe('false')
  })

  it('pressing the switch saves without optimism: dimmed, busy and «Guardando…», still the old value (lámina 22c)', async () => {
    const gate = pending()
    const { wrapper, calls } = await open({ putNotices: () => gate.promise })

    await toggle(wrapper).trigger('click')
    await flushPromises()

    expect(calls.at(-1)).toEqual({
      route: 'PUT /api/v1/cuenta/avisos',
      body: { recordatorios_correo: false },
    })
    expect(toggle(wrapper).attributes('aria-checked')).toBe('true')
    expect(toggle(wrapper).attributes('aria-busy')).toBe('true')
    expect(toggle(wrapper).classes()).toContain('opacity-60')
    expect(wrapper.get('[role="status"]').text()).toBe('Guardando…')

    // A second press while saving does nothing.
    await toggle(wrapper).trigger('click')
    expect(calls.filter((call) => call.route === 'PUT /api/v1/cuenta/avisos')).toHaveLength(1)

    gate.settle(jsonResponse(200, { data: { recordatorios_correo: false } }))
    await flushPromises()

    expect(toggle(wrapper).attributes('aria-checked')).toBe('false')
    expect(toggle(wrapper).attributes('aria-busy')).toBeUndefined()
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('turns the reminders back on', async () => {
    const { wrapper, calls } = await open({ reminders: false })

    await toggle(wrapper).trigger('click')
    await flushPromises()

    expect(calls.at(-1)?.body).toEqual({ recordatorios_correo: true })
    expect(toggle(wrapper).attributes('aria-checked')).toBe('true')
  })

  it('a failed save keeps the old value and shows the error; «Reintentar» tries the same change (lámina 22d)', async () => {
    let fail = true
    const { wrapper, calls } = await open({
      putNotices: (body) =>
        fail
          ? problem(500, { type: 'error-interno' })
          : jsonResponse(200, { data: { recordatorios_correo: body.recordatorios_correo } }),
    })

    await toggle(wrapper).trigger('click')
    await flushPromises()

    expect(toggle(wrapper).attributes('aria-checked')).toBe('true')
    expect(toggle(wrapper).attributes('aria-busy')).toBeUndefined()
    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toContain('No pudimos guardar el cambio')
    expect(alert.text()).toContain('Su preferencia sigue como estaba. Inténtelo de nuevo.')
    expect(wrapper.text().replace(/\s+/g, ' ')).toContain(NOTE)

    fail = false
    await alert.get('button').trigger('click')
    await flushPromises()

    expect(calls.filter((call) => call.route === 'PUT /api/v1/cuenta/avisos')).toHaveLength(2)
    expect(calls.at(-1)?.body).toEqual({ recordatorios_correo: false })
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(toggle(wrapper).attributes('aria-checked')).toBe('false')
  })

  it('keeps nothing in the browser storage', async () => {
    const { wrapper } = await open()
    await toggle(wrapper).trigger('click')
    await flushPromises()

    expect(JSON.stringify({ ...localStorage })).not.toMatch(/recordatorio|avisos/i)
    expect(JSON.stringify({ ...sessionStorage })).not.toMatch(/recordatorio|avisos/i)
  })

  it('reads the preference from the API on every visit, because it lives in the account', async () => {
    const { wrapper } = await open({ reminders: false })
    expect(toggle(wrapper).attributes('aria-checked')).toBe('false')
    wrapper.unmount()

    const { wrapper: again, calls } = await open({ reminders: false })

    expect(calls.map((call) => call.route)).toContain('GET /api/v1/cuenta/avisos')
    expect(toggle(again).attributes('aria-checked')).toBe('false')
  })

  it('the staff that enters by the address goes to «Datos» and the preference is not asked', async () => {
    sessionStorage.setItem(ACTIVE_SHOP_STORAGE_KEY, JSON.stringify({ id: SHOP_A }))
    const { calls } = accountHandler({ other: staffHandler([OWNER_SHOP]) })
    const { router, wrapper } = await mountView(App, '/cuenta/avisos')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('account-data')
    expect(calls.some((call) => call.route === 'GET /api/v1/cuenta/avisos')).toBe(false)
    expect(wrapper.get('nav[aria-label="Mi cuenta"]').text()).not.toContain('Avisos')
  })
})
