<script setup lang="ts">
import BaseButton from '@/components/BaseButton.vue'

interface Props {
  /** What failed, e.g. "Error: no pudimos cargar las reservas." */
  message: string
  /** The way out. */
  description?: string
  retryLabel?: string
}

const {
  message,
  description = 'Revise su conexión e intente de nuevo. Lo que ya eligió se mantiene.',
  retryLabel = 'Reintentar',
} = defineProps<Props>()

const emit = defineEmits<{
  retry: []
}>()
</script>

<template>
  <div role="alert" class="flex flex-col items-start gap-2 p-5 md:p-8">
    <h2 class="text-lg font-medium text-fg">{{ message }}</h2>
    <p class="text-sm text-fg-muted">{{ description }}</p>
    <div class="mt-2 flex flex-wrap items-center gap-2">
      <BaseButton @click="emit('retry')">{{ retryLabel }}</BaseButton>
      <slot name="action" />
    </div>
  </div>
</template>
