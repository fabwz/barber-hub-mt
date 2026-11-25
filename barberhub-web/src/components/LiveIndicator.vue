<script setup lang="ts">
import { computed } from 'vue'

import type { LiveState } from '@/composables/useLiveAgenda'

interface Props {
  state: LiveState
}

const { state } = defineProps<Props>()

const LABELS: Partial<Record<LiveState, string>> = {
  live: 'En vivo',
  reconnecting: 'Reconectando…',
  syncing: 'Sincronizando…',
}

const label = computed(() => LABELS[state] ?? null)

// The dot never carries the state alone: the text always does. «En vivo» is filled, «Reconectando…»
// has the warning tone and «Sincronizando…» is a ring.
const dotClass = computed(() => {
  if (state === 'live') return 'bg-accent'
  if (state === 'reconnecting') return 'bg-warning'
  return 'border border-fg-muted'
})

const NOTICES: Partial<Record<LiveState, string>> = {
  reconnecting: 'Sin conexión en vivo. La agenda puede estar desactualizada.',
  rejected:
    'No se pudo activar la agenda en vivo. La agenda puede estar desactualizada: recargue la página para intentarlo de nuevo.',
}

const notice = computed(() => NOTICES[state] ?? null)
</script>

<template>
  <span
    v-if="label !== null"
    role="status"
    class="inline-flex min-h-11 items-center gap-2 text-sm whitespace-nowrap text-fg-muted"
  >
    <span class="size-2 flex-none rounded-full" :class="dotClass" aria-hidden="true" />{{ label }}
  </span>
  <p
    v-if="notice !== null"
    :role="state === 'rejected' ? 'status' : undefined"
    class="order-last basis-full rounded-card bg-warning-surface px-4 py-3 text-sm text-warning"
    data-testid="live-notice"
  >
    {{ notice }}
  </p>
</template>
