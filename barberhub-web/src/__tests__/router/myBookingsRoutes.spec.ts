import { describe, expect, it } from 'vitest'

import { routes } from '@/router'

describe('routes of «Mis reservas»', () => {
  it.each([
    ['my-bookings', '/b/:enlace/mis-reservas'],
    ['booking-detail', '/b/:enlace/mis-reservas/:reserva'],
  ])('%s needs a session and loads its view lazily', (name, path) => {
    const route = routes.find((item) => item.name === name)

    expect(route?.path).toBe(path)
    expect(route?.meta?.requiresAuth).toBe(true)
    // Lazy: the component is a function that imports the view, not the view itself.
    expect(typeof route?.component).toBe('function')
  })
})
