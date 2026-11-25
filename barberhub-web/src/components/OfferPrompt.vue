<script setup lang="ts">
import { computed, ref, useTemplateRef, watch } from 'vue'
import { X } from 'lucide-vue-next'

import WaitlistOfferCard from '@/components/WaitlistOfferCard.vue'
import { dateOf, formatShortDate, timeOf } from '@/composables/format'
import { useDeadline } from '@/composables/useDeadline'
import { useOfferActions } from '@/composables/useOfferActions'
import { OFFER_PROMPT_ID, useOfferNotices } from '@/composables/useOfferNotices'
import { canPayWith, useVouchers } from '@/composables/useVouchers'
import { useOffersStore } from '@/stores/offers'

// The pop-up of the offers (RF-35, NO-08): mounted once in the root, so it is on every screen. It is a
// dialog that is not modal and never takes the focus: the person may be typing a card number when the
// offer arrives. A live region announces it, «Ir al aviso» leads to it, and Escape only hides it.
useOfferNotices()

const offers = useOffersStore()
const actions = useOfferActions()
const vouchers = useVouchers()

const tracked = computed(() => offers.current)
const shop = computed(() => (tracked.value ? offers.shopOf(tracked.value.shopId) : undefined))

// The vouchers of the shop of the offer decide whether «Usar bono» is offered.
const vouchersOf = ref<string | null>(null)
watch(
  () => (tracked.value ? tracked.value.shopId : null),
  async (shopId) => {
    if (shopId === null) return
    await vouchers.load(shopId, 'mine')
    vouchersOf.value = shopId
  },
  { immediate: true },
)
const canUseVoucher = computed(
  () =>
    tracked.value !== null &&
    vouchersOf.value === tracked.value.shopId &&
    vouchers.result.value === 'ready' &&
    vouchers.mine.value.some((item) => canPayWith(item, tracked.value!.offer.servicio)),
)

// The names of the offer: the catalog of the shop and, when it can be known, the barber.
watch(
  () => tracked.value?.offer.id ?? null,
  (id) => {
    const item = tracked.value
    if (id === null || item === null) return
    void offers.ensureNames(
      item.shopId,
      item.offer.servicio,
      item.offer.inicio,
      offers.missesBarberName(item.offer),
    )
    // A shop that is not known yet is read once: it gives the name and the link of the screens.
    if (offers.shopOf(item.shopId) === undefined) void offers.loadShops()
  },
  { immediate: true },
)

const serviceName = computed(() =>
  tracked.value ? offers.serviceName(tracked.value.shopId, tracked.value.offer.servicio) : '',
)
const barberName = computed(() => (tracked.value ? offers.barberOf(tracked.value.offer) : ''))

// —— What the screen readers hear ——
const arrival = ref('')
const progress = ref('')
const deadline = useDeadline(() => tracked.value?.offer.vence_en ?? null)

watch(
  () => tracked.value?.offer.id ?? null,
  (id) => {
    const item = tracked.value
    if (id === null || item === null || item.phase !== 'open') return
    const where = shop.value ? ` en ${shop.value.name}` : ''
    arrival.value = `Se liberó un espacio${where} para el ${formatShortDate(dateOf(item.offer.inicio))} a las ${timeOf(item.offer.inicio)}. Puede aceptarlo o rechazarlo.`
  },
  { immediate: true },
)
// Once, on entering the last two minutes (never every second).
watch(
  () => deadline.urgent.value && tracked.value?.phase === 'open',
  (urgent) => {
    if (urgent) progress.value = 'Quedan menos de dos minutos para responder la oferta.'
  },
)
watch(
  () => (tracked.value?.phase === 'closed' ? tracked.value.reason : null),
  (reason) => {
    if (reason === null) return
    progress.value =
      reason === 'vencida'
        ? 'La oferta venció.'
        : reason === 'aceptada'
          ? 'La oferta ya fue aceptada.'
          : reason === 'rechazada'
            ? 'La oferta se rechazó.'
            : 'La oferta ya no está disponible.'
  },
)

// —— Focus: it is never taken; it is given back if it was inside when the pop-up leaves ——
const dialog = useTemplateRef<HTMLElement>('dialog')
let opener: HTMLElement | null = null

function onFocusIn(event: FocusEvent): void {
  const from = event.relatedTarget
  if (from instanceof HTMLElement && !dialog.value?.contains(from)) opener = from
}

function hide(): void {
  const item = tracked.value
  if (item === null) return
  const hadFocus = dialog.value?.contains(document.activeElement) ?? false
  offers.dismiss(item.offer.id)
  if (hadFocus) {
    if (opener !== null && opener.isConnected) opener.focus()
    else if (document.activeElement instanceof HTMLElement) document.activeElement.blur()
  }
  opener = null
}
</script>

<template>
  <div>
    <!-- Always in the page, so the readers have it when the text arrives. -->
    <p class="sr-only" aria-live="assertive" aria-atomic="true">{{ arrival }}</p>
    <p class="sr-only" aria-live="polite" aria-atomic="true">{{ progress }}</p>

    <section
      v-if="tracked"
      :id="OFFER_PROMPT_ID"
      ref="dialog"
      role="dialog"
      aria-label="Oferta de la lista de espera"
      tabindex="-1"
      class="fixed right-4 bottom-4 left-4 z-40 flex flex-col gap-3 rounded-card border border-line bg-surface p-5 md:left-auto md:w-96"
      @keydown.esc.stop="hide"
      @focusin="onFocusIn"
    >
      <button
        type="button"
        class="absolute top-2 right-2 inline-flex size-11 cursor-pointer items-center justify-center rounded-pill text-fg-muted transition-colors duration-150 ease-out hover:bg-elevated"
        aria-label="Ocultar el aviso"
        @click="hide"
      >
        <X :size="18" :stroke-width="1.5" aria-hidden="true" />
      </button>

      <WaitlistOfferCard
        class="pr-8"
        :offer="tracked.offer"
        :phase="tracked.phase"
        :reason="tracked.reason"
        :error="tracked.error"
        :service-name="serviceName"
        :barber-name="barberName"
        :shop-name="shop?.name ?? ''"
        :can-use-voucher="canUseVoucher"
        @accept="actions.accept(tracked.offer.id, false)"
        @use-voucher="actions.accept(tracked.offer.id, true)"
        @reject="actions.reject(tracked.offer.id)"
        @dismiss="hide"
      />

      <p v-if="offers.waiting > 0" class="text-sm text-fg-muted">
        {{
          offers.waiting === 1
            ? 'Tiene otra oferta esperando.'
            : `Tiene ${offers.waiting} ofertas más esperando.`
        }}
      </p>
      <RouterLink
        v-if="shop && shop.role === 'cliente'"
        :to="{ name: 'waitlist', params: { enlace: shop.link } }"
        class="inline-flex min-h-11 w-fit items-center text-sm font-medium text-fg"
      >
        Ver mis filas
      </RouterLink>
    </section>
  </div>
</template>
