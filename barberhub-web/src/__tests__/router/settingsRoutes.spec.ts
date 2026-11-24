import { describe, expect, it } from 'vitest'

import { routes } from '@/router'

describe('the routes of Configuración', () => {
  const settings = routes.find((route) => route.path === '/barberias/:barberia/configuracion')

  it('is for the owner of the shop, with the shop in the route', () => {
    expect(settings?.meta).toMatchObject({
      requiresAuth: true,
      requiresStaffShop: true,
      requiresOwner: true,
    })
  })

  it('has «Datos públicos» and «Cobro y políticas» as child routes', () => {
    const children = (settings?.children ?? []).map((child) => [child.path, child.name])
    expect(children).toEqual([
      ['', 'staff-settings'],
      ['cobro', 'staff-settings-billing'],
    ])
  })
})
