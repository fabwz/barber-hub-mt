<script setup lang="ts">
import { useTemplateRef } from 'vue'
import { Search, X } from 'lucide-vue-next'

interface Props {
  /** Accessible name of the field; also its placeholder. */
  label?: string
  disabled?: boolean
}

const { label = 'Buscar por nombre o ciudad', disabled = false } = defineProps<Props>()
const value = defineModel<string>({ default: '' })

const emit = defineEmits<{
  /** The person cleared the text with the button. */
  clear: []
}>()

const input = useTemplateRef<HTMLInputElement>('input')

function clear(): void {
  emit('clear')
  // The button disappears with the text, so the focus goes back to the field.
  input.value?.focus()
}
</script>

<template>
  <div role="search" class="relative">
    <Search
      :size="20"
      :stroke-width="1.5"
      class="pointer-events-none absolute top-3.5 left-3.5 text-fg-muted"
      aria-hidden="true"
    />
    <input
      ref="input"
      v-model="value"
      type="text"
      role="searchbox"
      autocomplete="off"
      :aria-label="label"
      :placeholder="label"
      :disabled="disabled"
      class="h-12 w-full min-w-0 rounded-field border border-field bg-canvas pr-12 pl-11 text-base text-fg transition-colors duration-150 ease-out placeholder:text-fg-subtle disabled:opacity-60"
    />
    <button
      v-if="value !== '' && !disabled"
      type="button"
      aria-label="Borrar búsqueda"
      class="absolute top-0.5 right-0.5 grid size-11 place-items-center rounded-pill text-fg-muted transition-colors duration-150 ease-out hover:bg-surface"
      @click="clear"
    >
      <X :size="18" :stroke-width="1.5" aria-hidden="true" />
    </button>
  </div>
</template>
