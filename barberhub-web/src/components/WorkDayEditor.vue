<script setup lang="ts">
import { useId } from 'vue'
import { CircleAlert, Plus } from 'lucide-vue-next'

import BaseButton from '@/components/BaseButton.vue'
import SwitchField from '@/components/SwitchField.vue'
import TimeField from '@/components/TimeField.vue'
import type { DayDraft, HourRow, useCollaboratorForm } from '@/composables/useCollaboratorForm'

interface Props {
  day: DayDraft
  /** The form that owns the day: it changes the rows and keeps their errors. */
  form: ReturnType<typeof useCollaboratorForm>
  /** What the barbershop opens that day, e.g. «09:00–19:00» or «Cerrada». */
  shopHours: string
}

const { day, form, shopHours } = defineProps<Props>()
const id = useId()

type Kind = 'shifts' | 'breaks'

const KINDS: Array<{ kind: Kind; title: string; one: string; add: string }> = [
  { kind: 'shifts', title: 'Turnos', one: 'turno', add: 'Agregar turno' },
  { kind: 'breaks', title: 'Pausas', one: 'pausa', add: 'Agregar pausa' },
]

function lower(): string {
  return day.label.toLowerCase()
}

function hourLabel(kind: Kind, part: 'Inicio' | 'Fin', index: number): string {
  const of = kind === 'shifts' ? 'del turno' : 'de la pausa'
  return `${part} ${of} ${index + 1} del ${lower()}`
}

function errorId(row: HourRow): string {
  return `${id}-${row.key}-error`
}
</script>

<template>
  <section :aria-labelledby="`${id}-title`" class="border-b border-line py-4">
    <h4 :id="`${id}-title`" class="text-base font-medium text-fg">{{ day.label }}</h4>
    <SwitchField
      class="mt-2"
      :label="`Trabaja el ${lower()}`"
      :description="shopHours ? `Barbería: ${shopHours}` : undefined"
      :model-value="day.working"
      @update:model-value="(value: boolean) => form.setWorking(day, value)"
    />

    <div v-if="day.working" class="mt-3 flex flex-col gap-4">
      <p
        v-if="form.rowErrors[`day-${day.day}`]"
        role="alert"
        class="flex items-start gap-2 text-sm text-danger"
      >
        <CircleAlert :size="16" :stroke-width="1.5" class="mt-0.5 shrink-0" aria-hidden="true" />
        <span>{{ form.rowErrors[`day-${day.day}`] }}</span>
      </p>

      <div v-for="item in KINDS" :key="item.kind">
        <h5 class="text-sm font-medium text-fg-muted">{{ item.title }}</h5>
        <ul v-if="day[item.kind].length > 0" class="mt-2 flex flex-col gap-3">
          <li v-for="(row, index) in day[item.kind]" :key="row.key">
            <div class="flex flex-wrap items-center gap-3">
              <TimeField
                :label="hourLabel(item.kind, 'Inicio', index)"
                :model-value="row.start"
                :invalid="form.rowErrors[row.key] !== undefined"
                :described-by="form.rowErrors[row.key] ? errorId(row) : undefined"
                @update:model-value="(value: string) => form.setHour(row, 'start', value)"
              />
              <span class="text-sm text-fg-muted" aria-hidden="true">a</span>
              <TimeField
                :label="hourLabel(item.kind, 'Fin', index)"
                :model-value="row.end"
                :invalid="form.rowErrors[row.key] !== undefined"
                :described-by="form.rowErrors[row.key] ? errorId(row) : undefined"
                @update:model-value="(value: string) => form.setHour(row, 'end', value)"
              />
              <BaseButton
                variant="secondary"
                :aria-label="`Quitar el ${item.one} ${index + 1} del ${lower()}`"
                @click="form.removeRow(day, item.kind, row.key)"
                >Quitar</BaseButton
              >
            </div>
            <p
              v-if="form.rowErrors[row.key]"
              :id="errorId(row)"
              role="alert"
              class="mt-2 flex items-start gap-2 text-sm text-danger"
            >
              <CircleAlert
                :size="16"
                :stroke-width="1.5"
                class="mt-0.5 shrink-0"
                aria-hidden="true"
              />
              <span>{{ form.rowErrors[row.key] }}</span>
            </p>
          </li>
        </ul>
        <BaseButton
          v-if="item.kind === 'shifts' || day.shifts.length > 0"
          variant="secondary"
          class="mt-2"
          @click="item.kind === 'shifts' ? form.addShift(day) : form.addBreak(day)"
        >
          <template #icon><Plus :size="16" :stroke-width="1.5" aria-hidden="true" /></template>
          {{ item.add }}
        </BaseButton>
      </div>
    </div>
  </section>
</template>
