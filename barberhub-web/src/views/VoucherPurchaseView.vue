<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { Check, CreditCard, Lock, Smartphone, Ticket, Wallet } from 'lucide-vue-next'

import BackLink from '@/components/BackLink.vue'
import BaseButton from '@/components/BaseButton.vue'
import CardForm from '@/components/CardForm.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import OptionCard from '@/components/OptionCard.vue'
import OptionGroup from '@/components/OptionGroup.vue'
import PaymentStatus from '@/components/PaymentStatus.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import SinpeForm from '@/components/SinpeForm.vue'
import SinpeInstructions from '@/components/SinpeInstructions.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import VoucherSummary from '@/components/VoucherSummary.vue'
import { colones } from '@/composables/format'
import { HEADER_EXAMPLE } from '@/composables/shopHeaderExample'
import { isFinalPayment, type PaymentMethod } from '@/composables/usePayment'
import { usePaymentPolling } from '@/composables/usePaymentPolling'
import { useVoucherPurchase } from '@/composables/useVoucherPurchase'
import { useVouchers, type PurchasedVoucher, type VoucherOnSale } from '@/composables/useVouchers'
import { useSessionStore } from '@/stores/session'
import { useShopStore } from '@/stores/shop'

type LoadState =
  'loading' | 'ready' | 'not-found' | 'forbidden' | 'disabled' | 'no-gateway' | 'error'

/** Automatic status checks (3 s apart, so 3 minutes) before the pending payment is left under verification. */
const MAX_AUTOMATIC_CHECKS = 60

const METHODS: Array<{ value: PaymentMethod; label: string; icon: typeof CreditCard }> = [
  { value: 'tarjeta', label: 'Tarjeta', icon: CreditCard },
  { value: 'sinpe_movil', label: 'SINPE Móvil', icon: Smartphone },
]

/** What the list on sale says the shop collects with now (`meta.pasarela`): never assumed. */
const gateway = computed(() => vouchers.gateway.value)
/** With PayPal the card is typed on PayPal's page, so the tile and the button say PayPal. */
const viaPayPal = computed(() => gateway.value === 'paypal')
/** The methods the gateway admits (`meta.metodos_de_pago`), in the order of the design. */
const methods = computed(() =>
  METHODS.filter((item) => vouchers.methods.value.includes(item.value)),
)
function methodLabel(item: (typeof METHODS)[number]): string {
  return item.value === 'tarjeta' && viaPayPal.value ? 'PayPal' : item.label
}
function methodIcon(item: (typeof METHODS)[number]) {
  return item.value === 'tarjeta' && viaPayPal.value ? Wallet : item.icon
}

const route = useRoute()
const shopStore = useShopStore()
const session = useSessionStore()
const vouchers = useVouchers()
const purchase = useVoucherPurchase()

const enlace = computed(() => String(route.params.enlace))
const voucherId = computed(() => String(route.params.bono))
const cancelled = computed(() => route.query.cancelado === '1')

const state = ref<LoadState>('loading')
const voucher = ref<VoucherOnSale | null>(null)
const method = ref<string | null>(null)
const methodError = ref('')
/** The voucher once active, read from «Mis bonos»; `null` while it is read or if that failed. */
const activated = ref<PurchasedVoucher | null>(null)

const cardForm = useTemplateRef<InstanceType<typeof CardForm>>('cardForm')
const sinpeForm = useTemplateRef<InstanceType<typeof SinpeForm>>('sinpeForm')
const title = useTemplateRef<HTMLElement>('title')

const shopId = computed(() => shopStore.shop?.id ?? '')
const shopName = computed(() => shopStore.shop?.profile.nombre ?? '')
const price = computed(() => voucher.value?.precio ?? 0)

const payment = purchase.payment
// The contract declares the status as a plain string: only the known ones reach the screen.
const PAYMENT_STATES = ['pendiente', 'aprobado', 'rechazado', 'fallido', 'reembolsado'] as const
const paymentState = computed<(typeof PAYMENT_STATES)[number] | null>(
  () => PAYMENT_STATES.find((known) => known === payment.value?.estado) ?? null,
)
const pending = computed(
  () =>
    purchase.paymentId.value !== null &&
    (payment.value === null || paymentState.value === 'pendiente'),
)

const polling = usePaymentPolling({
  active: () => pending.value,
  check: () => purchase.refresh(shopId.value),
  maxChecks: MAX_AUTOMATIC_CHECKS,
})
// The provider did not answer (503) or the wait ran out: the payment stays under verification.
const unreachable = computed(() => polling.error.value !== null || polling.exhausted.value)

/** Approved but the voucher could not be activated: a refund is pending (PA-07). */
const refundPending = computed(
  () => paymentState.value === 'aprobado' && payment.value?.devolucion_pendiente === true,
)

/** What the page shows once the voucher is loaded. The voucher is active only when the API says the payment is approved. */
const phase = computed<'activated' | 'payment' | 'form'>(() => {
  if (paymentState.value === 'aprobado' && !refundPending.value) return 'activated'
  if (purchase.paymentId.value !== null || payment.value !== null) return 'payment'
  return 'form'
})

const busy = computed(() => purchase.submitting.value || purchase.redirecting.value)

const instructions = computed(() => {
  const next = payment.value?.accion_siguiente
  return next?.tipo === 'instrucciones' && paymentState.value === 'pendiente' ? next : null
})

const fieldErrors = computed(() => {
  const errors = purchase.error.value?.errors ?? {}
  const first = (name: string): string | undefined => errors[name]?.join(' ')
  return {
    phone: first('telefono'),
    idType: first('tipo_identificacion'),
    identification: first('identificacion'),
  }
})
const hasFieldErrors = computed(() => Object.values(fieldErrors.value).some(Boolean))
// ONVO did not accept the card data before any charge: the form says so with the text of the design.
const cardError = computed(() =>
  purchase.error.value?.type === 'tarjeta-rechazada' ? purchase.error.value.detail : '',
)
const generalError = computed(() => {
  const error = purchase.error.value
  if (error === null || error.type === 'tarjeta-rechazada' || hasFieldErrors.value) return ''
  return error.detail
})

const methodNote = computed(() => {
  if (method.value === 'sinpe_movil') {
    return 'Al pagar, ONVO Pay le indica cómo completar el SINPE Móvil desde la app de su banco.'
  }
  if (viaPayPal.value) return 'Al pagar, se abre PayPal para que confirme el pago.'
  return 'Tarjeta de crédito o débito, con ONVO Pay.'
})
const payVerb = computed(() =>
  viaPayPal.value && method.value === 'tarjeta' ? 'Continuar a PayPal' : 'Pagar',
)
const payNote = computed(() =>
  viaPayPal.value ? 'Pago protegido por PayPal' : 'Pago seguro con ONVO Pay',
)

const announcement = computed(() => {
  if (state.value === 'loading') return 'Cargando el resumen.'
  if (busy.value) return 'Procesando pago.'
  if (phase.value === 'activated') return 'Bono activado.'
  if (paymentState.value === 'rechazado' || paymentState.value === 'fallido') return ''
  if (pending.value && !unreachable.value) return 'Esperando la confirmación del pago.'
  return ''
})

async function load(): Promise<void> {
  state.value = 'loading'
  await shopStore.load(enlace.value)
  if (shopStore.status === 'not-found') {
    state.value = 'not-found'
    return
  }
  if (shopStore.status !== 'ready' || shopStore.shop === null) {
    state.value = 'error'
    return
  }
  const result = await vouchers.load(shopStore.shop.id, 'sale')
  if (result === 'forbidden') {
    state.value = 'forbidden'
    return
  }
  if (result === 'error') {
    state.value = 'error'
    return
  }
  voucher.value = vouchers.onSale.value.find((item) => item.id === voucherId.value) ?? null
  if (!vouchers.enabled.value) state.value = 'disabled'
  else if (vouchers.gateway.value === null) state.value = 'no-gateway'
  else if (voucher.value === null) state.value = 'not-found'
  else {
    state.value = 'ready'
    await resume()
  }
}

/** Takes up the payment saved for this voucher (reload, return from the provider) and reads its status. */
async function resume(): Promise<void> {
  if (cancelled.value) {
    // The person gave up on the provider's page: nothing to wait for, they choose again.
    purchase.reset()
    return
  }
  if (purchase.restore(enlace.value, voucherId.value) !== null) {
    await polling.checkNow()
  }
}

async function pay(): Promise<void> {
  if (busy.value || voucher.value === null) return
  methodError.value = ''
  if (method.value === null) {
    methodError.value = 'Elija un método de pago.'
    return
  }
  const current = method.value as PaymentMethod

  const via = gateway.value
  if (via === null) return

  const card = current === 'tarjeta' && via === 'onvo' ? cardForm.value?.collect() : undefined
  const sinpe = current === 'sinpe_movil' ? sinpeForm.value?.collect() : undefined
  if ((current === 'tarjeta' && via === 'onvo' && !card) || (current === 'sinpe_movil' && !sinpe)) {
    return
  }

  await purchase.submit({
    shopId: shopId.value,
    enlace: enlace.value,
    voucherId: voucher.value.id,
    method: current,
    gateway: via,
    card: card ?? undefined,
    sinpe: sinpe ?? undefined,
    billingEmail: session.person?.email ?? null,
    onCardUsed: () => cardForm.value?.reset(),
  })
}

/** After a rejection: forget that payment and show the methods again. */
function chooseAgain(): void {
  purchase.reset()
  method.value = null
}

// The voucher is active: read it from «Mis bonos» to show its expiry. If that fails, what was on
// sale (name, sessions, services) is still shown and the expiry is left out.
watch(
  () => (phase.value === 'activated' ? payment.value?.bono_comprado : null),
  async (id) => {
    if (!id) return
    await vouchers.load(shopId.value, 'mine')
    activated.value = vouchers.mine.value.find((item) => item.id === id) ?? null
    await nextTick()
    title.value?.focus()
  },
)

// A payment that reached a final state is not resumed when the person comes back.
onBeforeUnmount(() => {
  if (payment.value !== null && isFinalPayment(payment.value)) purchase.reset()
})

onMounted(() => void load())
</script>

<template>
  <div class="mx-auto flex min-h-screen w-full max-w-7xl flex-col">
    <!-- Phone: a light header with the way back and the name of the shop -->
    <header class="flex flex-none items-center gap-2 px-5 pt-4 pb-2 md:hidden">
      <BackLink :to="{ name: 'vouchers', params: { enlace } }" class="-ml-3" />
      <span class="ml-auto text-sm font-medium">{{ shopName }}</span>
    </header>

    <!-- Desktop: the same header of the shop as the payment page -->
    <div v-if="shopStore.shop" class="hidden border-b border-line px-8 md:block">
      <ShopHeader
        class="border-b-0!"
        wide
        name-tag="p"
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
        <!-- Bono activado -->
        <div
          v-if="state === 'ready' && voucher && phase === 'activated'"
          class="flex flex-col gap-6 md:mx-auto md:w-full md:max-w-110"
        >
          <div class="flex items-start gap-3">
            <Check
              :size="28"
              :stroke-width="1.5"
              class="mt-0.5 flex-none text-accent"
              aria-hidden="true"
            />
            <div>
              <h1 ref="title" tabindex="-1" class="text-2xl outline-offset-4">Bono activado</h1>
              <p class="mt-2 text-fg-muted">
                Su pago de
                <span class="tabular-nums">{{ colones(payment?.monto ?? price) }}</span> fue
                aprobado y el bono ya está activo.
              </p>
            </div>
          </div>
          <VoucherSummary
            :name="activated?.nombre ?? voucher.nombre"
            :sessions="activated?.sesiones_totales ?? voucher.sesiones"
            :services="(activated?.servicios ?? voucher.servicios).map((item) => item.nombre)"
            :expires-at="activated?.vence_en ?? null"
          />
          <div class="flex flex-col gap-3 border-t border-line pt-6">
            <RouterLink
              :to="{ name: 'vouchers', params: { enlace } }"
              class="inline-flex min-h-12 items-center justify-center rounded-pill border border-line-strong bg-elevated px-5 text-base font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover"
            >
              Ver mis bonos
            </RouterLink>
            <RouterLink
              :to="{ name: 'shop', params: { enlace } }"
              class="inline-flex min-h-12 items-center justify-center rounded-pill border border-line-strong px-5 text-base font-normal text-fg no-underline transition-colors duration-150 ease-out hover:border-line-hover"
            >
              Volver a la barbería
            </RouterLink>
          </div>
        </div>

        <template v-else>
          <div class="flex items-center justify-between gap-4 md:border-b md:border-line md:pb-6">
            <h1 ref="title" tabindex="-1" class="text-2xl outline-offset-4">Resumen y pago</h1>
            <BackLink
              :to="{ name: 'vouchers', params: { enlace } }"
              class="-mr-3 hidden md:inline-flex"
            />
          </div>

          <!-- Loading -->
          <div v-if="state === 'loading'" class="mt-6 flex flex-col gap-6">
            <SkeletonBlock shape="block" label="Cargando el resumen" />
            <SkeletonBlock shape="row" :count="2" label="Cargando los métodos de pago" />
          </div>

          <!-- Owner or collaborator: nothing is offered to them -->
          <div v-else-if="state === 'forbidden'" class="mt-6 border-y border-line">
            <EmptyState
              title="Esta compra es para clientes"
              description="Como parte del equipo de esta barbería, no puede comprar bonos aquí."
            />
          </div>

          <!-- Vouchers turned off -->
          <div v-else-if="state === 'disabled'" class="mt-6 border-y border-line">
            <EmptyState
              title="Esta barbería no ofrece bonos por ahora"
              description="No puede comprar bonos aquí. Los que ya compró siguen visibles y puede usarlos."
            >
              <template #action>
                <RouterLink
                  :to="{ name: 'vouchers', params: { enlace } }"
                  class="inline-flex min-h-11 items-center justify-center rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover"
                >
                  Ver mis bonos
                </RouterLink>
              </template>
            </EmptyState>
          </div>

          <!-- The shop does not charge (no gateway): nothing can be bought, same state as an empty shelf -->
          <div v-else-if="state === 'no-gateway'" class="mt-6 border-y border-line">
            <EmptyState
              title="Esta barbería no tiene bonos a la venta"
              description="Cuando publique uno, aparece aquí."
            >
              <template #action>
                <RouterLink
                  :to="{ name: 'vouchers', params: { enlace } }"
                  class="inline-flex min-h-11 items-center justify-center rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover"
                >
                  Ver mis bonos
                </RouterLink>
              </template>
            </EmptyState>
          </div>

          <!-- Empty: there is no such voucher on sale -->
          <div v-else-if="state === 'not-found'" class="mt-6 border-y border-line">
            <EmptyState
              title="Este bono ya no está a la venta"
              description="Vea los bonos que la barbería tiene a la venta ahora."
            >
              <template #action>
                <RouterLink
                  :to="{ name: 'vouchers', params: { enlace } }"
                  class="inline-flex min-h-11 items-center justify-center rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover"
                >
                  Ver los bonos
                </RouterLink>
              </template>
            </EmptyState>
          </div>

          <!-- Error -->
          <ErrorState
            v-else-if="state === 'error'"
            class="mt-6"
            message="No pudimos cargar el bono."
            @retry="load"
          />

          <div v-else-if="voucher" class="mt-6 flex flex-col gap-6 md:grid md:grid-cols-2 md:gap-8">
            <!-- Left on desktop: the voucher -->
            <div class="flex min-w-0 flex-col gap-4">
              <h2 class="flex items-center gap-2 text-lg font-medium">
                <Ticket :size="18" :stroke-width="1.5" class="hidden md:block" aria-hidden="true" />
                Su bono
              </h2>
              <VoucherSummary
                :name="voucher.nombre"
                :sessions="voucher.sesiones"
                :validity-days="voucher.vigencia_dias"
                :services="voucher.servicios.map((item) => item.nombre)"
                :price="voucher.precio"
              />
              <p class="max-w-110 text-sm text-fg-subtle">
                El bono solo sirve en {{ shopName }}. Se paga el precio completo ahora: sin depósito
                ni saldo en el local.
              </p>
            </div>

            <!-- Right on desktop: how it is paid -->
            <div class="flex min-w-0 flex-col gap-6 md:border-l md:border-line md:pl-8">
              <!-- Waiting for the payment, or its final state -->
              <div v-if="phase === 'payment'" class="flex flex-col gap-6">
                <SinpeInstructions
                  v-if="instructions"
                  :destination="instructions.numero_destino"
                  :amount="instructions.monto ?? payment?.monto ?? price"
                />
                <PaymentStatus
                  subject="voucher"
                  :state="paymentState"
                  :amount="payment?.monto ?? price"
                  :reason="payment?.motivo"
                  :method="payment?.metodo"
                  :refund-pending="payment?.devolucion_pendiente"
                  :unreachable="unreachable"
                  :checking="polling.checking.value"
                  @check="polling.checkNow()"
                  @retry="chooseAgain"
                />
              </div>

              <!-- Method and form -->
              <form
                v-else
                id="purchase-form"
                class="flex flex-col gap-6"
                novalidate
                @submit.prevent="pay"
              >
                <section aria-labelledby="method-title">
                  <h2 id="method-title" class="mb-4 text-lg font-medium">Método de pago</h2>
                  <OptionGroup
                    v-model="method"
                    aria-label="Método de pago"
                    class="grid! gap-2!"
                    :class="methods.length > 1 ? 'grid-cols-2' : 'grid-cols-1'"
                  >
                    <OptionCard
                      v-for="item in methods"
                      :key="item.value"
                      :value="item.value"
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
                  <div v-else-if="method && viaPayPal" class="mt-6 border-y border-line py-4">
                    <p class="font-medium">Pago con PayPal</p>
                    <p class="mt-1 max-w-110 text-sm text-fg-muted">{{ methodNote }}</p>
                  </div>
                  <p v-else-if="method" class="mt-3 text-sm text-fg-subtle">{{ methodNote }}</p>
                </section>

                <div :class="busy ? 'opacity-60' : ''">
                  <CardForm
                    v-if="method === 'tarjeta' && gateway === 'onvo'"
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
                <span class="text-fg-muted">Total</span>
                <span class="tabular-nums">{{ colones(price) }}</span>
              </span>
              <BaseButton
                type="submit"
                form="purchase-form"
                size="large"
                class="w-full text-base! tabular-nums md:w-70"
                :class="busy ? 'animate-pulse cursor-progress motion-reduce:animate-none' : ''"
                :loading="busy"
              >
                <template v-if="busy">Procesando pago</template>
                <template v-else-if="payVerb === 'Pagar'"
                  >Pagar<span class="md:hidden">{{ ' ' + colones(price) }}</span></template
                >
                <template v-else>{{ payVerb }}</template>
              </BaseButton>
            </div>
          </div>
        </template>
      </div>
    </main>
  </div>
</template>
