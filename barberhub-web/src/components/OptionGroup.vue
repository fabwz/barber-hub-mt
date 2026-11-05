<script setup lang="ts">
import { computed, provide, readonly, ref, useTemplateRef } from 'vue'

import { optionGroupKey } from '@/composables/useOptionGroupContext'

const selected = defineModel<string | null>({ default: null })

const root = useTemplateRef<HTMLElement>('root')
const registered = ref<string[]>([])

// The chosen card holds the tab stop; with no choice, the first card does.
const tabbable = computed<string | null>(() => {
  return selected.value ?? registered.value[0] ?? null
})

provide(optionGroupKey, {
  selected: readonly(selected),
  tabbable,
  select: (value: string) => {
    selected.value = value
  },
  register: (value: string) => {
    registered.value = [...registered.value, value]
    return () => {
      registered.value = registered.value.filter((item) => item !== value)
    }
  },
})

const NAVIGATION_KEYS = ['ArrowDown', 'ArrowRight', 'ArrowUp', 'ArrowLeft', 'Home', 'End']

function onKeydown(event: KeyboardEvent): void {
  if (!NAVIGATION_KEYS.includes(event.key) || root.value === null) {
    return
  }

  const radios = Array.from(
    root.value.querySelectorAll<HTMLElement>('[role="radio"]:not([aria-disabled="true"])'),
  )
  const current = radios.findIndex((radio) => radio === document.activeElement)
  if (radios.length === 0 || current === -1) {
    return
  }

  event.preventDefault()

  let next: number
  if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
    next = (current + 1) % radios.length
  } else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
    next = (current - 1 + radios.length) % radios.length
  } else if (event.key === 'Home') {
    next = 0
  } else {
    next = radios.length - 1
  }

  const target = radios[next]
  const value = target?.dataset.value
  if (target !== undefined && value !== undefined) {
    target.focus()
    selected.value = value
  }
}
</script>

<template>
  <div ref="root" role="radiogroup" class="flex flex-col gap-2" @keydown="onKeydown">
    <slot />
  </div>
</template>
