<script setup lang="ts" generic="T extends string">
import { nextTick, useTemplateRef, type Component } from 'vue'

interface Option {
  value: T
  label: string
  /** 18 px icon; it shows on desktop and when the control stacks, not in the compact phone row. */
  icon?: Component
}

interface Props {
  options: Option[]
  /** Accessible name of the group. */
  label: string
}

const { options, label } = defineProps<Props>()
const model = defineModel<T>({ required: true })

const root = useTemplateRef<HTMLElement>('root')

async function choose(value: T, focus = false): Promise<void> {
  model.value = value
  if (!focus) return
  await nextTick()
  root.value?.querySelector<HTMLElement>('[aria-checked="true"]')?.focus()
}

// Arrow keys move the choice and the focus, like a native radio group; the chosen one holds the tab stop.
function onKeydown(event: KeyboardEvent): void {
  const index = options.findIndex((option) => option.value === model.value)
  const last = options.length - 1
  const next: Record<string, number> = {
    ArrowRight: index === last ? 0 : index + 1,
    ArrowDown: index === last ? 0 : index + 1,
    ArrowLeft: index <= 0 ? last : index - 1,
    ArrowUp: index <= 0 ? last : index - 1,
    Home: 0,
    End: last,
  }
  const target = options[next[event.key] ?? -1]
  if (target === undefined) return
  event.preventDefault()
  void choose(target.value, true)
}
</script>

<template>
  <!-- A pill with the options side by side; with a larger text on a phone it stacks in rows. -->
  <div
    ref="root"
    role="radiogroup"
    :aria-label="label"
    class="flex max-w-120 rounded-pill border border-line-strong text-scaled:max-md:flex-col text-scaled:max-md:rounded-card"
    @keydown="onKeydown"
  >
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      role="radio"
      :aria-checked="model === option.value ? 'true' : 'false'"
      :tabindex="model === option.value ? 0 : -1"
      class="flex min-h-11 min-w-0 flex-1 cursor-pointer items-center justify-center gap-3 rounded-pill border px-3 text-sm text-fg transition-colors duration-150 ease-out text-scaled:max-md:min-h-13 text-scaled:max-md:rounded-card"
      :class="
        model === option.value
          ? 'border-line-strong bg-elevated font-medium'
          : 'border-transparent font-normal hover:bg-surface'
      "
      @click="choose(option.value)"
    >
      <component
        :is="option.icon"
        v-if="option.icon"
        :size="18"
        :stroke-width="1.5"
        class="hidden flex-none md:block text-scaled:block"
        :class="model === option.value ? 'text-accent' : 'text-fg-muted'"
        aria-hidden="true"
      />
      <span>{{ option.label }}</span>
    </button>
  </div>
</template>
