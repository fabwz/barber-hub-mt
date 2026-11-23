<script setup lang="ts">
import { computed } from 'vue'
import { Check, Circle, X } from 'lucide-vue-next'

import { PASSWORD_RULES } from '@/composables/passwordPolicy'

interface Props {
  password: string
  /** True after a submit attempt: what is still missing goes from «Pendiente» to «Falta». */
  failed?: boolean
  /** Lets the password field point to the list with `aria-describedby`. */
  id?: string
}

const { password, failed = false, id } = defineProps<Props>()

type State = 'met' | 'pending' | 'missing'

// The state is the icon and a word (read by screen readers), never only a color.
const STATE_WORDS: Record<State, string> = {
  met: 'Cumplido: ',
  pending: 'Pendiente: ',
  missing: 'Falta: ',
}

const items = computed(() =>
  PASSWORD_RULES.map((rule) => {
    const state: State = rule.test(password) ? 'met' : failed ? 'missing' : 'pending'
    return { id: rule.id, text: rule.text, state }
  }),
)
</script>

<template>
  <div :id="id" class="flex flex-col gap-2">
    <p class="text-sm font-medium">La contraseña nueva debe tener</p>
    <ul class="flex flex-col gap-1 text-sm">
      <li
        v-for="item in items"
        :key="item.id"
        class="flex min-h-6 items-center gap-2"
        :class="item.state === 'met' ? 'text-fg' : 'text-fg-muted'"
        :data-state="item.state"
      >
        <span class="flex flex-none" :class="item.state === 'missing' ? 'text-danger' : ''">
          <Check v-if="item.state === 'met'" :size="16" :stroke-width="1.5" aria-hidden="true" />
          <X
            v-else-if="item.state === 'missing'"
            :size="16"
            :stroke-width="1.5"
            aria-hidden="true"
          />
          <Circle v-else :size="16" :stroke-width="1.5" aria-hidden="true" />
        </span>
        <span :class="item.state === 'missing' ? 'text-danger' : ''">
          <span class="sr-only">{{ STATE_WORDS[item.state] }}</span
          >{{ item.text }}
        </span>
      </li>
    </ul>
  </div>
</template>
