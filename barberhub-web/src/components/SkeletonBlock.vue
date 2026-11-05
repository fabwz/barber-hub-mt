<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  /** Shape to imitate: a text line, a list row or a larger block. */
  shape?: 'text' | 'row' | 'block'
  count?: number
  /** Text for screen readers while loading. */
  label?: string
}

const { shape = 'row', count = 1, label = 'Cargando' } = defineProps<Props>()

const blockClass = computed(() => ({ text: 'h-4', row: 'h-12', block: 'h-24' })[shape])
const blocks = computed(() => Math.max(1, count))
</script>

<template>
  <div class="flex flex-col gap-2" aria-busy="true">
    <span class="sr-only">{{ label }}</span>
    <div
      v-for="index in blocks"
      :key="index"
      class="animate-pulse rounded-field bg-line motion-reduce:animate-none"
      :class="blockClass"
      aria-hidden="true"
    />
  </div>
</template>
