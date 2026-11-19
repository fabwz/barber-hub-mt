import { ref } from 'vue'

import { api, isApiError, type components } from '@/api'

export type VoucherOnSale = components['schemas']['BonoALaVenta']
export type PurchasedVoucher = components['schemas']['BonoComprado']

/** Where a load stands: with its data (maybe none), or why it could not be. */
export type VoucherListResult = 'ready' | 'forbidden' | 'error'

/** Largest page the API allows; every page is asked for so the lists are complete. */
const PAGE_SIZE = 50
/** Safety stop: 20 pages of 50 vouchers in one barbershop. */
const MAX_PAGES = 20

export type MovementType = 'compra' | 'apartado' | 'consumo' | 'reintegro'

/**
 * A change of the balance of a purchased voucher. The contract leaves the items of `movimientos`
 * untyped, so they are read defensively here from what the API documents (tipo, sesiones, reserva,
 * fecha), like the 409 alternatives.
 */
export interface VoucherMovement {
  type: MovementType
  /** Sessions it adds (positive) or takes from the balance (negative); 0 for a consumption. */
  sessions: number
  /** Reservation it belongs to; `null` for the purchase. */
  reservationId: string | null
  /** ISO 8601 instant, as the API delivers it. */
  at: string
}

const MOVEMENT_TYPES: readonly string[] = ['compra', 'apartado', 'consumo', 'reintegro']

/** The valid movements of a voucher, newest first (the API lists them oldest first). */
export function readMovements(raw: unknown[]): VoucherMovement[] {
  const movements = raw.flatMap((item): VoucherMovement[] => {
    if (typeof item !== 'object' || item === null) return []
    const { tipo, sesiones, reserva, fecha } = item as Record<string, unknown>
    if (typeof tipo !== 'string' || !MOVEMENT_TYPES.includes(tipo)) return []
    if (typeof sesiones !== 'number' || typeof fecha !== 'string' || fecha === '') return []
    return [
      {
        type: tipo as MovementType,
        sessions: sesiones,
        reservationId: typeof reserva === 'string' && reserva !== '' ? reserva : null,
        at: fecha,
      },
    ]
  })
  return movements.reverse()
}

/**
 * A voucher of the person can pay a reservation of the service: it is active, has sessions and covers
 * the service (the services come from the API). Which of them is used is up to the API (the one that
 * expires first, BO-08); this only says whether the option is worth offering.
 */
export function canPayWith(voucher: PurchasedVoucher, serviceId: string): boolean {
  return (
    voucher.estado === 'activo' &&
    voucher.saldo > 0 &&
    voucher.servicios.some((service) => service.id === serviceId)
  )
}

/**
 * The vouchers of the person in one barbershop: the ones bought (RF-29) and the ones on sale
 * (RF-27), plus whether the shop has vouchers turned on. Owner and collaborator get 403 on both
 * routes: that is `forbidden`, and nothing is offered to them.
 */
export function useVouchers() {
  const mine = ref<PurchasedVoucher[]>([])
  const onSale = ref<VoucherOnSale[]>([])
  /** `meta.bonos_activos` of the list on sale; `false` until it is known. */
  const enabled = ref(false)
  const result = ref<VoucherListResult | null>(null)
  // Only the last call may write: an older one that ends later is dropped.
  let call = 0

  async function readMine(shopId: string): Promise<PurchasedVoucher[]> {
    const all: PurchasedVoucher[] = []
    let page = 1
    let last = 1
    do {
      const { data } = await api.GET('/barberias/{barberia}/mis-bonos', {
        params: { path: { barberia: shopId }, query: { pagina: page, por_pagina: PAGE_SIZE } },
      })
      all.push(...data!.data)
      last = data!.meta.ultima_pagina
      page += 1
    } while (page <= last && page <= MAX_PAGES)
    return all
  }

  async function readOnSale(shopId: string): Promise<{ items: VoucherOnSale[]; enabled: boolean }> {
    const all: VoucherOnSale[] = []
    let page = 1
    let last = 1
    let active = false
    do {
      const { data } = await api.GET('/barberias/{barberia}/bonos', {
        params: { path: { barberia: shopId }, query: { pagina: page, por_pagina: PAGE_SIZE } },
      })
      all.push(...data!.data)
      last = data!.meta.ultima_pagina
      active = data!.meta.bonos_activos
      page += 1
    } while (page <= last && page <= MAX_PAGES)
    return { items: all, enabled: active }
  }

  /** Loads what the screen needs: `which` limits it to one of the two lists. */
  async function load(
    shopId: string,
    which: 'both' | 'mine' | 'sale' = 'both',
  ): Promise<VoucherListResult> {
    const current = ++call
    try {
      const [bought, selling] = await Promise.all([
        which === 'sale' ? Promise.resolve(null) : readMine(shopId),
        which === 'mine' ? Promise.resolve(null) : readOnSale(shopId),
      ])
      if (current !== call) return result.value ?? 'ready'
      if (bought !== null) mine.value = bought
      if (selling !== null) {
        onSale.value = selling.items
        enabled.value = selling.enabled
      }
      result.value = 'ready'
    } catch (caught) {
      if (current !== call) return result.value ?? 'ready'
      result.value = isApiError(caught) && caught.status === 403 ? 'forbidden' : 'error'
    }
    return result.value
  }

  return { mine, onSale, enabled, result, load }
}
