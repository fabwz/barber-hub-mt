<script setup lang="ts">
import { useId } from 'vue'

import { cleanTime } from '@/composables/timeOfDay'
import { restrict } from '@/composables/fieldInput'

interface Props {
  /** Names the hour for everyone; it is read aloud and shown only when `showLabel` is set. */
  label: string
  showLabel?: boolean
  /** The hour has a problem. The message is the caller's: a row of two hours shares one. */
  invalid?: boolean
  /** Id of the message that explains the problem, so the field points to it. */
  describedBy?: string
}

const { label, showLabel = false, invalid = false, describedBy } = defineProps<Props>()

/** «HH:MM» while typing: whatever is typed is cleaned, so the model may be an incomplete hour. */
const value = defineModel<string>({ default: '' })

const id = useId()

function onInput(event: Event): void {
  restrict(event, cleanTime, value)
}
</script>

<template>
  <div class="flex flex-col gap-2">
    <label :for="id" :class="showLabel ? 'text-sm font-medium text-fg' : 'sr-only'">{{
      label
    }}</label>
    <!-- Typed by hand: the native hour picker follows the language of the browser and may show 12 hours. -->
    <input
      :id="id"
      :value="value"
      type="text"
      inputmode="numeric"
      autocomplete="off"
      placeholder="HH:MM"
      maxlength="5"
      :aria-invalid="invalid ? 'true' : undefined"
      :aria-describedby="describedBy"
      class="min-h-12 w-24 rounded-field border bg-canvas px-3 text-center font-mono text-base font-normal text-fg tabular-nums placeholder:text-fg-subtle focus:border-accent focus:outline-none disabled:cursor-not-allowed disabled:border-line disabled:text-fg-subtle"
      :class="invalid ? 'border-danger-border' : 'border-field'"
      @input="onInput"
    />
  </div>
</template>
