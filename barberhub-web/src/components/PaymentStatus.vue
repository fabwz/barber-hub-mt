<script setup lang="ts">
import { computed, nextTick, onMounted, useTemplateRef, watch } from 'vue'
import { Clock } from 'lucide-vue-next'

import BaseButton from '@/components/BaseButton.vue'
import InlineNotice from '@/components/InlineNotice.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import StatusLabel from '@/components/StatusLabel.vue'
import { colones } from '@/composables/format'

interface Props {
  /** Status of the payment from the API; `null` while it is read for the first time. */
  state: 'pendiente' | 'aprobado' | 'rechazado' | 'fallido' | 'reembolsado' | null
  /** Amount charged, in colones. */
  amount?: number
  /** Reason of the rejection or failure, as the API gives it. */
  reason?: string | null
  /** How it was paid. A card refusal never shows the provider's text (DESIGN.md): it comes in its language. */
  method?: string | null
  /** The payment was approved when the reservation could no longer be confirmed (PA-07). */
  refundPending?: boolean
  /** The provider did not answer, or the wait ran out: the payment stays under verification. */
  unreachable?: boolean
  /** A status request is in flight. */
  checking?: boolean
}

const {
  state,
  amount = 0,
  reason = null,
  method = null,
  refundPending = false,
  unreachable = false,
  checking = false,
} = defineProps<Props>()

const emit = defineEmits<{
  check: []
  /** The person wants to choose a method again. */
  retry: []
  /** The reservation could not be confirmed: the person goes back to choose another space. */
  anotherSpace: []
}>()

type Kind =
  'refund' | 'verifying' | 'loading' | 'waiting' | 'approved' | 'rejected' | 'failed' | 'refunded'

const kind = computed<Kind>(() => {
  if (state === 'aprobado' && refundPending) return 'refund'
  if (unreachable && (state === null || state === 'pendiente')) return 'verifying'
  if (state === null) return 'loading'
  if (state === 'pendiente') return 'waiting'
  if (state === 'aprobado') return 'approved'
  if (state === 'rechazado') return 'rejected'
  if (state === 'fallido') return 'failed'
  return 'refunded'
})

/** «Motivo: …» with the reason of the API, or a generic sentence when it gives none. */
const rejection = computed(() => {
  if (method === 'tarjeta') return 'Motivo: su tarjeta fue rechazada.'
  const text = reason?.trim()
  if (!text) return 'El pago no se procesó y no se le cobró.'
  return `Motivo: ${/[.!?]$/.test(text) ? text : `${text}.`}`
})

// Moving to one of these screens takes the focus to its title, so a screen reader reads it.
const FOCUSED: Kind[] = ['refund', 'verifying', 'rejected', 'failed']

const root = useTemplateRef<HTMLElement>('root')
function focusTitle(): void {
  void nextTick(() => root.value?.querySelector<HTMLElement>('[data-status-title]')?.focus())
}
onMounted(() => {
  if (FOCUSED.includes(kind.value)) focusTitle()
})
watch(kind, (next) => {
  if (FOCUSED.includes(next)) focusTitle()
})
</script>

<template>
  <div ref="root" class="flex flex-col gap-4">
    <!-- PA-07: paid, but the reservation had expired. Not a success: the refund is handled with the shop. -->
    <NoticeBanner v-if="kind === 'refund'" variant="error">
      <p data-status-title tabindex="-1" class="font-medium outline-offset-2">
        Recibimos su pago, pero la reserva no pudo confirmarse.
      </p>
      <p class="mt-1 text-fg-muted">
        Su pago de {{ colones(amount) }} llegó cuando la reserva ya había vencido. El pago queda
        pendiente de devolución y la gestionará la barbería con usted.
      </p>
      <template #action>
        <BaseButton variant="secondary" @click="emit('anotherSpace')">
          Elegir otro espacio
        </BaseButton>
      </template>
    </NoticeBanner>

    <!-- The provider did not answer (503) or the wait ran out with the payment still pending. -->
    <div
      v-else-if="kind === 'verifying'"
      role="status"
      class="flex items-start gap-3 rounded-card border border-line bg-surface p-4 text-sm"
    >
      <Clock
        :size="20"
        :stroke-width="1.5"
        class="mt-0.5 shrink-0 text-fg-subtle"
        aria-hidden="true"
      />
      <div class="flex flex-col items-start gap-3">
        <div>
          <p data-status-title tabindex="-1" class="font-medium outline-offset-2">
            Estamos verificando su pago
          </p>
          <p class="mt-1 text-fg-muted">
            El proveedor de pago no ha respondido. Su pago queda en verificación y no se cobrará dos
            veces. Puede consultarlo de nuevo cuando quiera.
          </p>
        </div>
        <BaseButton variant="secondary" :loading="checking" @click="emit('check')">
          Verificar ahora
        </BaseButton>
      </div>
    </div>

    <div v-else-if="kind === 'loading'" class="flex flex-col gap-3" role="status">
      <p class="font-medium">Verificando su pago…</p>
      <SkeletonBlock shape="block" label="Verificando su pago" />
    </div>

    <div v-else-if="kind === 'waiting'" class="flex flex-col items-start gap-3" role="status">
      <StatusLabel status="pending" />
      <p class="font-medium">Esperando la confirmación de su pago…</p>
      <p class="text-sm text-fg-muted">Esta página se actualiza sola cada pocos segundos.</p>
      <BaseButton variant="secondary" :loading="checking" @click="emit('check')">
        Verificar ahora
      </BaseButton>
    </div>

    <!-- Approved: the view goes on to the confirmed reservation; this is only what is seen meanwhile. -->
    <NoticeBanner v-else-if="kind === 'approved'">
      <p class="font-medium">Su pago de {{ colones(amount) }} fue aprobado.</p>
      <p class="mt-1 text-fg-muted">Estamos abriendo su reserva…</p>
    </NoticeBanner>

    <!-- Rejected: the reason of the API, and the reservation is kept. -->
    <NoticeBanner v-else-if="kind === 'rejected'" variant="error">
      <p data-status-title tabindex="-1" class="font-medium outline-offset-2">
        No pudimos cobrar su pago.
      </p>
      <p class="mt-1 text-fg-muted">
        {{ rejection }} Su reserva se mantiene; elija otro método para pagarla.
      </p>
      <template #action>
        <BaseButton variant="secondary" @click="emit('retry')">Elegir otro método</BaseButton>
      </template>
    </NoticeBanner>

    <!-- Failed: nothing was charged and it can be tried again. -->
    <NoticeBanner v-else-if="kind === 'failed'" variant="error">
      <p data-status-title tabindex="-1" class="font-medium outline-offset-2">
        No se pudo procesar su pago.
      </p>
      <p class="mt-1 text-fg-muted">
        {{ rejection }} Su reserva se mantiene; puede intentarlo de nuevo o elegir otro método.
      </p>
      <template #action>
        <BaseButton variant="secondary" @click="emit('retry')">Intentar de nuevo</BaseButton>
      </template>
    </NoticeBanner>

    <template v-else>
      <StatusLabel status="refunded" />
      <InlineNotice title="El pago fue reembolsado."> </InlineNotice>
    </template>
  </div>
</template>
