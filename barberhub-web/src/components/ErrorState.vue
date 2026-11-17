<script setup lang="ts">
import { CircleAlert } from 'lucide-vue-next'

import BaseButton from '@/components/BaseButton.vue'

interface Props {
  /** What failed, e.g. "No pudimos cargar las reservas." */
  message: string
  /** The way out. */
  description?: string
  retryLabel?: string
  /** Holds the retry button, e.g. while a wait asked by the API runs. */
  retryDisabled?: boolean
}

const {
  message,
  description = 'Revise su conexión e intente de nuevo. Lo que ya eligió se mantiene.',
  retryLabel = 'Reintentar',
  retryDisabled = false,
} = defineProps<Props>()

const emit = defineEmits<{
  retry: []
}>()
</script>

<template>
  <div role="alert" class="flex items-start gap-3 rounded-field bg-danger-surface p-4">
    <CircleAlert
      :size="20"
      :stroke-width="1.5"
      class="mt-0.5 flex-none text-danger"
      aria-hidden="true"
    />
    <div class="flex min-w-0 flex-col items-start gap-4">
      <div>
        <h2 class="font-medium text-danger">{{ message }}</h2>
        <p class="mt-1 text-sm text-fg-muted">{{ description }}</p>
        <slot />
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <BaseButton variant="secondary" :disabled="retryDisabled" @click="emit('retry')">{{
          retryLabel
        }}</BaseButton>
        <slot name="action" />
      </div>
    </div>
  </div>
</template>
