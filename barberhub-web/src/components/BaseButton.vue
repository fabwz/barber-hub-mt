<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  variant?: 'primary' | 'secondary'
  /** `large` is the 48 px height used in payment flows. */
  size?: 'default' | 'large'
  type?: 'button' | 'submit' | 'reset'
  disabled?: boolean
  /** Blocks the button and adds «…» after the label; the label itself carries no dots. */
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
    <slot v-if="!loading" name="icon" />
    <!-- Loading: the three dots go only after the label, never before it. -->
    <span><slot /><span v-if="loading" aria-hidden="true">…</span></span>
  </button>
</template>
