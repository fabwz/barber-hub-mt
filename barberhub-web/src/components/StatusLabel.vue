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

type VoucherStatus = 'voucher_active' | 'voucher_expired'

interface Props {
  status: ReservationStatus | PaymentStatus | VoucherStatus
}

// Texts from the three status tables in DESIGN.md. Always text only: the word says the state,
// so it never depends on a color or a dot. «Expirada» and «Reembolsado» are muted.
const STATUSES: Record<
  ReservationStatus | PaymentStatus | VoucherStatus,
  { text: string; muted: boolean }
> = {
  temporary: { text: 'Pendiente de pago', muted: false },
  confirmed: { text: 'Confirmada', muted: false },
  completed: { text: 'Atendida', muted: false },
  no_show: { text: 'Ausente', muted: false },
  cancelled_by_customer: { text: 'Cancelada', muted: false },
  cancelled_by_barbershop: { text: 'Cancelada', muted: false },
  expired: { text: 'Expirada', muted: true },
  pending: { text: 'Pago pendiente', muted: false },
  approved: { text: 'Pago aprobado', muted: false },
  rejected: { text: 'Pago rechazado', muted: false },
  failed: { text: 'Pago fallido', muted: false },
  refunded: { text: 'Reembolsado', muted: true },
  voucher_active: { text: 'Activo', muted: false },
  voucher_expired: { text: 'Vencido', muted: true },
}

const { status } = defineProps<Props>()

const current = computed(() => STATUSES[status])
</script>

<template>
  <span class="text-sm font-medium" :class="current.muted ? 'text-fg-muted' : 'text-fg'">
    {{ current.text }}
  </span>
</template>
