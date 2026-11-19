<script setup lang="ts">
import { computed } from 'vue'

import { colones, dateOf, formatDateWithYear, sessionsLabel } from '@/composables/format'

interface Props {
  name: string
  sessions: number
  /** Names of the services it covers. */
  services: string[]
  /** Days of validity of a voucher still to buy; they count from the confirmation of the payment. */
  validityDays?: number
  /** ISO 8601 expiry of a voucher already active. */
  expiresAt?: string | null
  /** Colones with the 13 % VAT included. */
  price?: number
}

const { services, expiresAt = null } = defineProps<Props>()

const covered = computed(() => services.join(', '))
const expiry = computed(() => (expiresAt === null ? '' : formatDateWithYear(dateOf(expiresAt))))
</script>

<template>
  <dl class="rounded-card border border-line bg-surface px-5 text-base">
    <div class="flex justify-between gap-6 border-b border-line-strong py-3">
      <dt class="text-fg-muted">Bono</dt>
      <dd class="text-right font-medium">{{ name }}</dd>
    </div>
    <div class="flex justify-between gap-6 border-b border-line-strong py-3">
      <dt class="text-fg-muted">Sesiones</dt>
      <dd class="text-right tabular-nums">{{ sessionsLabel(sessions) }}</dd>
    </div>
    <div
      v-if="validityDays !== undefined"
      class="flex justify-between gap-6 border-b border-line-strong py-3"
    >
      <dt class="text-fg-muted">Vigencia</dt>
      <dd class="text-right">
        <span class="tabular-nums">{{ validityDays }} días</span>
        <span class="block text-sm text-fg-muted">Cuenta desde que se confirme el pago</span>
      </dd>
    </div>
    <div v-if="expiry" class="flex justify-between gap-6 border-b border-line-strong py-3">
      <dt class="text-fg-muted">Vence el</dt>
      <dd class="text-right tabular-nums">{{ expiry }}</dd>
    </div>
    <div
      class="flex justify-between gap-6 py-3"
      :class="price !== undefined ? 'border-b border-line-strong' : ''"
    >
      <dt class="text-fg-muted">Servicios cubiertos</dt>
      <dd class="text-right">{{ covered }}</dd>
    </div>
    <div v-if="price !== undefined" class="flex justify-between gap-6 py-4">
      <dt class="font-medium">Total</dt>
      <dd class="text-right text-lg tabular-nums">
        {{ colones(price) }}
        <span class="text-sm text-fg-muted">IVA incluido</span>
      </dd>
    </div>
  </dl>
</template>
