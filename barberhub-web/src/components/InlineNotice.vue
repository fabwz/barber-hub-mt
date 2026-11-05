<script setup lang="ts">
import { Info } from 'lucide-vue-next'

interface Props {
  /** First sentence, weight 500. */
  title: string
}

defineProps<Props>()
</script>

<template>
  <div
    role="note"
    class="flex items-start gap-3 rounded-card border border-line bg-surface px-4 py-3 text-sm"
  >
    <Info :size="16" :stroke-width="1.5" class="mt-0.5 shrink-0 text-fg-muted" aria-hidden="true" />
    <p>
      <span class="font-medium text-fg">{{ title }}</span
      >{{ ' ' }}<span class="text-fg-muted"><slot /></span>
    </p>
  </div>
</template>
