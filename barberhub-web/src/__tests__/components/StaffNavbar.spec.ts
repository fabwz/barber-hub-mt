import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router'

import StaffNavbar from '@/components/StaffNavbar.vue'
import { useActiveShopStore } from '@/stores/activeShop'
import { SESSION_STORAGE_KEY } from '@/stores/session'
import { mockFetch } from '../helpers/http'
import { CLIENT_SHOP, OWNER_SHOP, SHOP_A, SHOP_B, STAFF_SHOP, staffHandler } from '../helpers/staff'

const page = defineComponent({ render: () => h('p') })

const AGENDA_ROUTES: RouteRecordRaw[] = [
  { path: '/', component: page },
  { path: '/negocios', component: page },
  { path: '/cuenta/datos', name: 'account-data', component: page },
  { path: '/barberias/:barberia/agenda', name: 'staff-agenda', component: page },
  { path: '/barberias/:barberia/agenda/:reserva', name: 'staff-reservation', component: page },
]

beforeEach(() => {
  sessionStorage.setItem(
    SESSION_STORAGE_KEY,
    JSON.stringify({ token: 'tok', person: { email: 'marco@example.test' } }),
  )
  setActivePinia(createPinia())
})
afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

async function mountBar(memberships: unknown[], active: string, extra: RouteRecordRaw[] = []) {
  mockFetch(staffHandler(memberships))
  const shops = useActiveShopStore()
  await shops.loadStaffShops()
  shops.select(active)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [...AGENDA_ROUTES, ...extra],
  })
  await router.push(`/barberias/${active}/agenda`)
  await router.isReady()
  const wrapper = mount(StaffNavbar, {
    global: { plugins: [router] },
    attachTo: document.body,
  })
  await flushPromises()
  return { wrapper, router }
}

describe('StaffNavbar', () => {
  it('shows the active shop with its role and links to the agenda of that shop', async () => {
    const { wrapper } = await mountBar([OWNER_SHOP, CLIENT_SHOP], SHOP_A)

    expect(wrapper.get('[data-testid="active-shop"]').text()).toBe('Pura Navaja')
    expect(wrapper.text()).toContain('Dueño')
    const link = wrapper.get('nav a')
    expect(link.text()).toBe('Agenda')
    expect(link.attributes('href')).toBe(`/barberias/${SHOP_A}/agenda`)
    expect(link.attributes('aria-current')).toBe('page')
  })

  it('offers «Cambiar de barbería» only when there is more than one shop', async () => {
    const several = await mountBar([OWNER_SHOP, STAFF_SHOP], SHOP_B)
    expect(several.wrapper.get('a[href="/negocios"]').text()).toBe('Cambiar de barbería')
    expect(several.wrapper.text()).toContain('Colaborador')
    several.wrapper.unmount()

    setActivePinia(createPinia())
    const single = await mountBar([OWNER_SHOP, CLIENT_SHOP], SHOP_A)
    expect(single.wrapper.find('a[href="/negocios"]').exists()).toBe(false)
  })

  it('shows a link only when its route exists', async () => {
    const without = await mountBar([OWNER_SHOP], SHOP_A)
    expect(without.wrapper.findAll('nav a').map((link) => link.text())).toEqual(['Agenda'])
    without.wrapper.unmount()

    setActivePinia(createPinia())
    const withServices = await mountBar([OWNER_SHOP], SHOP_A, [
      { path: '/barberias/:barberia/servicios', name: 'staff-services', component: page },
    ])
    expect(withServices.wrapper.findAll('nav a').map((link) => link.text())).toEqual([
      'Agenda',
      'Servicios',
    ])
  })

  it('shows «Equipo» to the owner only when its route exists, and marks it current inside it', async () => {
    const without = await mountBar([OWNER_SHOP], SHOP_A)
    expect(without.wrapper.findAll('nav a').map((link) => link.text())).not.toContain('Equipo')
    without.wrapper.unmount()

    setActivePinia(createPinia())
    const { wrapper, router } = await mountBar([OWNER_SHOP], SHOP_A, [
      { path: '/barberias/:barberia/equipo', name: 'staff-team', component: page },
    ])
    const team = wrapper.findAll('nav a').find((link) => link.text() === 'Equipo')!
    expect(team.attributes('href')).toBe(`/barberias/${SHOP_A}/equipo`)
    expect(team.attributes('aria-current')).toBeUndefined()

    await router.push(`/barberias/${SHOP_A}/equipo`)
    await flushPromises()
    expect(team.attributes('aria-current')).toBe('page')
  })

  it('gives the collaborator only the agenda, even when the other routes exist', async () => {
    const { wrapper } = await mountBar([STAFF_SHOP], SHOP_B, [
      { path: '/barberias/:barberia/servicios', name: 'staff-services', component: page },
      { path: '/barberias/:barberia/equipo', name: 'staff-team', component: page },
    ])

    expect(wrapper.findAll('nav a').map((link) => link.text())).toEqual(['Agenda'])
  })

  it('opens the account menu with «Mi cuenta» and «Cerrar sesión»', async () => {
    const { wrapper } = await mountBar([OWNER_SHOP], SHOP_A)

    await wrapper.get('button[aria-expanded]').trigger('click')

    const account = wrapper.findAll('a').find((link) => link.text() === 'Mi cuenta')
    expect(account?.attributes('href')).toBe('/cuenta/datos')
    expect(wrapper.text()).toContain('Cerrar sesión')
    expect(wrapper.text()).toContain('marco@example.test')
  })

  it('in the phone menu the links come first, then the account', async () => {
    const { wrapper } = await mountBar([OWNER_SHOP], SHOP_A)

    await wrapper.get('button[aria-label="Menú"]').trigger('click')

    const panel = wrapper.get('ul')
    expect(panel.text()).toContain('Agenda')
  })
})
