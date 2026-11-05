<script setup lang="ts">
import { computed } from 'vue'
import { Ellipsis } from 'lucide-vue-next'

interface Props {
  variant?: 'primary' | 'secondary'
  /** `large` is the 48 px height used in payment flows. */
  size?: 'default' | 'large'
  type?: 'button' | 'submit' | 'reset'
  disabled?: boolean
  loading?: boolean
}

const {
  variant = 'primary',
  size = 'default',
  type = 'button',
  disabled = false,
  loading = false,
} = defineProps<Props>()

const classes = computed(() => [
  'inline-flex min-w-11 items-center justify-center gap-2 rounded-pill border border-line-strong px-5 text-sm',
  size === 'large' ? 'min-h-12' : 'min-h-11',
  variant === 'primary'
    ? 'bg-elevated font-medium text-fg enabled:hover:bg-btn-hover enabled:active:bg-btn-active'
    : 'bg-transparent font-normal text-fg enabled:hover:border-line-hover',
  'transition-colors duration-150 ease-out disabled:cursor-not-allowed disabled:opacity-50',
  loading ? 'cursor-progress' : '',
])
</script>

<template>
  <button
    :type="type"
    :class="classes"
    :disabled="disabled || loading"
    :aria-busy="loading ? 'true' : undefined"
  >
    <Ellipsis v-if="loading" :size="16" :stroke-width="1.5" aria-hidden="true" />
    <slot v-else name="icon" />
    <slot />
  </button>
</template>
