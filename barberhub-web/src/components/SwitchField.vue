<script setup lang="ts">
import { useId } from 'vue'
import { Check } from 'lucide-vue-next'

interface Props {
  label: string
  description?: string
}

defineProps<Props>()
const model = defineModel<boolean>({ required: true })

const labelId = useId()
const descriptionId = useId()
</script>

<template>
  <div class="flex items-center justify-between gap-3">
    <div class="min-w-0">
      <p :id="labelId" class="font-medium">{{ label }}</p>
      <p v-if="description" :id="descriptionId" class="mt-0.5 text-sm text-fg-muted">
        {{ description }}
      </p>
    </div>
    <!-- 56 x 44 px target around a 48 x 28 px track. The state is the knob's side and its check, not just a color. -->
    <button
      type="button"
      role="switch"
      :aria-checked="model ? 'true' : 'false'"
      :aria-labelledby="labelId"
      :aria-describedby="description ? descriptionId : undefined"
      class="group grid h-11 w-14 flex-none cursor-pointer place-items-center focus-visible:outline-none"
      @click="model = !model"
    >
      <span
        class="relative block h-7 w-12 rounded-pill border transition-colors duration-150 ease-out group-focus-visible:outline group-focus-visible:outline-1 group-focus-visible:outline-offset-2 group-focus-visible:outline-accent"
        :class="model ? 'border-line-strong bg-elevated' : 'border-field bg-transparent'"
      >
        <span
          class="absolute top-0.75 left-0.75 grid size-5 place-items-center rounded-full transition duration-150 ease-out"
          :class="model ? 'translate-x-5 bg-accent' : 'translate-x-0 bg-fg-muted'"
        >
          <Check v-if="model" :size="12" :stroke-width="2" class="text-canvas" aria-hidden="true" />
        </span>
      </span>
    </button>
  </div>
</template>
