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
  'flex min-h-11 w-full cursor-pointer items-center gap-4 rounded-card p-4 text-left text-fg transition-colors duration-150 ease-out',
  variant === 'card' ? 'border border-line-strong' : 'border border-transparent',
  checked.value
    ? 'bg-elevated font-medium'
    : variant === 'card'
      ? 'bg-transparent font-normal hover:border-line-hover'
      : 'border-b-line bg-transparent font-normal hover:bg-surface',
  disabled ? 'cursor-not-allowed opacity-50' : '',
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
    <span
      class="flex size-5 shrink-0 items-center justify-center rounded-full border border-line-strong"
      aria-hidden="true"
    >
      <span v-if="checked" class="size-2.5 rounded-full bg-accent" />
    </span>
  </div>
</template>
