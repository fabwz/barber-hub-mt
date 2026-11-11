<script setup lang="ts">
import { computed } from 'vue'
import { Check, CircleAlert } from 'lucide-vue-next'

interface Props {
  variant?: 'success' | 'error'
  visible?: boolean
}

const { variant = 'success', visible = true } = defineProps<Props>()

const isError = computed(() => variant === 'error')

const classes = computed(() => [
  'flex items-start gap-2 rounded-card p-4 text-sm',
  // An error has no border (the success notice does): its surface and its icon carry it.
  isError.value ? 'bg-danger-surface text-danger' : 'border border-line bg-surface text-fg',
])
</script>

<template>
  <Transition
    appear
    enter-active-class="transition-opacity duration-150 ease-out motion-reduce:transition-none"
    leave-active-class="transition-opacity duration-150 ease-out motion-reduce:transition-none"
    enter-from-class="opacity-0"
    leave-to-class="opacity-0"
  >
    <div v-if="visible" :class="classes" :role="isError ? 'alert' : 'status'">
      <CircleAlert
        v-if="isError"
        :size="16"
        :stroke-width="1.5"
        class="mt-0.5 shrink-0"
        aria-hidden="true"
      />
      <Check
        v-else
        :size="16"
        :stroke-width="1.5"
        class="mt-0.5 shrink-0 text-accent"
        aria-hidden="true"
      />
      <div class="grow">
        <slot />
      </div>
      <div v-if="$slots.action" class="shrink-0">
        <slot name="action" />
      </div>
    </div>
  </Transition>
</template>
