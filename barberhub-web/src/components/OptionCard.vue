<script setup lang="ts">
import { computed, onBeforeUnmount, useId, type Component } from 'vue'

import { useOptionGroupContext } from '@/composables/useOptionGroupContext'

interface Props {
  value: string
  /** `row` is the flat service row; `card` is the payment method card; `tile` is the same with the icon over the label. */
  variant?: 'row' | 'card' | 'tile'
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

const tile = computed(() => variant === 'tile')

const classes = computed(() => [
  'flex w-full cursor-pointer text-fg transition-colors duration-150 ease-out',
  tile.value
    ? 'flex-col items-center justify-center gap-2 text-center'
    : 'items-center gap-4 text-left',
  // The service row is flat (64 px, no radius at rest); the payment card is a 16 px card.
  tile.value
    ? 'min-h-19 rounded-card px-1.5 py-2 text-sm leading-tight'
    : variant === 'card'
      ? 'min-h-11 rounded-card p-4'
      : 'min-h-16 px-4 py-2',
  variant !== 'row'
    ? 'border border-line-strong'
    : 'relative after:absolute after:inset-x-4 after:bottom-0 after:h-px after:bg-line',
  checked.value
    ? 'bg-elevated font-medium after:hidden'
    : variant !== 'row'
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
      :size="tile ? 22 : 20"
      :stroke-width="1.5"
      class="shrink-0"
      :class="tile && checked ? 'text-accent' : ''"
      aria-hidden="true"
    />
    <span class="flex min-w-0 flex-col" :class="tile ? 'items-center' : 'grow'">
      <span :id="labelId" class="text-base"><slot /></span>
      <span v-if="$slots.description" :id="descriptionId" class="text-sm font-normal text-fg-muted">
        <slot name="description" />
      </span>
    </span>
    <span v-if="!tile" :class="indicatorClasses" aria-hidden="true">
      <span v-if="checked" class="size-2.5 rounded-full bg-accent" />
    </span>
  </div>
</template>
