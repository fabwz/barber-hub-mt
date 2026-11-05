<script setup lang="ts">
interface Props {
  /** Names the empty space, e.g. "No hay reservas para el sáb 7 nov." */
  title: string
  /** One line that explains what is happening. */
  description: string
}

const { title, description } = defineProps<Props>()
</script>

<template>
  <div class="flex flex-col items-start gap-2 p-5 md:p-8">
    <h2 class="text-lg font-medium text-fg">{{ title }}</h2>
    <p class="text-sm text-fg-muted">{{ description }}</p>
    <div v-if="$slots.action" class="mt-2">
      <slot name="action" />
    </div>
  </div>
</template>
