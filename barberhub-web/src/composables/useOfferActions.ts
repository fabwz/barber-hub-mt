import { useRouter } from 'vue-router'

import { useOffersStore, type AnswerOutcome } from '@/stores/offers'

/**
 * The answers to an offer, shared by the pop-up and by «Mis filas». The API decides what follows an
 * acceptance: a confirmed reservation goes to its confirmation, and a reservation with an amount to
 * pay goes to the payment, which counts down to the `vence_en` of the offer (LE-04, PA-06).
 */
export function useOfferActions() {
  const router = useRouter()
  const offers = useOffersStore()

  /** Accepts the offer, with a session of a voucher or not, and goes where the answer says. */
  async function accept(id: string, withVoucher: boolean): Promise<AnswerOutcome> {
    const tracked = offers.find(id)
    const outcome = await offers.accept(id, withVoucher)
    if (outcome.kind !== 'confirmed' && outcome.kind !== 'payment') return outcome
    const shop = tracked === undefined ? null : await offers.ensureShop(tracked.shopId)
    if (shop === null) {
      await router.push({ name: 'directory' })
      return outcome
    }
    await router.push({
      name: outcome.kind === 'confirmed' ? 'booking-confirmed' : 'payment',
      params: { enlace: shop.link, reserva: outcome.reservation },
    })
    return outcome
  }

  function reject(id: string): Promise<AnswerOutcome> {
    return offers.reject(id)
  }

  return { accept, reject }
}
