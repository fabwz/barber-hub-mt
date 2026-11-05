<script setup lang="ts">
import { computed } from 'vue'

export interface PasswordRequirement {
  id: string
  text: string
  test: (password: string) => boolean
}

interface Props {
  requirements: PasswordRequirement[]
  password: string
  /** The one-line help that lists the requirements. */
  hint: string
  /** True after a submit attempt: if something is still missing, the sentence turns red. */
  failed?: boolean
  id?: string
}

const { requirements, password, hint, failed = false, id } = defineProps<Props>()

const items = computed(() =>
  requirements.map((requirement) => ({
    id: requirement.id,
    text: requirement.text,
    met: requirement.test(password),
  })),
)
// Quiet by default: the sentence only changes after a failed attempt, and goes back
// to normal as soon as everything is met.
const invalid = computed(() => failed && items.value.some((item) => !item.met))
</script>

<template>
  <div :id="id" aria-live="polite" class="mt-2 text-sm">
    <p :class="invalid ? 'font-medium text-danger' : 'text-fg-subtle'">{{ hint }}</p>
    <!-- Screen readers hear which requirement is met or missing, only after a failed attempt. -->
    <ul v-if="invalid" class="sr-only">
      <li v-for="item in items" :key="item.id">
        {{ item.met ? 'Cumplido: ' : 'No cumplido: ' }}{{ item.text }}
      </li>
    </ul>
  </div>
</template>
