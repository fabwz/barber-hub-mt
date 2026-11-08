<script setup lang="ts">
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
  /** The payment was approved when the reservation could no longer be confirmed (PA-07). */
  refundPending?: boolean
  /** The provider did not answer: the payment stays under verification. */
  unreachable?: boolean
  /** A status request is in flight. */
  checking?: boolean
}

const {
  state,
  amount = 0,
  reason = null,
  refundPending = false,
  unreachable = false,
  checking = false,
} = defineProps<Props>()

const emit = defineEmits<{
  check: []
  /** The person wants to choose a method again. */
  retry: []
}>()
</script>

<template>
  <!--
    TEMPORAL hasta el #84: estos avisos son el mínimo para cerrar el pago. La confirmación de la
    reserva y sus pantallas finales (confirmada o rechazada) son del issue #84.
  -->
  <div class="flex flex-col gap-4">
    <NoticeBanner v-if="unreachable && (state === null || state === 'pendiente')" variant="error">
      <p class="font-medium">El proveedor de pago no responde.</p>
      <p class="mt-1 text-fg-muted">
        Su pago queda en verificación y no se cobrará dos veces. Puede consultarlo de nuevo.
      </p>
      <template #action>
        <BaseButton variant="secondary" :loading="checking" @click="emit('check')">
          Verificar ahora
        </BaseButton>
      </template>
    </NoticeBanner>

    <div v-else-if="state === null" class="flex flex-col gap-3" role="status">
      <p class="font-medium">Verificando su pago…</p>
      <SkeletonBlock shape="block" label="Verificando su pago" />
    </div>

    <div v-else-if="state === 'pendiente'" class="flex flex-col items-start gap-3" role="status">
      <StatusLabel status="pending" />
      <p class="font-medium">Esperando la confirmación de su pago…</p>
      <p class="text-sm text-fg-muted">Esta página se actualiza sola cada pocos segundos.</p>
      <BaseButton variant="secondary" :loading="checking" @click="emit('check')">
        Verificar ahora
      </BaseButton>
    </div>

    <template v-else-if="state === 'aprobado'">
      <NoticeBanner>
        <p class="font-medium">Su pago de {{ colones(amount) }} fue aprobado.</p>
      </NoticeBanner>
      <InlineNotice v-if="refundPending" title="La reserva ya había vencido.">
        No se pudo confirmar y el pago se le devolverá.
      </InlineNotice>
    </template>

    <template v-else-if="state === 'rechazado' || state === 'fallido'">
      <StatusLabel :status="state === 'rechazado' ? 'rejected' : 'failed'" />
      <NoticeBanner variant="error">
        <p class="font-medium">No se pudo completar el pago.</p>
        <p class="mt-1 text-fg-muted">
          {{ reason || 'El pago no se procesó. No se le cobró.' }}
        </p>
        <template #action>
          <BaseButton variant="secondary" @click="emit('retry')">Elegir otro método</BaseButton>
        </template>
      </NoticeBanner>
    </template>

    <template v-else>
      <StatusLabel status="refunded" />
      <InlineNotice title="El pago fue reembolsado."> </InlineNotice>
    </template>
  </div>
</template>
