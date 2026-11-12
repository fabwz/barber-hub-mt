<script setup lang="ts">
import { computed } from 'vue'

type ReservationStatus =
  | 'temporary'
  | 'confirmed'
  | 'completed'
  | 'no_show'
  | 'cancelled_by_customer'
  | 'cancelled_by_barbershop'
  | 'expired'

type PaymentStatus = 'pending' | 'approved' | 'rejected' | 'failed' | 'refunded'

type Tone = 'warning' | 'accent' | 'danger' | 'ring'

interface Props {
  status: ReservationStatus | PaymentStatus
  /** Only the text, with no dot (the reservation screens of «Mis reservas»). */
  plain?: boolean
}

// Texts and dots from the two status tables in DESIGN.md.
const STATUSES: Record<ReservationStatus | PaymentStatus, { text: string; tone: Tone }> = {
  temporary: { text: 'Pendiente de pago', tone: 'warning' },
  confirmed: { text: 'Confirmada', tone: 'accent' },
  completed: { text: 'Atendida', tone: 'accent' },
  no_show: { text: 'Ausente', tone: 'danger' },
  cancelled_by_customer: { text: 'Cancelada', tone: 'danger' },
  cancelled_by_barbershop: { text: 'Cancelada', tone: 'danger' },
  expired: { text: 'Expirada', tone: 'ring' },
  pending: { text: 'Pago pendiente', tone: 'warning' },
  approved: { text: 'Pago aprobado', tone: 'accent' },
  rejected: { text: 'Pago rechazado', tone: 'danger' },
  failed: { text: 'Pago fallido', tone: 'danger' },
  refunded: { text: 'Reembolsado', tone: 'ring' },
}

const { status, plain = false } = defineProps<Props>()

const current = computed(() => STATUSES[status])

const dotClass = computed(
  () =>
    ({
      warning: 'bg-warning',
      accent: 'bg-accent',
      danger: 'bg-danger',
      ring: 'border border-fg-muted bg-transparent',
    })[current.value.tone],
)
</script>

<template>
  <span
    class="inline-flex items-center gap-2 font-medium"
    :class="[plain ? 'text-sm' : 'text-xs', current.tone === 'ring' ? 'text-fg-muted' : 'text-fg']"
  >
    <span v-if="!plain" class="size-2 shrink-0 rounded-full" :class="dotClass" aria-hidden="true" />
    {{ current.text }}
  </span>
</template>
