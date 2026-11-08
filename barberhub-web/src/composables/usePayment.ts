import { ref } from 'vue'

import { api, ApiError, isApiError, type components } from '@/api'
import { networkError } from '@/api/errors'

export type Payment = components['schemas']['Pago']
export type PaymentMethod = 'tarjeta' | 'sinpe_movil'

/** The pending payment lives in sessionStorage under this key. Never card data. */
export const PAYMENT_STORAGE_KEY = 'barberhub.payment'

const ONVO_DEFAULT_URL = 'https://api.onvopay.com/v1'
const ONVO_TEST_KEY_PREFIX = 'onvo_test_'

/** Text of the design for a card that was not accepted. */
export const CARD_REJECTED =
  'Su tarjeta ha sido rechazada. Por favor, pruebe con otra tarjeta o pague con SINPE Móvil.'

/** The only things kept to resume a payment after a redirect or a reload. */
export interface StoredPayment {
  enlace: string
  reserva: string
  pago: string
  metodo: string
}

/** What the person typed in the card form. It lives only inside one call and is never stored. */
export interface CardDetails {
  number: string
  expMonth: number
  expYear: number
  cvv: string
  holderName: string
}

export interface SinpeDetails {
  phone: string
  idType: string
  identification: string
}

export interface SubmitInput {
  /** Identifier of the shop for the `/barberias/{barberia}/...` routes. */
  shopId: string
  /** Public link of the shop (the `:enlace` of the route), kept to come back after a redirect. */
  enlace: string
  reservationId: string
  method: PaymentMethod
  gateway: 'onvo' | 'paypal'
  card?: CardDetails
  sinpe?: SinpeDetails
  /** Email of the session, sent to ONVO as billing data when known. */
  billingEmail?: string | null
  /** Called as soon as the card has been exchanged for a token, so the form can wipe it. */
  onCardUsed?: () => void
}

function text(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

export function readStoredPayment(): StoredPayment | null {
  try {
    const raw = globalThis.sessionStorage.getItem(PAYMENT_STORAGE_KEY)
    if (raw === null) return null
    const data: unknown = JSON.parse(raw)
    if (typeof data !== 'object' || data === null) return null
    const record = data as Record<string, unknown>
    const enlace = text(record.enlace)
    const reserva = text(record.reserva)
    const pago = text(record.pago)
    const metodo = text(record.metodo)
    return enlace && reserva && pago && metodo ? { enlace, reserva, pago, metodo } : null
  } catch {
    return null
  }
}

export function writeStoredPayment(value: StoredPayment | null): void {
  try {
    if (value === null) globalThis.sessionStorage.removeItem(PAYMENT_STORAGE_KEY)
    else globalThis.sessionStorage.setItem(PAYMENT_STORAGE_KEY, JSON.stringify(value))
  } catch {
    // No storage (blocked, private window): the payment is resumed only from memory.
  }
}

/** A payment that no longer changes by itself. */
export function isFinalPayment(payment: Payment): boolean {
  return payment.estado !== 'pendiente'
}

/** Goes to the page the API names (PayPal approval, 3DS). Only http(s) addresses are followed. */
export function redirectTo(url: string): boolean {
  try {
    const target = new URL(url)
    if (target.protocol !== 'https:' && target.protocol !== 'http:') return false
    globalThis.location.assign(target.toString())
    return true
  } catch {
    return false
  }
}

type SetupFailure = 'configuracion' | 'tarjeta-rechazada' | 'red'

class PaymentSetupError extends Error {
  readonly kind: SetupFailure
  constructor(kind: SetupFailure) {
    super(kind)
    this.name = 'PaymentSetupError'
    this.kind = kind
  }
}

function setupApiError(error: PaymentSetupError): ApiError {
  if (error.kind === 'configuracion') {
    return new ApiError({
      status: 0,
      type: 'configuracion',
      title: 'Error de configuración',
      detail:
        'El pago con tarjeta no está configurado. Avise a la barbería o pague con otro método.',
    })
  }
  if (error.kind === 'red') {
    return new ApiError({
      status: 0,
      type: 'red',
      title: 'Sin conexión',
      detail:
        'No se pudo comunicar con el procesador de pagos. Revise su conexión e intente de nuevo.',
    })
  }
  return new ApiError({
    status: 422,
    type: 'tarjeta-rechazada',
    title: 'Tarjeta no válida',
    detail: CARD_REJECTED,
  })
}

/**
 * Creates the payment method in ONVO straight from the browser with the public key. Our API never
 * sees the card: it only receives the id this returns. The card data is never logged or stored.
 */
export async function createOnvoPaymentMethod(
  card: CardDetails,
  billingEmail?: string | null,
): Promise<string> {
  const key = import.meta.env.VITE_ONVO_PUBLISHABLE_KEY ?? ''
  // A live key (or a missing one) never leaves the browser.
  if (!key.startsWith(ONVO_TEST_KEY_PREFIX)) throw new PaymentSetupError('configuracion')
  const baseUrl = (import.meta.env.VITE_ONVO_API_URL || ONVO_DEFAULT_URL).replace(/\/+$/, '')

  let response: Response
  try {
    // Not our `api` client: it would add our session token to a third-party request.
    response = await globalThis.fetch(`${baseUrl}/payment-methods`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${key}`, 'Content-Type': 'application/json' },
      credentials: 'omit',
      body: JSON.stringify({
        type: 'card',
        card: {
          number: card.number,
          expMonth: card.expMonth,
          expYear: card.expYear,
          cvv: card.cvv,
          holderName: card.holderName,
        },
        billing: {
          name: card.holderName,
          ...(billingEmail ? { email: billingEmail } : {}),
          address: { country: 'CR' },
        },
      }),
    })
  } catch {
    throw new PaymentSetupError('red')
  }
  if (!response.ok) throw new PaymentSetupError('tarjeta-rechazada')
  try {
    const body: unknown = await response.json()
    const id = typeof body === 'object' && body !== null ? (body as { id?: unknown }).id : null
    if (typeof id === 'string' && id !== '') return id
  } catch {
    // Falls through to the rejection below.
  }
  throw new PaymentSetupError('tarjeta-rechazada')
}

/**
 * Pays a temporary reservation (RF-20): creates the payment, keeps what is needed to resume it and
 * reads its status. The amount is never sent: the server calculates it.
 */
export function usePayment() {
  const payment = ref<Payment | null>(null)
  /** Id of the payment in progress, from the creation or restored from the storage. */
  const paymentId = ref<string | null>(null)
  const submitting = ref(false)
  const redirecting = ref(false)
  const error = ref<ApiError | null>(null)

  async function buildBody(input: SubmitInput): Promise<components['schemas']['PagarReserva']> {
    if (input.method === 'sinpe_movil' && input.sinpe) {
      return {
        metodo: 'sinpe_movil',
        telefono: input.sinpe.phone,
        identificacion: input.sinpe.identification,
        tipo_identificacion: input.sinpe
          .idType as components['schemas']['PagarReserva']['tipo_identificacion'],
      }
    }
    if (input.method === 'tarjeta' && input.gateway === 'onvo') {
      if (!input.card) throw new PaymentSetupError('tarjeta-rechazada')
      const token = await createOnvoPaymentMethod(input.card, input.billingEmail)
      input.onCardUsed?.()
      return { metodo: 'tarjeta', token }
    }
    // PayPal collects the card on its own page: only the method travels.
    return { metodo: input.method }
  }

  /**
   * Creates the payment. A double click does not create two: while a request is in flight the call
   * is ignored, and an active payment is resumed instead of asking for another one.
   */
  async function submit(input: SubmitInput): Promise<Payment | null> {
    if (submitting.value || redirecting.value) return null
    if (payment.value !== null && !isFinalPayment(payment.value)) return payment.value
    submitting.value = true
    error.value = null
    try {
      const body = await buildBody(input)
      const { data } = await api.POST('/barberias/{barberia}/reservas/{reserva}/pagos', {
        params: { path: { barberia: input.shopId, reserva: input.reservationId } },
        body,
      })
      // The contract declares the 200 body as a string and leaves out the 201: at runtime it is `{ data: Pago }`.
      const created = (data as unknown as { data: Payment }).data
      payment.value = created
      paymentId.value = created.id
      writeStoredPayment({
        enlace: input.enlace,
        reserva: input.reservationId,
        pago: created.id,
        metodo: created.metodo,
      })
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

  /** Takes up the payment saved for this reservation, if there is one. */
  function restore(enlace: string, reservationId: string): StoredPayment | null {
    const stored = readStoredPayment()
    if (stored === null || stored.enlace !== enlace || stored.reserva !== reservationId) return null
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
      writeStoredPayment(null)
    }
    return payment.value
  }

  /** Forgets the payment (after a rejection or a cancellation) so another can be made. */
  function reset(): void {
    payment.value = null
    paymentId.value = null
    redirecting.value = false
    error.value = null
    writeStoredPayment(null)
  }

  return { payment, paymentId, submitting, redirecting, error, submit, restore, refresh, reset }
}
