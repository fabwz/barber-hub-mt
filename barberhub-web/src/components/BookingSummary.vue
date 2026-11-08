<script setup lang="ts">
import { computed } from 'vue'

import StatusLabel from '@/components/StatusLabel.vue'
import { colones, formatShortDate } from '@/composables/format'

interface Props {
  serviceName: string
  barberName: string
  /** «AAAA-MM-DD». */
  date: string
  /** «10:30». */
  startTime: string
  /** «11:15»; optional. */
  endTime?: string
  /** Colones with the 13 % VAT included. */
  price: number
  /** `draft` is the choice in progress; the others are the reservation created. */
  status?: 'draft' | 'temporary' | 'confirmed'
  /** Length of the service in minutes, shown next to the hour. */
  durationMinutes?: number
  /** Time left to pay as `m:ss`, for a temporary reservation. */
  remaining?: string
}

const { startTime, endTime, durationMinutes, status = 'draft' } = defineProps<Props>()

const range = computed(() => (endTime ? `${startTime}–${endTime}` : startTime))

const duration = computed(() => {
  if (durationMinutes === undefined) return ''
  const hours = Math.floor(durationMinutes / 60)
  const minutes = durationMinutes % 60
  if (hours === 0) return `${minutes} min`
  return minutes === 0 ? `${hours} h` : `${hours} h ${minutes} min`
})
</script>

<template>
  <div class="flex flex-col gap-4">
    <StatusLabel v-if="status === 'confirmed'" status="confirmed" />
    <dl class="rounded-card border border-line bg-surface px-5 text-base">
      <div class="flex justify-between gap-6 border-b border-line-strong py-3">
        <dt class="text-fg-muted">Servicio</dt>
        <dd class="text-right font-medium">{{ serviceName }}</dd>
      </div>
      <div class="flex justify-between gap-6 border-b border-line-strong py-3">
        <dt class="text-fg-muted">Barbero</dt>
        <dd class="text-right font-medium">{{ barberName }}</dd>
      </div>
      <div class="flex justify-between gap-6 border-b border-line-strong py-3">
        <dt class="text-fg-muted">Fecha</dt>
        <dd class="text-right font-medium">{{ formatShortDate(date) }}</dd>
      </div>
      <div class="flex justify-between gap-6 border-b border-line-strong py-3">
        <dt class="text-fg-muted">Hora</dt>
        <dd class="text-right tabular-nums">
          {{ range }}
          <span v-if="duration" class="ml-3 text-fg-muted">{{ duration }}</span>
        </dd>
      </div>
      <div class="flex justify-between gap-6 py-4">
        <dt class="font-medium">Total</dt>
        <dd class="text-right text-lg tabular-nums">
          {{ colones(price) }}
          <span class="text-sm text-fg-muted">IVA incluido</span>
        </dd>
      </div>
    </dl>
    <p v-if="status === 'temporary' && remaining" role="timer" class="text-sm tabular-nums">
      <span class="text-fg-muted">Su espacio se libera en</span>
      <span class="ml-1 font-medium">{{ remaining }}</span>
    </p>
  </div>
</template>
