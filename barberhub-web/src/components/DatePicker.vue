<script setup lang="ts">
import { computed, ref, useId, useTemplateRef } from 'vue'
import { ChevronDown } from 'lucide-vue-next'

import { dayParts, formatMonthYear, formatShortDate } from '@/composables/format'

export interface PickerDay {
  /** «AAAA-MM-DD». */
  value: string
  /** The day is known to have no hours. */
  closed: boolean
}

interface Props {
  days: PickerDay[]
}

const { days } = defineProps<Props>()
const selected = defineModel<string | null>({ default: null })

const calendarId = useId()
const strip = useTemplateRef<HTMLElement>('strip')
const calendarOpen = ref(false)

const reference = computed(() => selected.value ?? days[0]?.value ?? null)
const month = computed(() => (reference.value ? formatMonthYear(reference.value) : ''))

// The chosen day holds the tab stop; with no choice, the first day with hours does.
const tabbable = computed(
  () => selected.value ?? days.find((day) => !day.closed)?.value ?? days[0]?.value ?? null,
)

function label(day: PickerDay): string {
  return day.closed ? `${formatShortDate(day.value)}, sin horas` : formatShortDate(day.value)
}

function choose(day: PickerDay): void {
  if (!day.closed) selected.value = day.value
}

function dayClasses(day: PickerDay): string[] {
  const base =
    'flex h-18 w-14 flex-none flex-col items-center justify-center rounded-pill border text-sm transition-colors duration-150 ease-out'
  if (selected.value === day.value) return [base, 'border-line-strong bg-elevated text-fg']
  return day.closed
    ? [base, 'cursor-not-allowed border-line bg-transparent text-fg-subtle']
    : [base, 'border-line-strong bg-transparent text-fg hover:border-line-hover']
}

function onKeydown(event: KeyboardEvent): void {
  if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key) || strip.value === null) {
    return
  }
  const buttons = Array.from(strip.value.querySelectorAll<HTMLButtonElement>('button'))
  const current = buttons.findIndex((button) => button === document.activeElement)
  if (current === -1) return
  event.preventDefault()
  const next =
    event.key === 'Home'
      ? 0
      : event.key === 'End'
        ? buttons.length - 1
        : Math.min(buttons.length - 1, Math.max(0, current + (event.key === 'ArrowRight' ? 1 : -1)))
  buttons[next]?.focus()
}

// Phone: the month as a calendar («Otra fecha»), Monday first.
const WEEK = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom']

const cells = computed(() => {
  if (reference.value === null) return []
  const [year = 0, monthNumber = 1] = reference.value.split('-').map(Number)
  const first = new Date(Date.UTC(year, monthNumber - 1, 1)).getUTCDay()
  const lead = (first + 6) % 7
  const count = new Date(Date.UTC(year, monthNumber, 0)).getUTCDate()
  const known = new Map(days.map((day) => [day.value, day]))
  const result: Array<{ blank: boolean; value: string; number: number; off: boolean }> = []
  for (let i = 0; i < lead; i++) result.push({ blank: true, value: '', number: 0, off: true })
  for (let n = 1; n <= count; n++) {
    const value = `${year}-${String(monthNumber).padStart(2, '0')}-${String(n).padStart(2, '0')}`
    const day = known.get(value)
    result.push({ blank: false, value, number: n, off: !day || day.closed })
  }
  return result
})

function chooseCell(value: string): void {
  selected.value = value
  calendarOpen.value = false
}

function cellClasses(cell: { value: string; off: boolean }): string[] {
  const base = 'h-11 rounded-pill text-base tabular-nums transition-colors duration-150 ease-out'
  if (selected.value === cell.value) return [base, 'bg-elevated font-medium text-fg']
  return cell.off ? [base, 'cursor-not-allowed text-fg-muted'] : [base, 'text-fg hover:bg-line']
}
</script>

<template>
  <div>
    <div class="flex items-baseline justify-between gap-3">
      <h3 class="text-lg font-medium">Fecha</h3>
      <span class="hidden text-sm text-fg-subtle md:inline">{{ month }}</span>
    </div>

    <div class="mt-2 flex items-center justify-between gap-3 md:hidden">
      <span class="text-sm text-fg-subtle">{{ month }}</span>
      <button
        type="button"
        :aria-expanded="calendarOpen"
        :aria-controls="calendarId"
        class="-mr-2 flex min-h-11 items-center gap-1.5 rounded-pill px-3 text-sm font-medium text-fg transition-colors duration-150 ease-out hover:bg-surface"
        @click="calendarOpen = !calendarOpen"
      >
        <span>Otra fecha</span>
        <ChevronDown
          :size="16"
          :stroke-width="1.5"
          class="flex-none transition-transform duration-150 ease-out"
          :class="calendarOpen ? 'rotate-180' : ''"
          aria-hidden="true"
        />
      </button>
    </div>

    <div
      ref="strip"
      role="group"
      aria-label="Fecha. Use las flechas del teclado o deslice"
      class="scrollbar-none -mx-5 flex gap-2 overflow-x-auto px-5 pt-0.5 pb-1 md:mx-0 md:mt-4 md:px-0"
      @keydown="onKeydown"
    >
      <button
        v-for="day in days"
        :key="day.value"
        type="button"
        :class="dayClasses(day)"
        :aria-pressed="selected === day.value ? 'true' : 'false'"
        :aria-disabled="day.closed ? 'true' : undefined"
        :aria-label="label(day)"
        :tabindex="tabbable === day.value ? 0 : -1"
        @click="choose(day)"
      >
        <span aria-hidden="true">{{ dayParts(day.value).weekday }}</span>
        <span
          aria-hidden="true"
          class="text-lg tabular-nums"
          :class="selected === day.value ? 'font-medium' : 'font-normal'"
          >{{ day.closed ? '–' : dayParts(day.value).day }}</span
        >
      </button>
    </div>

    <div
      v-if="calendarOpen"
      :id="calendarId"
      class="mt-2 rounded-card border border-line bg-surface p-4 md:hidden"
    >
      <div class="grid grid-cols-7 gap-1 text-center text-sm text-fg-muted">
        <span v-for="name in WEEK" :key="name" class="py-1">{{ name }}</span>
      </div>
      <div class="grid grid-cols-7 gap-1">
        <template v-for="(cell, index) in cells" :key="index">
          <span v-if="cell.blank" aria-hidden="true" />
          <button
            v-else
            type="button"
            :class="cellClasses(cell)"
            :aria-label="formatShortDate(cell.value)"
            :aria-pressed="selected === cell.value ? 'true' : 'false'"
            :aria-disabled="cell.off ? 'true' : undefined"
            @click="!cell.off && chooseCell(cell.value)"
          >
            {{ cell.number }}
          </button>
        </template>
      </div>
      <p class="mt-2 text-sm text-fg-muted">Los días con guion no tienen horas disponibles.</p>
    </div>
  </div>
</template>
