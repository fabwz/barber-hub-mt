<script setup lang="ts">
import { computed } from 'vue'
import { Check } from 'lucide-vue-next'

interface Props {
  /** Taken slot: not selectable, struck through, aria-disabled. */
  busy?: boolean
}

const { busy = false } = defineProps<Props>()
const selected = defineModel<boolean>({ default: false })

const classes = computed(() => [
  'inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-pill border px-4 text-sm tabular-nums transition-colors duration-150 ease-out',
  busy
    ? 'cursor-not-allowed border-line bg-transparent font-normal text-fg-subtle line-through'
    : selected.value
      ? 'border-line-strong bg-elevated font-medium text-fg'
      : 'border-line-strong bg-transparent font-normal text-fg hover:bg-line',
])

function toggle(): void {
  if (!busy) {
    selected.value = !selected.value
  }
}
</script>

<template>
  <button
    type="button"
    :class="classes"
    :aria-pressed="selected ? 'true' : 'false'"
    :aria-disabled="busy ? 'true' : undefined"
    @click="toggle"
  >
    <Check
      v-if="selected && !busy"
      :size="14"
      :stroke-width="1.5"
      class="text-accent"
      aria-hidden="true"
    />
    <slot />
  </button>
</template>
