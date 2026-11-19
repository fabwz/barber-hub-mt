import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  canPayWith,
  newestFirst,
  useVouchers,
  type PurchasedVoucher,
} from '@/composables/useVouchers'
import { jsonResponse, problem } from '../helpers/http'
import {
  BASE,
  CUT,
  CUT_BEARD,
  DYE,
  ONVO_SHELF,
  bought,
  expired,
  list,
  mockVoucherFetch,
  onSale,
} from '../helpers/vouchers'

afterEach(() => vi.unstubAllGlobals())

const voucher = (over: Record<string, unknown> = {}) => bought(over) as unknown as PurchasedVoucher

describe('newestFirst', () => {
  it('returns the movements newest first, as the contract types them, without touching the original', () => {
    const original = voucher().movimientos
    const movements = newestFirst(original)

    expect(movements.map((item) => item.tipo)).toEqual([
      'apartado',
      'reintegro',
      'apartado',
      'consumo',
      'apartado',
      'compra',
    ])
    expect(movements.at(-1)).toEqual({
      tipo: 'compra',
      sesiones: 5,
      reserva: null,
      fecha: '2030-01-04T08:00:00-06:00',
    })
    expect(movements[0]).toMatchObject({ sesiones: -1, reserva: 'r3' })
    expect(original[0]!.tipo).toBe('compra')
  })

  it('gives an empty list for a voucher with no movements', () => {
    expect(newestFirst([])).toEqual([])
  })
})

describe('canPayWith', () => {
  it('is true for an active voucher with sessions that covers the service', () => {
    expect(canPayWith(voucher(), CUT.id)).toBe(true)
  })

  it.each([
    ['an expired voucher', expired({ servicios: [CUT] }), CUT.id],
    ['a voucher with no sessions', bought({ saldo: 0 }), CUT.id],
    ['a service it does not cover', bought(), DYE.id],
  ])('is false for %s', (_name, item, serviceId) => {
    expect(canPayWith(item as unknown as PurchasedVoucher, serviceId)).toBe(false)
  })

  it('does not look at which voucher expires first: that is up to the API', () => {
    const later = voucher({ id: 'a', vence_en: '2031-01-01T00:00:00-06:00' })
    const sooner = voucher({ id: 'b', vence_en: '2030-02-01T00:00:00-06:00' })

    expect(canPayWith(later, CUT_BEARD.id)).toBe(true)
    expect(canPayWith(sooner, CUT_BEARD.id)).toBe(true)
  })
})

describe('useVouchers', () => {
  it('loads both lists and whether the vouchers are on', async () => {
    mockVoucherFetch({ onSale: () => jsonResponse(200, list([onSale()], ONVO_SHELF)) })
    const vouchers = useVouchers()

    expect(await vouchers.load('0190b1f0-0000-7000-8000-000000000001')).toBe('ready')

    expect(vouchers.mine.value).toHaveLength(1)
    expect(vouchers.onSale.value).toHaveLength(1)
    expect(vouchers.enabled.value).toBe(true)
  })

  it('says forbidden for the owner or a collaborator and keeps nothing to offer', async () => {
    mockVoucherFetch({
      mine: () => problem(403, { type: 'prohibido' }),
      onSale: () => problem(403, { type: 'prohibido' }),
    })
    const vouchers = useVouchers()

    expect(await vouchers.load('0190b1f0-0000-7000-8000-000000000001')).toBe('forbidden')
    expect(vouchers.onSale.value).toEqual([])
    expect(vouchers.enabled.value).toBe(false)
  })

  it('says error on any other failure', async () => {
    mockVoucherFetch({ mine: () => problem(500) })
    const vouchers = useVouchers()

    expect(await vouchers.load('0190b1f0-0000-7000-8000-000000000001')).toBe('error')
  })

  it('reads only the list asked for', async () => {
    const { count } = mockVoucherFetch()
    const vouchers = useVouchers()

    await vouchers.load('0190b1f0-0000-7000-8000-000000000001', 'mine')

    expect(count('GET', '/mis-bonos')).toBe(1)
    expect(count('GET', `${BASE.slice(BASE.lastIndexOf('/'))}/bonos`)).toBe(0)
  })
})
