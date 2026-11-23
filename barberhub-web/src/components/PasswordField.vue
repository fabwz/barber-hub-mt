<script setup lang="ts">
import { ref, useTemplateRef } from 'vue'
import { Eye, EyeOff } from 'lucide-vue-next'

import TextField from '@/components/TextField.vue'

// A TextField for a password with the «Mostrar contraseña» button (DESIGN.md, «Contraseña»).
// Native attributes (name, autocomplete…) go to the <input>.
defineOptions({ inheritAttrs: false })

interface Props {
  label: string
  error?: string
  disabled?: boolean
}

const { label, error = '', disabled = false } = defineProps<Props>()
const value = defineModel<string>({ default: '' })

const show = ref(false)
const field = useTemplateRef<InstanceType<typeof TextField>>('field')

defineExpose({ focus: () => field.value?.focus() })
</script>

<template>
  <div class="relative">
    <TextField
      ref="field"
      v-model="value"
      v-bind="$attrs"
      :label="label"
      :error="error"
      :type="show ? 'text' : 'password'"
      :disabled="disabled"
      class="pr-12!"
    />
    <!-- Aligned with the 48 px input: the label above it is 20 px plus the 8 px gap. -->
    <button
      type="button"
      class="absolute top-7 right-0 grid size-12 place-items-center rounded-field text-fg-muted transition-colors duration-150 ease-out hover:text-fg disabled:cursor-not-allowed disabled:text-fg-subtle"
      aria-label="Mostrar contraseña"
      :aria-pressed="show ? 'true' : 'false'"
      :disabled="disabled"
      @click="show = !show"
    >
      <EyeOff v-if="show" :size="20" :stroke-width="1.5" aria-hidden="true" />
      <Eye v-else :size="20" :stroke-width="1.5" aria-hidden="true" />
    </button>
  </div>
</template>
