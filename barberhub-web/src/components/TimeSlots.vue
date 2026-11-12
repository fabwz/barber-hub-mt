<script setup lang="ts">
import { computed, useId } from 'vue'
import { ChevronDown } from 'lucide-vue-next'

export interface TimeSlot {
  /** Identifier of the space (the ISO start the API delivers). */
  value: string
  /** «10:30». */
  time: string
  /** Who attends; shown only with `showBarber` («Sin preferencia»). */
  barberName?: string
  /** Taken: shown but not selectable. */
  taken?: boolean
}

interface Props {
  slots: TimeSlot[]
  /** «Sin preferencia»: every hour says with whom it stays. */
  showBarber?: boolean
  /** Accessible name of the group. */
  label?: string
  /** Desktop: each period wraps onto several lines instead of scrolling sideways. */
  wrap?: boolean
}

const { slots, showBarber = false, label = 'Hora', wrap = false } = defineProps<Props>()
const selected = defineModel<string | null>({ default: null })

const noteId = useId()

const PERIODS = [
  { name: 'Mañana', from: 0, to: 12 },
  { name: 'Tarde', from: 12, to: 18 },
  { name: 'Noche', from: 18, to: 24 },
]

const periods = computed(() =>
  PERIODS.map((period) => ({
    name: period.name,
    slots: slots.filter((slot) => {
      const hour = Number(slot.time.slice(0, 2))
      return hour >= period.from && hour < period.to
    }),
  })).filter((period) => period.slots.length > 0),
)

const hasTaken = computed(() => slots.some((slot) => slot.taken))

function chipLabel(slot: TimeSlot): string {
  const who = showBarber && slot.barberName ? ` con ${slot.barberName}` : ''
  return `${slot.time}${who}${slot.taken ? ', ocupado' : ''}`
}

function optionLabel(slot: TimeSlot): string {
  const who = showBarber && slot.barberName ? ` con ${slot.barberName}` : ''
  return `${slot.time}${who}${slot.taken ? ' ocupada' : ''}`
}

function chipClasses(slot: TimeSlot): string[] {
  const base =
    'inline-flex min-h-11 min-w-18 flex-none items-center justify-center rounded-field border px-4 text-sm tabular-nums transition-colors duration-150 ease-out'
  if (slot.taken) {
    return [base, 'cursor-not-allowed border-line bg-transparent text-fg-subtle line-through']
  }
  return selected.value === slot.value
    ? [base, 'border-line-strong bg-elevated font-medium text-fg']
    : [base, 'border-line-strong bg-transparent text-fg hover:border-line-hover']
}

function choose(slot: TimeSlot): void {
  if (!slot.taken) selected.value = slot.value
}

function onSelect(event: Event): void {
  const value = (event.target as HTMLSelectElement).value
  const found = slots.find((slot) => slot.value === value)
  if (found) choose(found)
}
</script>

<template>
  <div>
    <!-- Phone: one select, like the mobile design -->
    <div class="md:hidden">
      <div class="relative">
        <select
          :aria-label="label"
          :aria-describedby="hasTaken ? noteId : undefined"
          :value="selected ?? ''"
          class="h-13 w-full cursor-pointer appearance-none rounded-field border border-field bg-canvas pr-11 pl-4 text-base text-fg tabular-nums transition-colors duration-150 ease-out hover:border-fg-muted"
          @change="onSelect"
        >
          <option value="" disabled>Elija una hora</option>
          <option
            v-for="slot in slots"
            :key="slot.value"
            :value="slot.value"
            :disabled="slot.taken"
          >
            {{ optionLabel(slot) }}
          </option>
        </select>
        <ChevronDown
          :size="18"
          :stroke-width="1.5"
          class="pointer-events-none absolute top-4 right-4 text-fg-muted"
          aria-hidden="true"
        />
      </div>
      <p v-if="hasTaken" :id="noteId" class="mt-2 text-sm text-fg-subtle">
        Las horas marcadas como ocupadas no se pueden elegir.
      </p>
    </div>

    <!-- Desktop: chips by period, in one line that scrolls sideways like the dates -->
    <div role="group" :aria-label="label" class="hidden flex-col gap-5 md:flex">
      <div
        v-for="period in periods"
        :key="period.name"
        role="group"
        :aria-label="period.name"
        class="flex flex-col gap-2"
      >
        <span class="text-sm text-fg-muted">{{ period.name }}</span>
        <div
          class="scrollbar-none -mx-1 flex gap-2 px-1 pb-1"
          :class="wrap ? 'flex-wrap' : 'overflow-x-auto'"
        >
          <button
            v-for="slot in period.slots"
            :key="slot.value"
            type="button"
            :class="chipClasses(slot)"
            :aria-pressed="selected === slot.value ? 'true' : 'false'"
            :aria-disabled="slot.taken ? 'true' : undefined"
            :aria-label="chipLabel(slot)"
            @click="choose(slot)"
          >
            <span class="flex flex-col items-center leading-tight">
              <span>{{ slot.time }}</span>
              <span v-if="showBarber && slot.barberName" class="text-xs font-normal text-fg-muted">
                con {{ slot.barberName }}
              </span>
            </span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
