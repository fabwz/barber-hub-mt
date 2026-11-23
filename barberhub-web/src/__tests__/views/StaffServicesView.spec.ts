import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { signIn } from '../helpers/account'
import { problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { RAPADO, service, servicesApi } from '../helpers/services'

beforeEach(() => signIn('marco@example.test'))
afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

const isList = (r: Request) => r.method === 'GET' && r.url.endsWith('/administracion/servicios')

async function open(options: Parameters<typeof servicesApi>[0] = {}) {
  const api = servicesApi(options)
  const mounted = await mountView(App, `/barberias/${api.shopId}/servicios`)
  await flushPromises()
  return { ...mounted, ...api }
}

/** Presses a button of the open dialog by its text. */
function pressInDialog(text: string): void {
  const dialog = document.body.querySelector('[role="alertdialog"]')!
  const button = Array.from(dialog.querySelectorAll('button')).find((b) =>
    b.textContent?.includes(text),
  )
  button!.click()
}

describe('StaffServicesView', () => {
  it('lists the services with duration, price with VAT, status and the way to edit them', async () => {
    const { wrapper } = await open({
      services: [
        service({ imagen: '/api/v1/archivos/a.webp' }),
        RAPADO,
        service({ id: 'i', nombre: 'Tinte', activo: false }),
      ],
    })

    expect(wrapper.get('h1').text()).toBe('Servicios')
    const rows = wrapper.findAll('ul[aria-label="Servicios de la barbería"] > li')
    expect(rows).toHaveLength(3)
    expect(rows[0]!.text()).toContain('Corte clásico')
    expect(rows[0]!.text()).toContain('30 min')
    expect(rows[0]!.text()).toContain('₡7.000')
    expect(rows[0]!.text()).toContain('IVA incluido')
    expect(rows[0]!.text()).toContain('Activo')
    expect(rows[0]!.get('img').attributes('src')).toBe('/api/v1/archivos/a.webp')
    // A service without image shows no picture, only a neutral block.
    expect(rows[1]!.find('img').exists()).toBe(false)
    expect(rows[2]!.text()).toContain('Inactivo')
    expect(rows[2]!.text()).toContain('Activar')
    expect(rows[0]!.get('a').attributes('href')).toContain(
      '/servicios/0190b1f0-0000-7000-8000-0000000000a1',
    )
  })

  it('shows the skeleton while it loads', async () => {
    const api = servicesApi({ override: (r) => (isList(r) ? new Response() : undefined) })
    const { wrapper } = await mountView(App, `/barberias/${api.shopId}/servicios`)
    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true)
  })

  it('shows the empty state with the way to create the first one', async () => {
    const { wrapper } = await open({ services: [] })
    expect(wrapper.text()).toContain('Aún no tiene servicios.')
    expect(wrapper.text()).toContain('Crear mi primer servicio')
  })

  it('shows the error and tries again', async () => {
    let fail = true
    const { wrapper } = await open({
      override: (r) => (fail && isList(r) ? problem(500) : undefined),
    })
    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar los servicios')
    fail = false
    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()
    expect(wrapper.findAll('ul[aria-label="Servicios de la barbería"] > li')).toHaveLength(2)
  })

  it('inactivating asks first and changes nothing if the owner says no', async () => {
    const { wrapper, calls } = await open()
    await wrapper.get('button[aria-label="Inactivar Rapado"]').trigger('click')

    const dialog = document.body.querySelector('[role="alertdialog"]')!
    expect(dialog.textContent).toContain('¿Inactivar «Rapado»?')
    expect(dialog.textContent).toContain('Las reservas ya hechas y el historial no cambian.')
    pressInDialog('No, mantenerlo activo')
    await flushPromises()

    expect(calls.some((c) => c.startsWith('PATCH'))).toBe(false)
    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
  })

  it('confirming sends `activo: false`, shows the result and the new status', async () => {
    const { wrapper, bodies } = await open()
    await wrapper.get('button[aria-label="Inactivar Rapado"]').trigger('click')
    pressInDialog('Sí, inactivar')
    await flushPromises()

    expect(bodies).toEqual([{ activo: false }])
    expect(wrapper.get('[role="status"]').text()).toContain(
      '«Rapado» quedó inactivo. Ya no se puede reservar; sigue en el historial.',
    )
    expect(
      wrapper.get('[data-testid="service-0190b1f0-0000-7000-8000-0000000000a2"]').text(),
    ).toContain('Inactivo')
    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
  })

  it('activating does not ask', async () => {
    const { wrapper } = await open({ services: [service({ activo: false })] })
    await wrapper.get('button[aria-label="Activar Corte clásico"]').trigger('click')
    await flushPromises()
    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
    expect(wrapper.get('[role="status"]').text()).toContain('«Corte clásico» quedó activo.')
  })

  it('a failed change shows the error and «Reintentar» sends it again', async () => {
    let fail = true
    const { wrapper, calls } = await open({
      override: (r) => (fail && r.method === 'PATCH' ? problem(500) : undefined),
    })
    await wrapper.get('button[aria-label="Inactivar Rapado"]').trigger('click')
    pressInDialog('Sí, inactivar')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain(
      'No pudimos cambiar el estado de «Rapado»',
    )
    fail = false
    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()
    expect(calls.filter((c) => c.startsWith('PATCH'))).toHaveLength(2)
    expect(wrapper.get('[role="status"]').text()).toContain('quedó inactivo')
  })

  it('a collaborator of the shop does not get in: the agenda opens instead', async () => {
    const api = servicesApi({ role: 'colaborador' })
    const { router } = await mountView(App, `/barberias/${api.shopId}/servicios`)
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('staff-agenda')
    expect(api.calls.some((c) => c.includes('/administracion/servicios'))).toBe(false)
  })

  it('the shop travels in the route, never in a header', async () => {
    const { fetchMock, shopId } = await open()
    const list = fetchMock.mock.calls.map((c) => c[0] as Request).find(isList)!
    expect(new URL(list.url).pathname).toBe(`/api/v1/barberias/${shopId}/administracion/servicios`)
    expect([...list.headers.keys()].some((key) => key.includes('barberia'))).toBe(false)
  })

  it('the «Servicios» link of the bar shows for the owner', async () => {
    const { wrapper } = await open()
    const links = wrapper.findAll('nav a').map((a) => a.text())
    expect(links).toContain('Servicios')
  })
})
