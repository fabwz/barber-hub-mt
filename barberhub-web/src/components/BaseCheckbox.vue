<script setup lang="ts">
import { computed, useId, useTemplateRef } from 'vue'
import { Check, CircleAlert } from 'lucide-vue-next'

interface Props {
  /** Error message shown below the row; the box gets the error border. */
  error?: string
  /** Marks the box as required for assistive technology. */
  required?: boolean
  /** Sending state: dimmed and not interactive. */
  loading?: boolean
  disabled?: boolean
}

const { error = '', required = false, loading = false, disabled = false } = defineProps<Props>()
const checked = defineModel<boolean>({ default: false })

const button = useTemplateRef<HTMLButtonElement>('button')
const uid = useId()
const labelId = computed(() => `${uid}-label`)
const errorId = computed(() => `${uid}-error`)
const invalid = computed(() => error !== '')
const inactive = computed(() => disabled || loading)

const boxClasses = computed(() => [
  'mt-3 grid size-5 shrink-0 place-items-center rounded-field border transition-colors duration-150 ease-out',
  'disabled:cursor-not-allowed',
  loading
    ? 'border-line opacity-60'
    : invalid.value
      ? 'border-danger-border'
      : checked.value
        ? 'border-line-strong bg-elevated'
        : 'border-field bg-transparent',
  checked.value && loading ? 'bg-elevated' : '',
])

function toggle(): void {
  if (inactive.value) return
  checked.value = !checked.value
}

// A click on a link inside the text follows the link and does not toggle the box.
function onRowClick(event: MouseEvent): void {
  if ((event.target as HTMLElement).closest('a')) return
  toggle()
}

function focus(): void {
  button.value?.focus()
}

defineExpose({ focus })
</script>

<template>
  <div>
    <div class="flex min-h-11 items-start gap-3" @click="onRowClick">
      <button
        ref="button"
        type="button"
        role="checkbox"
        :class="boxClasses"
        :aria-checked="checked ? 'true' : 'false'"
        :aria-required="required ? 'true' : undefined"
        :aria-labelledby="labelId"
        :aria-describedby="invalid ? errorId : undefined"
        :aria-invalid="invalid ? 'true' : undefined"
        :disabled="inactive"
        @keydown.space.prevent
        @keyup.space.prevent="toggle"
      >
        <Check v-if="checked" :size="14" :stroke-width="2" class="text-accent" aria-hidden="true" />
      </button>
      <span :id="labelId" class="py-3 text-sm" :class="loading ? 'opacity-60' : ''">
        <slot />
      </span>
    </div>
    <p v-if="invalid" :id="errorId" class="mb-1 ml-8 flex items-start gap-2 text-sm text-danger">
      <CircleAlert :size="16" :stroke-width="1.5" class="mt-0.5 shrink-0" aria-hidden="true" />
      <span>{{ error }}</span>
    </p>
  </div>
</template>
