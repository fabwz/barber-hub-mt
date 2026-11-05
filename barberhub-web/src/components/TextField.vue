<script setup lang="ts">
import { computed, useId, useTemplateRef } from 'vue'
import { CircleAlert } from 'lucide-vue-next'

// Native attributes (type, name, placeholder, disabled, autocomplete…) go to the <input>.
defineOptions({ inheritAttrs: false })

interface Props {
  label: string
  /** Error message: names the problem and the way out. */
  error?: string
  id?: string
}

const { label, error = '', id } = defineProps<Props>()
const value = defineModel<string>({ default: '' })

const input = useTemplateRef<HTMLInputElement>('input')
const generatedId = useId()
const inputId = computed(() => id ?? generatedId)
const errorId = computed(() => `${inputId.value}-error`)
const invalid = computed(() => error !== '')

const inputClasses = computed(() => [
  'min-h-12 w-full rounded-field border bg-canvas px-4 text-base font-normal text-fg placeholder:text-fg-subtle',
  'focus:border-accent focus:outline-none',
  'disabled:cursor-not-allowed disabled:border-line disabled:text-fg-subtle',
  invalid.value ? 'border-danger-border' : 'border-field',
])

function focus(): void {
  input.value?.focus()
}

defineExpose({ focus })
</script>

<template>
  <div class="flex flex-col gap-2">
    <label :for="inputId" class="text-sm font-medium text-fg">{{ label }}</label>
    <input
      v-bind="$attrs"
      :id="inputId"
      ref="input"
      v-model="value"
      :class="inputClasses"
      :aria-invalid="invalid ? 'true' : undefined"
      :aria-describedby="invalid ? errorId : undefined"
    />
    <p v-if="invalid" :id="errorId" class="flex items-start gap-2 text-sm text-danger">
      <CircleAlert :size="16" :stroke-width="1.5" class="mt-0.5 shrink-0" aria-hidden="true" />
      <span>{{ error }}</span>
    </p>
  </div>
</template>
