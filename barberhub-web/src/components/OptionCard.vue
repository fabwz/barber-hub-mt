<script setup lang="ts">
import { computed, onBeforeUnmount, useId, type Component } from 'vue'

import { useOptionGroupContext } from '@/composables/useOptionGroupContext'

interface Props {
  value: string
  /** `row` is the flat service row; `card` is the payment method card. */
  variant?: 'row' | 'card'
  /** Optional 20 px icon (card variant). */
  icon?: Component
  disabled?: boolean
}

const { value, variant = 'card', icon, disabled = false } = defineProps<Props>()

const group = useOptionGroupContext()
const labelId = useId()
const descriptionId = useId()

const unregister = group.register(value)
onBeforeUnmount(unregister)

const checked = computed(() => group.selected.value === value)
const tabindex = computed(() => (!disabled && group.tabbable.value === value ? 0 : -1))

const classes = computed(() => [
  'flex w-full cursor-pointer items-center gap-4 text-left text-fg transition-colors duration-150 ease-out',
  // The service row is flat (64 px, no radius at rest); the payment card is a 16 px card.
  variant === 'card' ? 'min-h-11 rounded-card p-4' : 'min-h-16 px-4 py-2',
  variant === 'card'
    ? 'border border-line-strong'
    : 'relative after:absolute after:inset-x-4 after:bottom-0 after:h-px after:bg-line',
  checked.value
    ? 'bg-elevated font-medium after:hidden'
    : variant === 'card'
      ? 'bg-transparent font-normal hover:border-line-hover'
      : 'bg-transparent font-normal hover:rounded-card hover:bg-surface hover:after:hidden',
  checked.value && variant === 'row' ? 'rounded-card' : '',
  disabled ? 'cursor-not-allowed opacity-50' : '',
])

const indicatorClasses = computed(() => [
  'flex shrink-0 items-center justify-center rounded-full border',
  variant === 'row' ? 'size-5.5' : 'size-5',
  variant === 'row' && !checked.value ? 'border-field' : 'border-line-strong',
])

function choose(): void {
  if (!disabled) {
    group.select(value)
  }
}
</script>

<template>
  <div
    role="radio"
    :class="classes"
    :tabindex="tabindex"
    :data-value="value"
    :aria-checked="checked ? 'true' : 'false'"
    :aria-disabled="disabled ? 'true' : undefined"
    :aria-labelledby="labelId"
    :aria-describedby="$slots.description ? descriptionId : undefined"
    @click="choose"
    @keydown.space.prevent="choose"
    @keydown.enter.prevent="choose"
  >
    <component
      :is="icon"
      v-if="icon"
      :size="20"
      :stroke-width="1.5"
      class="shrink-0"
      aria-hidden="true"
    />
    <span class="flex min-w-0 grow flex-col">
      <span :id="labelId" class="text-base"><slot /></span>
      <span v-if="$slots.description" :id="descriptionId" class="text-sm font-normal text-fg-muted">
        <slot name="description" />
      </span>
    </span>
    <span :class="indicatorClasses" aria-hidden="true">
      <span v-if="checked" class="size-2.5 rounded-full bg-accent" />
    </span>
  </div>
</template>
