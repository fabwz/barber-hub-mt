import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import ShopSelectView from '@/views/ShopSelectView.vue'
import { useActiveShopStore } from '@/stores/activeShop'
import { SESSION_STORAGE_KEY } from '@/stores/session'
import { jsonResponse, mockFetch, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { CLIENT_SHOP, OWNER_SHOP, SHOP_A, SHOP_B, STAFF_SHOP, staffHandler } from '../helpers/staff'

beforeEach(() => {
  sessionStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify({ token: 'tok', person: null }))
})
afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

describe('ShopSelectView', () => {
  it('shows skeletons while the shops load', async () => {
    mockFetch(() => new Promise<Response>(() => {}))
    const { wrapper } = await mountView(ShopSelectView, '/negocios')

    expect(wrapper.get('h1').text()).toBe('Elija una barbería')
    expect(wrapper.text()).toContain('Cargando')
    expect(wrapper.find('[role="radiogroup"]').exists()).toBe(false)
  })

  it('shows an error with a retry that loads the list', async () => {
    let online = false
    mockFetch((request) =>
      online ? staffHandler([OWNER_SHOP, STAFF_SHOP])(request) : problem(500),
    )
    const { wrapper } = await mountView(ShopSelectView, '/negocios')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos cargar sus barberías.')

    online = true
    await wrapper.get('[role="alert"] button').trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.findAll('[role="radio"]')).toHaveLength(2)
  })

  it('with several shops lists name and role in text, none preselected, Continuar disabled', async () => {
    mockFetch(staffHandler([OWNER_SHOP, CLIENT_SHOP, STAFF_SHOP]))
    const { wrapper } = await mountView(ShopSelectView, '/negocios')
    await flushPromises()

    const radios = wrapper.findAll('[role="radio"]')
    expect(radios).toHaveLength(2)
    expect(radios[0]!.text()).toContain('Pura Navaja')
    expect(radios[0]!.text()).toContain('Dueño')
    expect(radios[1]!.text()).toContain('Fade Lab')
    expect(radios[1]!.text()).toContain('Colaborador')
    expect(wrapper.text()).not.toContain('Don Chepe')
    expect(radios.every((radio) => radio.attributes('aria-checked') === 'false')).toBe(true)
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()
  })

  it('choosing enables Continuar, announces it and enters the shop at «/»', async () => {
    mockFetch(staffHandler([OWNER_SHOP, STAFF_SHOP]))
    const { wrapper, router } = await mountView(ShopSelectView, '/negocios')
    await flushPromises()

    await wrapper.findAll('[role="radio"]')[1]!.trigger('click')

    expect(wrapper.get('[aria-live="polite"]').text()).toBe('Seleccionó Fade Lab.')
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeUndefined()

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(useActiveShopStore().active?.id).toBe(SHOP_B)
    expect(router.currentRoute.value.path).toBe('/')
  })

  it('works with the keyboard: arrows move the choice and Enter picks', async () => {
    mockFetch(staffHandler([OWNER_SHOP, STAFF_SHOP]))
    const { wrapper } = await mountView(ShopSelectView, '/negocios')
    await flushPromises()
    const radios = wrapper.findAll('[role="radio"]')

    expect(radios[0]!.attributes('tabindex')).toBe('0')
    ;(radios[0]!.element as HTMLElement).focus()
    await radios[0]!.trigger('keydown', { key: 'ArrowDown' })
    expect(radios[1]!.attributes('aria-checked')).toBe('true')

    await radios[0]!.trigger('keydown', { key: 'Enter' })
    expect(radios[0]!.attributes('aria-checked')).toBe('true')
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeUndefined()
  })

  it('with a single shop selects it and goes to «/» without showing the screen', async () => {
    mockFetch(staffHandler([OWNER_SHOP, CLIENT_SHOP]))
    const { wrapper, router } = await mountView(ShopSelectView, '/negocios')
    await flushPromises()

    expect(useActiveShopStore().active?.id).toBe(SHOP_A)
    expect(router.currentRoute.value.path).toBe('/')
    expect(wrapper.find('[role="radiogroup"]').exists()).toBe(false)
  })

  it('with no staff shop (a customer) goes to «/» and it is not an error', async () => {
    mockFetch(staffHandler([CLIENT_SHOP]))
    const { wrapper, router } = await mountView(ShopSelectView, '/negocios')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('without a session the route goes to the login', async () => {
    sessionStorage.clear()
    mockFetch(() => jsonResponse(200, { data: [] }))
    const { router } = await mountView(ShopSelectView, '/negocios')

    expect(router.currentRoute.value.path).toBe('/ingresar')
  })
})
