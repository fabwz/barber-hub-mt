import { ref } from 'vue'

import { api, isApiError, type ApiError } from '@/api'
import { networkError } from '@/api/errors'
import {
  PaymentSetupError,
  buildPaymentBody,
  isFinalPayment,
  readStoredVoucherPayment,
  redirectTo,
  setupApiError,
  writeStoredPayment,
  writeStoredVoucherPayment,
  type Payment,
  type StoredVoucherPayment,
  type SubmitInput,
} from '@/composables/usePayment'

export interface PurchaseInput extends Omit<SubmitInput, 'reservationId'> {
  /** Identifier of the voucher on sale, the `{bono}` of the route. */
  voucherId: string
}

/**
 * Pays for a voucher (RF-27): creates the payment, keeps what is needed to resume it and reads its
 * status. The amount is never sent: the server charges the full price. The voucher is active only
 * when the API says the payment is approved, never because of what the browser reports.
 */
export function useVoucherPurchase() {
  const payment = ref<Payment | null>(null)
  const paymentId = ref<string | null>(null)
  const submitting = ref(false)
  const redirecting = ref(false)
  const error = ref<ApiError | null>(null)

  /**
   * Creates the payment. A double click does not create two: while a request is in flight the call is
   * ignored, and an active payment is resumed instead of asking for another one. The gateway is the
   * one the list on sale reported: with ONVO the card is exchanged at ONVO; with PayPal only the
   * method travels and the person is sent to PayPal.
   */
  async function submit(input: PurchaseInput): Promise<Payment | null> {
    if (submitting.value || redirecting.value) return null
    if (payment.value !== null && !isFinalPayment(payment.value)) return payment.value
    submitting.value = true
    error.value = null
    try {
      const body = await buildPaymentBody(input)
      const { data } = await api.POST('/barberias/{barberia}/bonos/{bono}/compras', {
        params: { path: { barberia: input.shopId, bono: input.voucherId } },
        body,
      })
      const created = data!.data
      payment.value = created
      paymentId.value = created.id
      // Only one payment is resumed after a redirect: the latest one.
      writeStoredPayment(null)
      // A payment refused right away is not resumed: the person picks again.
      const refused = created.estado === 'rechazado' || created.estado === 'fallido'
      writeStoredVoucherPayment(
        refused
          ? null
          : {
              enlace: input.enlace,
              bono: input.voucherId,
              pago: created.id,
              metodo: created.metodo,
            },
      )
      const next = created.accion_siguiente
      if (created.estado === 'pendiente' && next?.tipo === 'redireccion') {
        redirecting.value = true
        if (!redirectTo(next.url)) redirecting.value = false
      }
      return created
    } catch (caught) {
      if (caught instanceof PaymentSetupError) error.value = setupApiError(caught)
      else error.value = isApiError(caught) ? caught : networkError(caught)
      return null
    } finally {
      submitting.value = false
    }
  }

  /** Takes up the payment saved for this voucher, if there is one. */
  function restore(enlace: string, voucherId: string): StoredVoucherPayment | null {
    const stored = readStoredVoucherPayment()
    if (stored === null || stored.enlace !== enlace || stored.bono !== voucherId) return null
    paymentId.value = stored.pago
    return stored
  }

  /** Reads the payment status from the API (which asks the gateway). Throws an ApiError. */
  async function refresh(shopId: string): Promise<Payment | null> {
    if (paymentId.value === null) return null
    const { data } = await api.GET('/barberias/{barberia}/pagos/{pago}', {
      params: { path: { barberia: shopId, pago: paymentId.value } },
    })
    payment.value = data!.data
    // A refused payment is not resumed: the person picks again.
    if (payment.value.estado === 'rechazado' || payment.value.estado === 'fallido') {
      writeStoredVoucherPayment(null)
    }
    return payment.value
  }

  /** Forgets the payment (after a rejection or when leaving) so another can be made. */
  function reset(): void {
    payment.value = null
    paymentId.value = null
    redirecting.value = false
    error.value = null
    writeStoredVoucherPayment(null)
  }

  return { payment, paymentId, submitting, redirecting, error, submit, restore, refresh, reset }
}
