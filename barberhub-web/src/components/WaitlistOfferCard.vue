<script setup lang="ts">
import { computed } from 'vue'

import BaseButton from '@/components/BaseButton.vue'
import { dateOf, formatShortDate, timeOf } from '@/composables/format'
import { useDeadline } from '@/composables/useDeadline'
import type { CloseReason, Offer, OfferPhase } from '@/stores/offers'

interface Props {
  offer: Offer
  phase: OfferPhase
  /** How it ended, when `phase` is `closed`. */
  reason?: CloseReason | null
  /** Why the last answer failed. */
  error?: string | null
  serviceName?: string
  /** Empty when the name is not known: the offer is then shown without it. */
  barberName?: string
  /** The name of the shop, for the pop-up that shows an offer of any shop. */
  shopName?: string
  /** The person has a voucher that covers the service: «Usar bono» is offered. */
  canUseVoucher?: boolean
  /** Level of the title, so the card fits the heading order of the screen that shows it. */
  headingTag?: 'h2' | 'h3'
}

const {
  offer,
  phase,
  reason = null,
  error = null,
  serviceName = '',
  barberName = '',
  shopName = '',
  canUseVoucher = false,
  headingTag = 'h2',
} = defineProps<Props>()

const emit = defineEmits<{
  accept: []
  'use-voucher': []
  reject: []
  dismiss: []
}>()

const deadline = useDeadline(() => offer.vence_en)

const busy = computed(() => phase === 'accepting' || phase === 'rejecting')
/** The time ran out here, though the API has not closed the offer yet: it may not be answered. */
const timedOut = computed(() => phase === 'open' && deadline.expired.value)

const CLOSED_TEXT: Record<CloseReason, { title: string; description: string }> = {
  vencida: {
    title: 'La oferta venció.',
    description:
      'Pasó el plazo para responder y el espacio se ofreció a otra persona. Si sigue en la fila, le avisaremos si se libera otro.',
  },
  aceptada: {
    title: 'Esta oferta ya fue aceptada.',
    description: 'Puede ver la reserva en «Mis reservas».',
  },
  rechazada: {
    title: 'Esta oferta se rechazó.',
    description: 'Su lugar en la fila se mantiene.',
  },
  'ya-no-disponible': {
    title: 'Esta oferta ya no está disponible.',
    description: 'El espacio ya no se le ofrece. Su lugar en la fila se mantiene.',
  },
}

const closedText = computed(() => CLOSED_TEXT[reason ?? 'ya-no-disponible'])

const what = computed(() => {
  const service = serviceName === '' ? 'Un espacio' : serviceName
  return barberName === '' ? service : `${service} con ${barberName}`
})
const when = computed(
  () => `${formatShortDate(dateOf(offer.inicio))} · ${timeOf(offer.inicio)}–${timeOf(offer.fin)}`,
)
</script>

<template>
  <div class="flex flex-col gap-4">
    <template v-if="phase === 'closed' || timedOut">
      <div class="flex flex-col gap-1">
        <component :is="headingTag" class="text-lg font-medium">
          {{ timedOut ? CLOSED_TEXT.vencida.title : closedText.title }}
        </component>
        <p class="text-sm text-fg-muted">
          {{ timedOut ? CLOSED_TEXT.vencida.description : closedText.description }}
        </p>
      </div>
      <div v-if="phase === 'closed'" class="flex flex-wrap gap-2">
        <BaseButton @click="emit('dismiss')">Entendido</BaseButton>
      </div>
    </template>

    <template v-else>
      <div class="flex flex-col gap-1">
        <component :is="headingTag" class="text-lg font-medium">Se liberó un espacio</component>
        <p class="font-medium">{{ what }}</p>
        <p class="text-sm text-fg-muted">{{ when }}</p>
        <p v-if="shopName !== ''" class="text-sm text-fg-muted">{{ shopName }}</p>
      </div>

      <div class="flex flex-col gap-1">
        <p role="timer" class="text-sm tabular-nums">
          <span class="text-fg-muted">Tiempo para responder </span>
          <span class="font-medium">{{ deadline.formatted.value }}</span>
        </p>
        <p v-if="deadline.urgent.value" class="text-sm text-warning">
          Quedan menos de dos minutos para responder.
        </p>
      </div>

      <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>

      <div class="flex flex-wrap gap-2">
        <BaseButton :loading="phase === 'accepting'" :disabled="busy" @click="emit('accept')">
          Aceptar
        </BaseButton>
        <BaseButton
          v-if="canUseVoucher"
          variant="secondary"
          :disabled="busy"
          @click="emit('use-voucher')"
        >
          Usar bono
        </BaseButton>
        <BaseButton
          variant="secondary"
          :loading="phase === 'rejecting'"
          :disabled="busy"
          @click="emit('reject')"
        >
          Rechazar
        </BaseButton>
      </div>
    </template>
  </div>
</template>
