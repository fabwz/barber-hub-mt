<script setup lang="ts">
import { computed, onMounted, ref, useTemplateRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowRight,
  Calendar,
  ChevronLeft,
  CreditCard,
  Lock,
  Smartphone,
  Wallet,
} from 'lucide-vue-next'

import { api, isApiError, type components } from '@/api'
import BaseButton from '@/components/BaseButton.vue'
import BookingSummary from '@/components/BookingSummary.vue'
import CardForm from '@/components/CardForm.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import InlineNotice from '@/components/InlineNotice.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import OptionCard from '@/components/OptionCard.vue'
import OptionGroup from '@/components/OptionGroup.vue'
import PaymentStatus from '@/components/PaymentStatus.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import SinpeForm from '@/components/SinpeForm.vue'
import SinpeInstructions from '@/components/SinpeInstructions.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import { colones, dateOf, secondsUntil, timeOf } from '@/composables/format'
import { HEADER_EXAMPLE } from '@/composables/shopHeaderExample'
import { useCountdown } from '@/composables/useCountdown'
import { CARD_REJECTED, usePayment, type PaymentMethod } from '@/composables/usePayment'
import { usePaymentPolling } from '@/composables/usePaymentPolling'
import { useBookingStore } from '@/stores/booking'
import { useSessionStore } from '@/stores/session'
import { useShopStore } from '@/stores/shop'

type CustomerReservation = components['schemas']['ReservaDetalleDeCliente']
type LoadState = 'loading' | 'ready' | 'not-found' | 'error'

/** Two minutes: below this the countdown warns in the page. */
const WARNING_SECONDS = 120

const METHODS: Record<PaymentMethod, { label: string; icon: typeof CreditCard }> = {
  tarjeta: { label: 'Tarjeta', icon: CreditCard },
  sinpe_movil: { label: 'SINPE Móvil', icon: Smartphone },
}

const route = useRoute()
const router = useRouter()
const shopStore = useShopStore()
const session = useSessionStore()
const booking = useBookingStore()

const enlace = computed(() => String(route.params.enlace))
const reservationId = computed(() => String(route.params.reserva))
const cancelled = computed(() => route.query.cancelado === '1')

const state = ref<LoadState>('loading')
const reservation = ref<CustomerReservation | null>(null)
const method = ref<string | null>(null)
const methodError = ref('')

const payments = usePayment()
const countdown = useCountdown()
const counting = ref(false)

const cardForm = useTemplateRef<InstanceType<typeof CardForm>>('cardForm')
const sinpeForm = useTemplateRef<InstanceType<typeof SinpeForm>>('sinpeForm')

const shopId = computed(() => shopStore.shop?.id ?? '')
const shopName = computed(() => shopStore.shop?.profile.nombre ?? '')

const expired = computed(
  () =>
    reservation.value?.estado === 'expirada' || (counting.value && countdown.remaining.value === 0),
)
const expiring = computed(
  () =>
    counting.value && countdown.remaining.value > 0 && countdown.remaining.value <= WARNING_SECONDS,
)

const methods = computed<PaymentMethod[]>(() =>
  (reservation.value?.metodos_de_pago ?? []).filter(
    (item): item is PaymentMethod => item === 'tarjeta' || item === 'sinpe_movil',
  ),
)
watch(
  methods,
  (list) => {
    // A single method is already chosen; with several, the person chooses.
    if (list.length === 1) method.value = list[0]!
    else if (method.value !== null && !list.includes(method.value as PaymentMethod)) {
      method.value = null
    }
  },
  { immediate: true },
)

/** Amounts come from the API as they are: the client never calculates them. */
const amountToPay = computed(() => reservation.value?.monto_a_pagar ?? 0)
const hasDeposit = computed(
  () =>
    reservation.value !== null &&
    amountToPay.value > 0 &&
    amountToPay.value < reservation.value.precio,
)

const methodNote = computed(() => {
  if (method.value === 'sinpe_movil') {
    return 'Al pagar, ONVO Pay le indica cómo completar el SINPE Móvil desde la app de su banco.'
  }
  if (reservation.value?.pasarela === 'paypal') {
    return 'Al pagar, se abre PayPal para que confirme el pago.'
  }
  return 'Tarjeta de crédito o débito, con ONVO Pay.'
})
/** With PayPal the card is typed on PayPal's page, so the tile and the button say PayPal. */
const viaPayPal = computed(() => reservation.value?.pasarela === 'paypal')
function methodLabel(item: PaymentMethod): string {
  return item === 'tarjeta' && viaPayPal.value ? 'PayPal' : METHODS[item].label
}
function methodIcon(item: PaymentMethod) {
  return item === 'tarjeta' && viaPayPal.value ? Wallet : METHODS[item].icon
}
const payVerb = computed(() =>
  viaPayPal.value && method.value === 'tarjeta' ? 'Continuar a PayPal' : 'Pagar',
)
/** On phone the amount goes in the button; on desktop it is next to it. */
const amountInButton = computed(() => payVerb.value === 'Pagar')
const payNote = computed(() =>
  reservation.value?.pasarela === 'paypal'
    ? 'Pago protegido por PayPal'
    : 'Pago seguro con ONVO Pay',
)

const payment = payments.payment
// The contract declares the status as a plain string: only the known ones reach the screen.
const PAYMENT_STATES = ['pendiente', 'aprobado', 'rechazado', 'fallido', 'reembolsado'] as const
const paymentState = computed<(typeof PAYMENT_STATES)[number] | null>(
  () => PAYMENT_STATES.find((known) => known === payment.value?.estado) ?? null,
)
const pending = computed(
  () =>
    payments.paymentId.value !== null &&
    (payment.value === null || paymentState.value === 'pendiente'),
)

const polling = usePaymentPolling({
  active: () => pending.value && !expired.value,
  check: () => payments.refresh(shopId.value),
})

const instructions = computed(() => {
  const next = payment.value?.accion_siguiente
  return next?.tipo === 'instrucciones' && paymentState.value === 'pendiente' ? next : null
})

/** What the page shows once the reservation is loaded. */
const phase = computed<'approved' | 'expired' | 'payment' | 'free' | 'unavailable' | 'form'>(() => {
  if (paymentState.value === 'aprobado' || paymentState.value === 'reembolsado') return 'approved'
  if (expired.value) return 'expired'
  if (cardRejected.value) return 'form'
  if (payments.paymentId.value !== null || payment.value !== null) return 'payment'
  if (amountToPay.value === 0) return 'free'
  if (methods.value.length === 0) return 'unavailable'
  return 'form'
})

const busy = computed(() => payments.submitting.value || payments.redirecting.value)

const fieldErrors = computed(() => {
  const errors = payments.error.value?.errors ?? {}
  const first = (name: string): string | undefined => errors[name]?.join(' ')
  return {
    phone: first('telefono'),
    idType: first('tipo_identificacion'),
    identification: first('identificacion'),
  }
})
const hasFieldErrors = computed(() => Object.values(fieldErrors.value).some(Boolean))
// A card ONVO refused is asked for again in the form, with the message of the design.
const cardRejected = computed(
  () =>
    (paymentState.value === 'rechazado' || paymentState.value === 'fallido') &&
    payment.value?.metodo === 'tarjeta' &&
    reservation.value?.pasarela === 'onvo',
)
// After a reload the rejected card comes back from the storage with no method chosen: choose it.
watch(cardRejected, (rejected) => {
  if (rejected) method.value = 'tarjeta'
})
const cardError = computed(() => {
  if (payments.error.value?.type === 'tarjeta-rechazada') return payments.error.value.detail
  return cardRejected.value ? CARD_REJECTED : ''
})
const generalError = computed(() => {
  const error = payments.error.value
  if (error === null || error.type === 'tarjeta-rechazada' || hasFieldErrors.value) return ''
  return error.detail
})

const announcement = computed(() => {
  if (state.value === 'loading') return 'Cargando el resumen.'
  if (phase.value === 'expired') return 'La reserva venció.'
  if (busy.value) return 'Procesando pago.'
  if (paymentState.value === 'aprobado') return 'Su pago fue aprobado.'
  if (paymentState.value === 'rechazado' || paymentState.value === 'fallido') {
    return 'El pago no se pudo completar.'
  }
  if (pending.value) return 'Esperando la confirmación del pago.'
  // Changes only when it enters the last two minutes: never once per second.
  if (expiring.value) return 'Quedan menos de dos minutos para pagar.'
  return ''
})

function startCountdown(detail: CustomerReservation): void {
  counting.value = false
  countdown.stop()
  if (detail.estado === 'temporal' && detail.vence_en !== null) {
    countdown.start(secondsUntil(detail.vence_en))
    counting.value = true
  }
}

async function load(): Promise<void> {
  state.value = 'loading'
  await shopStore.load(enlace.value)
  if (shopStore.status === 'not-found') {
    state.value = 'not-found'
    return
  }
  if (shopStore.status !== 'ready') {
    state.value = 'error'
    return
  }
  try {
    const { data } = await api.GET('/barberias/{barberia}/reservas/{reserva}', {
      params: { path: { barberia: shopId.value, reserva: reservationId.value } },
    })
    const detail = data!.data
    // The view of the shop's agenda has no amount to pay: it is not a customer's reservation.
    if (!('monto_a_pagar' in detail)) {
      state.value = 'not-found'
      return
    }
    reservation.value = detail
    startCountdown(detail)
    state.value = 'ready'
    await resume()
  } catch (error) {
    state.value = isApiError(error) && error.status === 404 ? 'not-found' : 'error'
  }
}

/** Takes up the payment saved for this reservation (reload, return from PayPal) and reads its status. */
async function resume(): Promise<void> {
  if (cancelled.value) {
    // The person gave up on the provider's page: nothing to wait for, they choose again.
    payments.reset()
    return
  }
  if (payments.restore(enlace.value, reservationId.value) !== null) {
    await polling.checkNow()
  }
}

async function pay(): Promise<void> {
  if (busy.value || reservation.value === null) return
  methodError.value = ''
  if (method.value === null) {
    methodError.value = 'Elija un método de pago.'
    return
  }
  const current = method.value as PaymentMethod
  const gateway = reservation.value.pasarela === 'paypal' ? 'paypal' : 'onvo'

  const card = current === 'tarjeta' && gateway === 'onvo' ? cardForm.value?.collect() : undefined
  const sinpe = current === 'sinpe_movil' ? sinpeForm.value?.collect() : undefined
  if (
    (current === 'tarjeta' && gateway === 'onvo' && !card) ||
    (current === 'sinpe_movil' && !sinpe)
  ) {
    return
  }

  await payments.submit({
    shopId: shopId.value,
    enlace: enlace.value,
    reservationId: reservationId.value,
    method: current,
    gateway,
    card: card ?? undefined,
    sinpe: sinpe ?? undefined,
    billingEmail: session.person?.email ?? null,
    onCardUsed: () => cardForm.value?.reset(),
  })
}

/** After a rejection or a cancellation: forget that payment and show the methods again. */
function chooseAgain(): void {
  payments.reset()
  method.value = methods.value.length === 1 ? methods.value[0]! : null
}

const editing = ref(false)

/**
 * «Cambiar servicio u hora»: only offered while the reservation is temporary, unpaid and in time. It
 * cancels it first so the space is free again, then goes back to the selection.
 */
async function editReservation(): Promise<void> {
  if (editing.value) return
  editing.value = true
  try {
    await booking.release(shopId.value, reservationId.value)
  } finally {
    editing.value = false
  }
  chooseAnotherTime()
}

function chooseAnotherTime(): void {
  booking.clear()
  void router.push({ name: 'shop', params: { enlace: enlace.value } })
}

onMounted(() => void load())
</script>

<template>
  <div class="mx-auto flex min-h-screen w-full max-w-7xl flex-col">
    <!-- Phone: a light header with the way back and the name of the shop (6n–6q) -->
    <header class="flex flex-none items-center gap-2 px-5 pt-4 pb-2 md:hidden">
      <RouterLink
        :to="{ name: 'shop', params: { enlace } }"
        class="-ml-3 inline-flex min-h-11 items-center gap-1 rounded-pill pr-4 pl-2.5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-surface"
      >
        <ChevronLeft :size="18" :stroke-width="1.5" aria-hidden="true" />
        Volver
      </RouterLink>
      <span class="ml-auto text-sm font-medium">{{ shopName }}</span>
    </header>

    <!-- Desktop: the same header of the shop as the booking page (9a–9d) -->
    <div v-if="shopStore.shop" class="hidden border-b border-line px-8 md:block">
      <ShopHeader
        class="border-b-0!"
        wide
        v-bind="HEADER_EXAMPLE"
        :name="shopStore.shop.profile.nombre"
        :city="shopStore.shop.profile.ciudad"
        :phone="shopStore.shop.profile.telefono"
      />
    </div>

    <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>

    <main
      class="flex flex-1 flex-col px-5 pt-4 pb-8 md:px-8 md:pt-8"
      :aria-busy="state === 'loading' || busy ? 'true' : undefined"
    >
      <div class="md:rounded-card md:border md:border-line md:p-8">
        <div class="flex items-center justify-between gap-4 md:border-b md:border-line md:pb-6">
          <h1 class="text-2xl">Resumen y pago</h1>
          <RouterLink
            :to="{ name: 'shop', params: { enlace } }"
            class="-mr-3 hidden min-h-11 items-center gap-1 rounded-pill pr-4 pl-2.5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-surface md:inline-flex"
          >
            <ChevronLeft :size="18" :stroke-width="1.5" aria-hidden="true" />
            Volver
          </RouterLink>
        </div>

        <!-- Loading -->
        <div v-if="state === 'loading'" class="mt-6 flex flex-col gap-6">
          <SkeletonBlock shape="block" label="Cargando el resumen" />
          <SkeletonBlock shape="row" :count="2" label="Cargando los métodos de pago" />
        </div>

        <!-- Empty: there is no such reservation -->
        <template v-else-if="state === 'not-found'">
          <div class="mt-12 border-y border-line md:hidden">
            <EmptyState
              title="No tiene una cita en curso"
              description="Elija servicio, barbero, fecha y hora. El resumen y el pago aparecen aquí."
            >
              <template #action>
                <BaseButton size="large" @click="chooseAnotherTime">Reservar cita</BaseButton>
              </template>
            </EmptyState>
          </div>
          <div class="hidden flex-col items-center gap-4 px-8 py-12 text-center md:flex">
            <div
              class="grid size-14 place-items-center rounded-full border border-line-strong bg-surface"
              aria-hidden="true"
            >
              <Calendar :size="24" :stroke-width="1.5" class="text-fg-muted" />
            </div>
            <div>
              <h2 class="text-lg font-medium">No tiene una cita en curso</h2>
              <p class="mt-1 max-w-95 text-sm text-fg-muted">
                Elija servicio, barbero, fecha y hora. El resumen y el pago aparecen aquí.
              </p>
            </div>
            <BaseButton size="large" @click="chooseAnotherTime">Reservar cita</BaseButton>
          </div>
        </template>

        <!-- Error -->
        <div
          v-else-if="state === 'error'"
          class="mt-6 rounded-card border border-danger-border bg-danger-surface"
        >
          <ErrorState message="Error: no pudimos cargar su reserva." @retry="load" />
        </div>

        <div
          v-else-if="reservation"
          class="mt-6 flex flex-col gap-6 md:grid md:grid-cols-2 md:gap-8"
        >
          <!-- Left on desktop: the appointment -->
          <div class="flex min-w-0 flex-col gap-4">
            <h2 class="hidden text-lg font-medium md:block">Su cita</h2>
            <BookingSummary
              :service-name="reservation.servicio_nombre"
              :barber-name="reservation.barbero_nombre"
              :date="dateOf(reservation.inicio)"
              :start-time="timeOf(reservation.inicio)"
              :end-time="timeOf(reservation.fin)"
              :price="reservation.precio"
              :duration-minutes="reservation.duracion_minutos"
              :status="
                reservation.estado === 'temporal'
                  ? 'temporary'
                  : reservation.estado === 'confirmada'
                    ? 'confirmed'
                    : 'draft'
              "
              :remaining="counting && !expired ? countdown.formatted.value : undefined"
            />

            <dl
              v-if="hasDeposit"
              class="flex flex-col gap-2 rounded-card border border-line bg-surface p-4 text-base"
            >
              <div class="flex justify-between gap-6">
                <dt class="font-medium">Depósito a pagar ahora</dt>
                <dd class="text-right font-medium tabular-nums">
                  {{ colones(reservation.monto_a_pagar) }}
                </dd>
              </div>
              <div class="flex justify-between gap-6">
                <dt class="text-fg-muted">Saldo en el local</dt>
                <dd class="text-right tabular-nums">{{ colones(reservation.saldo_en_local) }}</dd>
              </div>
            </dl>

            <InlineNotice
              v-if="expiring && phase !== 'approved'"
              title="Quedan menos de dos minutos."
            >
              Complete el pago antes de que su espacio se libere.
            </InlineNotice>

            <div class="flex flex-col items-start">
              <button
                v-if="phase === 'form'"
                type="button"
                :disabled="editing"
                class="-ml-3 inline-flex min-h-11 items-center gap-1.5 rounded-pill px-3 text-sm font-medium text-fg transition-colors duration-150 ease-out hover:bg-surface disabled:cursor-progress disabled:opacity-50"
                @click="editReservation"
              >
                <span>Cambiar servicio u hora</span>
                <ArrowRight :size="16" :stroke-width="1.5" class="flex-none" aria-hidden="true" />
              </button>
              <p
                v-if="reservation.plazo_cancelacion_horas > 0"
                class="mt-2 max-w-110 text-sm text-fg-subtle"
              >
                Puede cancelar o cambiar la hora hasta
                {{ reservation.plazo_cancelacion_horas }}
                {{ reservation.plazo_cancelacion_horas === 1 ? 'hora' : 'horas' }} antes de la cita.
                Cada barbería define este plazo.
              </p>
            </div>
          </div>

          <!-- Right on desktop: how it is paid -->
          <div class="flex min-w-0 flex-col gap-6 md:border-l md:border-line md:pl-8">
            <!-- Expired reservation -->
            <div v-if="phase === 'expired'" class="border-y border-line">
              <!-- TEMPORAL hasta el #84: la pantalla final de una reserva vencida. -->
              <EmptyState
                title="La reserva temporal venció"
                description="El espacio volvió a estar disponible. Elija una hora de nuevo."
              >
                <template #action>
                  <BaseButton @click="chooseAnotherTime">Elegir otro horario</BaseButton>
                </template>
              </EmptyState>
            </div>

            <!-- Nothing to pay -->
            <div v-else-if="phase === 'free'">
              <NoticeBanner>
                <p class="font-medium">Esta reserva no requiere pago.</p>
              </NoticeBanner>
            </div>

            <div v-else-if="phase === 'unavailable'">
              <NoticeBanner variant="error">
                <p class="font-medium">No hay métodos de pago disponibles.</p>
                <p class="mt-1 text-fg-muted">Intente más tarde o consulte con la barbería.</p>
              </NoticeBanner>
            </div>

            <!-- Waiting for the payment, or its final state -->
            <div
              v-else-if="phase === 'payment' || phase === 'approved'"
              class="flex flex-col gap-6"
            >
              <SinpeInstructions
                v-if="instructions"
                :destination="instructions.numero_destino"
                :amount="instructions.monto ?? payment?.monto ?? amountToPay"
              />
              <PaymentStatus
                :state="paymentState"
                :amount="payment?.monto ?? amountToPay"
                :reason="payment?.motivo"
                :refund-pending="payment?.devolucion_pendiente"
                :unreachable="polling.error.value !== null"
                :checking="polling.checking.value"
                @check="polling.checkNow()"
                @retry="chooseAgain"
              />
            </div>

            <!-- Method and form -->
            <form
              v-else
              id="payment-form"
              class="flex flex-col gap-6"
              novalidate
              @submit.prevent="pay"
            >
              <InlineNotice v-if="cancelled" title="El pago se canceló.">
                Puede intentarlo de nuevo o elegir otro método.
              </InlineNotice>

              <section aria-labelledby="method-title">
                <h2 id="method-title" class="mb-4 text-lg font-medium">Método de pago</h2>
                <OptionGroup
                  v-model="method"
                  aria-label="Método de pago"
                  class="grid! grid-cols-3 gap-2!"
                >
                  <OptionCard
                    v-for="item in methods"
                    :key="item"
                    :value="item"
                    variant="tile"
                    :icon="methodIcon(item)"
                    :disabled="busy"
                  >
                    {{ methodLabel(item) }}
                  </OptionCard>
                </OptionGroup>
                <p v-if="methodError" class="mt-3 text-sm text-danger" role="alert">
                  {{ methodError }}
                </p>
                <div
                  v-else-if="method && reservation.pasarela === 'paypal'"
                  class="mt-6 border-y border-line py-4"
                >
                  <p class="font-medium">Pago con PayPal</p>
                  <p class="mt-1 max-w-110 text-sm text-fg-muted">{{ methodNote }}</p>
                </div>
                <p v-else-if="method" class="mt-3 text-sm text-fg-subtle">{{ methodNote }}</p>
              </section>

              <div :class="busy ? 'opacity-60' : ''">
                <CardForm
                  v-if="method === 'tarjeta' && reservation.pasarela === 'onvo'"
                  ref="cardForm"
                  :disabled="busy"
                  :error="cardError"
                />
                <SinpeForm
                  v-else-if="method === 'sinpe_movil'"
                  ref="sinpeForm"
                  :disabled="busy"
                  :errors="fieldErrors"
                />
              </div>

              <NoticeBanner v-if="generalError" variant="error">
                <p class="font-medium">No se pudo realizar el pago.</p>
                <p class="mt-1 text-fg-muted">{{ generalError }}</p>
              </NoticeBanner>
            </form>
          </div>

          <!-- Pay bar: stuck to the bottom on phone; on desktop it closes the panel with the total and the button -->
          <div
            v-if="phase === 'form'"
            class="flex flex-col gap-3 border-t border-line pt-4 max-md:sticky max-md:bottom-0 max-md:z-10 max-md:-mx-5 max-md:-mb-8 max-md:bg-canvas max-md:px-5 max-md:pb-6 md:col-span-2 md:flex-row md:items-center md:justify-between md:gap-8 md:pt-6"
          >
            <span
              class="flex items-center gap-2 text-sm text-fg-subtle max-md:justify-center md:flex-1"
            >
              <Lock
                :size="16"
                :stroke-width="1.5"
                class="hidden flex-none md:block"
                aria-hidden="true"
              />
              {{ payNote }}
            </span>
            <span class="hidden items-baseline gap-3 text-base whitespace-nowrap md:flex">
              <span class="text-fg-muted">{{ hasDeposit ? 'A pagar ahora' : 'Total' }}</span>
              <span class="tabular-nums">{{ colones(amountToPay) }}</span>
            </span>
            <BaseButton
              type="submit"
              form="payment-form"
              size="large"
              class="w-full text-base! tabular-nums md:w-70"
              :class="busy ? 'animate-pulse cursor-progress motion-reduce:animate-none' : ''"
              :disabled="busy"
              :aria-busy="busy ? 'true' : undefined"
            >
              <template v-if="busy">Procesando pago…</template>
              <template v-else
                >{{ payVerb
                }}<span v-if="amountInButton" class="md:hidden">{{
                  ' ' + colones(amountToPay)
                }}</span></template
              >
            </BaseButton>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>
