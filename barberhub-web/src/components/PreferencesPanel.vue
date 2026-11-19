<script setup lang="ts">
import { computed } from 'vue'
import { Monitor, Moon, Sun } from 'lucide-vue-next'

import OptionCard from '@/components/OptionCard.vue'
import OptionGroup from '@/components/OptionGroup.vue'
import SegmentedControl from '@/components/SegmentedControl.vue'
import SwitchField from '@/components/SwitchField.vue'
import TextSizeSample from '@/components/TextSizeSample.vue'
import {
  TEXT_SIZES,
  TEXT_SIZE_PERCENT,
  usePreferencesStore,
  type TextSize,
  type ThemeChoice,
} from '@/stores/preferences'

// The block of RF-48: theme, text size and motion, with its note. It has no title and no page
// around it, so the «Preferencias» screen and the «Apariencia» tab of «Mi cuenta» can both host it.
// Every change applies at once and is saved in the browser: there is no save button.
const preferences = usePreferencesStore()

const THEMES: Array<{ value: ThemeChoice; label: string; description: string; icon: typeof Sun }> =
  [
    { value: 'light', label: 'Claro', description: 'Fondo claro y texto oscuro.', icon: Sun },
    { value: 'dark', label: 'Oscuro', description: 'Fondo oscuro y texto claro.', icon: Moon },
    {
      value: 'auto',
      label: 'Automático',
      description: 'Sigue la preferencia de su dispositivo',
      icon: Monitor,
    },
  ]

const SIZE_LABELS: Record<TextSize, string> = {
  normal: 'Normal',
  large: 'Grande',
  xlarge: 'Muy grande',
}

const theme = computed({
  get: () => preferences.theme,
  set: (value: ThemeChoice) => preferences.setTheme(value),
})
const themeDescription = computed(
  () => THEMES.find((option) => option.value === preferences.theme)?.description ?? '',
)

const textSize = computed<string | null>({
  get: () => preferences.textSize,
  set: (value) => {
    const size = TEXT_SIZES.find((candidate) => candidate === value)
    if (size !== undefined) preferences.setTextSize(size)
  },
})

const reduceMotion = computed({
  get: () => preferences.reducedMotion,
  set: (value: boolean) => preferences.setReduceMotion(value),
})
</script>

<template>
  <div>
    <p class="mt-2 text-fg-muted md:border-b md:border-line md:pb-6">
      Elija cómo quiere ver BarberHub: los cambios se aplican de inmediato y se guardan en este
      navegador.
    </p>

    <div class="preferences-columns mt-8 max-md:flex max-md:flex-col max-md:gap-8">
      <section class="flex flex-col gap-1 md:col-start-1 md:row-start-1 md:pr-8">
        <h2 class="text-lg font-medium">Tema</h2>
        <div class="mt-3">
          <SegmentedControl v-model="theme" label="Tema" :options="THEMES" />
          <p class="mt-3 text-sm text-fg-muted" aria-live="polite">{{ themeDescription }}</p>
        </div>
      </section>

      <section
        class="flex flex-col gap-1 md:col-start-2 md:row-span-2 md:row-start-1 md:border-l md:border-line md:pl-8"
      >
        <h2 class="text-lg font-medium">Tamaño del texto</h2>
        <p class="text-sm text-fg-muted">Agranda o reduce el texto de toda la aplicación.</p>
        <div class="mt-3">
          <!-- The list goes out 16 px on each side so its text lines up with the rest, like the service list. -->
          <OptionGroup v-model="textSize" aria-label="Tamaño del texto" class="-mx-4 gap-0">
            <OptionCard v-for="size in TEXT_SIZES" :key="size" :value="size" variant="row">
              {{ SIZE_LABELS[size] }}
              <template #trailing>{{ TEXT_SIZE_PERCENT[size] }}&nbsp;%</template>
            </OptionCard>
          </OptionGroup>
          <TextSizeSample class="mt-4" />
        </div>
      </section>

      <section
        aria-label="Reducción de movimiento"
        class="md:col-start-1 md:row-start-2 md:self-start md:pr-8"
      >
        <SwitchField
          v-model="reduceMotion"
          label="Reducir el movimiento"
          description="Quita las transiciones y animaciones. Por defecto sigue la preferencia de su dispositivo."
        />
      </section>
    </div>
  </div>
</template>
