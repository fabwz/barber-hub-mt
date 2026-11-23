import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { isApiError } from '@/api'
import { useScheduleStore } from '@/stores/schedule'
import { SESSION_STORAGE_KEY } from '@/stores/session'
import { problem } from '../helpers/http'
import { scheduleApi, slot } from '../helpers/schedule'

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
})

const MONDAY_MORNING = slot('s1', 1, '09:00', '12:00')
const MONDAY_AFTERNOON = slot('s2', 1, '14:00', '19:00')

describe('useScheduleStore', () => {
  it('loads the week and the closures of the shop of the route, slots sorted by day and hour', async () => {
    const api = scheduleApi({
      slots: [slot('s3', 2, '09:00', '19:00'), MONDAY_AFTERNOON, MONDAY_MORNING],
      closures: [{ id: 'c1', fecha: '2026-12-25', motivo: 'Navidad' }],
    })
    const store = useScheduleStore()
    await store.load(api.shopId)

    expect(api.calls).toEqual(
      expect.arrayContaining([`GET ${api.base}/horario`, `GET ${api.base}/cierres`]),
    )
    expect(store.status).toBe('ready')
    expect(store.slots.map((s) => s.id)).toEqual(['s1', 's2', 's3'])
    expect(store.closures).toHaveLength(1)
  })

  it('ends in error when it does not load', async () => {
    scheduleApi({ override: () => problem(500) })
    const store = useScheduleStore()
    await store.load('shop')
    expect(store.status).toBe('error')
  })

  it('adds a slot and keeps the week sorted', async () => {
    const api = scheduleApi({ slots: [MONDAY_AFTERNOON] })
    const store = useScheduleStore()
    await store.load(api.shopId)
    await store.addSlot(api.shopId, { dia: 1, abre: '09:00', cierra: '12:00' })
    expect(api.bodies).toEqual([{ dia: 1, abre: '09:00', cierra: '12:00' }])
    expect(store.slots.map((s) => s.abre)).toEqual(['09:00', '14:00'])
  })

  it('an overlapping slot is a 409 conflict and changes nothing', async () => {
    const api = scheduleApi({ slots: [MONDAY_MORNING] })
    const store = useScheduleStore()
    await store.load(api.shopId)
    const error = await store
      .addSlot(api.shopId, { dia: 1, abre: '11:00', cierra: '13:00' })
      .catch((reason: unknown) => reason)
    expect(isApiError(error) && error.status).toBe(409)
    expect(store.slots).toHaveLength(1)
  })

  it('removes a slot', async () => {
    const api = scheduleApi({ slots: [MONDAY_MORNING, MONDAY_AFTERNOON] })
    const store = useScheduleStore()
    await store.load(api.shopId)
    await store.removeSlot(api.shopId, 's1')
    expect(api.calls).toContain(`DELETE ${api.base}/horario/s1`)
    expect(store.slots.map((s) => s.id)).toEqual(['s2'])
  })

  it('adds a closure sorted by date, and a repeated date is a 409', async () => {
    const api = scheduleApi({ closures: [{ id: 'c1', fecha: '2026-12-25', motivo: null }] })
    const store = useScheduleStore()
    await store.load(api.shopId)
    await store.addClosure(api.shopId, { fecha: '2026-12-14', motivo: 'Inventario' })
    expect(store.closures.map((c) => c.fecha)).toEqual(['2026-12-14', '2026-12-25'])

    const error = await store
      .addClosure(api.shopId, { fecha: '2026-12-25' })
      .catch((reason: unknown) => reason)
    expect(isApiError(error) && error.status).toBe(409)
    expect(store.closures).toHaveLength(2)
  })

  it('removes a closure', async () => {
    const api = scheduleApi({ closures: [{ id: 'c1', fecha: '2026-12-25', motivo: null }] })
    const store = useScheduleStore()
    await store.load(api.shopId)
    await store.removeClosure(api.shopId, 'c1')
    expect(api.calls).toContain(`DELETE ${api.base}/cierres/c1`)
    expect(store.closures).toEqual([])
  })
})
