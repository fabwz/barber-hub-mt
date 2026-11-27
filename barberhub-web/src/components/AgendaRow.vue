<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'

import StatusLabel from '@/components/StatusLabel.vue'
import { statusKeyOf } from '@/composables/useMyBookings'
import type { AgendaReservation } from '@/composables/useStaffAgenda'
import { timeOf } from '@/composables/format'

interface Props {
  reservation: AgendaReservation
  /** Where the row leads: the detail panel of the reservation. */
  to: RouteLocationRaw
  /** The reservation open in the panel. */
  selected?: boolean
  /** The owner sees whose agenda each row belongs to; the collaborator only has their own. */
  showCollaborator?: boolean
}

const { reservation, to, selected = false, showCollaborator = false } = defineProps<Props>()
</script>

<template>
  <!-- Flat row (DESIGN.md «Fila de agenda»): time range, customer, service and status, no card. -->
  <RouterLink
    :to="to"
    :aria-current="selected ? 'true' : undefined"
    class="flex min-h-14 flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2 text-fg no-underline transition-colors duration-150 ease-out hover:bg-surface"
    :class="selected ? 'rounded-field bg-elevated' : ''"
  >
    <span class="w-28 flex-none text-sm text-fg-muted tabular-nums">
      {{ timeOf(reservation.inicio) }}–{{ timeOf(reservation.fin) }}
    </span>
    <span class="min-w-40 flex-1">
      <span class="block" :class="selected ? 'font-medium' : ''">{{
        reservation.cliente_nombre
      }}</span>
      <span class="block text-sm text-fg-muted">
        {{ reservation.servicio_nombre
        }}<template v-if="showCollaborator"> · {{ reservation.barbero_nombre }}</template
        ><template v-if="reservation.asistida"> · Asistida</template
        ><template v-if="reservation.cliente_telefono">
          · {{ reservation.cliente_telefono }}</template
        >
      </span>
    </span>
    <StatusLabel :status="statusKeyOf(reservation.estado)" />
  </RouterLink>
</template>
