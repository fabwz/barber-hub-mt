<script setup lang="ts">
import { computed, reactive, ref, useId } from 'vue'
import { CircleAlert, Plus } from 'lucide-vue-next'

import { isApiError } from '@/api'
import BaseButton from '@/components/BaseButton.vue'
import TimeField from '@/components/TimeField.vue'
import { isValidTime } from '@/composables/timeOfDay'
import { useScheduleStore, type TimeSlot } from '@/stores/schedule'

interface Props {
  barberia: string
  /** Day of the week: 1 is Monday and 7 is Sunday. */
  day: number
  /** «Lunes». */
  label: string
}

const { barberia, day, label } = defineProps<Props>()

const emit = defineEmits<{
  /** An action ended well; the text is for the notice of the screen. */
  done: [message: string]
}>()

// One day of the weekly schedule (RF-04, HO-01). Every action is a call of its own and applies at once:
// add a slot, remove one, or change one (remove the old, add the new). Nothing waits for a «Save».
const store = useScheduleStore()
const id = useId()

interface Row {
  key: string
  /** Id of the saved slot; null while the row has not been saved. */
  id: string | null
  start: string
  end: string
  /** What the person typed differs from what is saved (always true for a row not saved yet). */
  dirty: boolean
}

const saved = computed(() => store.slots.filter((slot) => slot.dia === day))
/** Edits of saved slots, by id. */
const drafts = reactive<Record<string, { start: string; end: string }>>({})
/** Rows that are not saved yet: no hour is invented for them. */
const pending = ref<Array<{ key: string; start: string; end: string }>>([])
/** The row with an action running, so its buttons wait. */
const busy = ref<string | null>(null)
const errors = reactive<Record<string, string>>({})
/** A failure that belongs to the whole day, not to a row. */
const dayError = ref('')

let counter = 0

const rows = computed<Row[]>(() => [
  ...saved.value.map((slot) => {
    const start = drafts[slot.id]?.start ?? slot.abre
    const end = drafts[slot.id]?.end ?? slot.cierra
    return {
      key: slot.id,
      id: slot.id,
      start,
      end,
      dirty: start !== slot.abre || end !== slot.cierra,
    }
  }),
  ...pending.value.map((row) => ({ ...row, id: null, dirty: true })),
])

const isOpen = computed(() => saved.value.length > 0)

const INVALID_TIME = 'Escriba las horas en formato HH:MM, de 00:00 a 23:59.'
const END_NOT_AFTER = 'La hora de cierre debe ser posterior a la de apertura.'
const OVERLAP = 'Esta franja se solapa con otra del mismo día. Corrija las horas.'
const FAILED_SAVE = 'No pudimos guardar la franja. Inténtelo de nuevo.'
const FAILED_REMOVE = 'No pudimos quitar la franja. Inténtelo de nuevo.'

function setHour(row: Row, part: 'start' | 'end', value: string): void {
  delete errors[row.key]
  if (row.id !== null) {
    drafts[row.id] = { start: row.start, end: row.end, [part]: value }
  } else {
    const target = pending.value.find((item) => item.key === row.key)
    if (target !== undefined) target[part] = value
  }
}

/** The quick check before the trip; the API still has the last word (409 and 422). */
function problemOf(row: Row): string {
  if (!isValidTime(row.start) || !isValidTime(row.end)) return INVALID_TIME
  if (row.end <= row.start) return END_NOT_AFTER
  const other = saved.value.find(
    (slot) => slot.id !== row.id && slot.abre < row.end && row.start < slot.cierra,
  )
  return other === undefined
    ? ''
    : `Esta franja se solapa con la de ${other.abre} a ${other.cierra}. Corrija las horas.`
}

/** The message of a failure of the API, in the row it belongs to. */
function messageOf(error: unknown): string {
  if (isApiError(error)) {
    if (error.status === 409) return OVERLAP
    if (error.status === 422) {
      const named = [error.errors.abre, error.errors.cierra, error.errors.dia].flat()
      return named.filter(Boolean).join(' ') || END_NOT_AFTER
    }
  }
  return FAILED_SAVE
}

function isSessionEnd(error: unknown): boolean {
  return isApiError(error) && error.status === 401
}

function openDay(): void {
  pending.value.push({ key: `new-${id}-${counter++}`, start: '', end: '' })
}

async function save(row: Row): Promise<void> {
  if (busy.value !== null) return
  dayError.value = ''
  const problem = problemOf(row)
  if (problem !== '') {
    errors[row.key] = problem
    return
  }
  delete errors[row.key]
  busy.value = row.key
  try {
    if (row.id === null) await add(row)
    else await change(row, row.id)
  } finally {
    busy.value = null
  }
}

async function add(row: Row): Promise<void> {
  try {
    await store.addSlot(barberia, { dia: day, abre: row.start, cierra: row.end })
    pending.value = pending.value.filter((item) => item.key !== row.key)
    emit('done', 'Franja agregada.')
  } catch (error) {
    if (!isSessionEnd(error)) errors[row.key] = messageOf(error)
  }
}

/** Changing a slot is removing the old one and adding the new one, in that order. */
async function change(row: Row, slotId: string): Promise<void> {
  const old: TimeSlot = saved.value.find((slot) => slot.id === slotId)!
  try {
    await store.removeSlot(barberia, slotId)
  } catch (error) {
    // Nothing changed: the old slot is still there.
    if (!isSessionEnd(error)) errors[row.key] = `${FAILED_SAVE} No se cambió nada.`
    return
  }
  delete drafts[slotId]
  try {
    await store.addSlot(barberia, { dia: day, abre: row.start, cierra: row.end })
    emit('done', 'Franja actualizada.')
  } catch (error) {
    if (isSessionEnd(error)) return
    // The new slot was refused: the old one comes back, and the error says so.
    try {
      const restored = await store.addSlot(barberia, {
        dia: day,
        abre: old.abre,
        cierra: old.cierra,
      })
      errors[restored.id] =
        `${messageOf(error)} Se mantuvo la franja de ${old.abre} a ${old.cierra}.`
    } catch (restoreError) {
      if (isSessionEnd(restoreError)) return
      // Neither worked: what the API holds now is the truth.
      await store.load(barberia)
      dayError.value = `No pudimos restaurar la franja de ${old.abre} a ${old.cierra}. Revise el horario y agréguela de nuevo.`
    }
  }
}

async function remove(row: Row): Promise<void> {
  if (busy.value !== null) return
  dayError.value = ''
  if (row.id === null) {
    pending.value = pending.value.filter((item) => item.key !== row.key)
    delete errors[row.key]
    return
  }
  busy.value = row.key
  delete errors[row.key]
  try {
    await store.removeSlot(barberia, row.id)
    delete drafts[row.id]
    emit('done', 'Franja quitada.')
  } catch (error) {
    if (!isSessionEnd(error)) errors[row.key] = FAILED_REMOVE
  } finally {
    busy.value = null
  }
}

function discard(row: Row): void {
  if (row.id !== null) delete drafts[row.id]
  delete errors[row.key]
}

function hourLabel(kind: 'Apertura' | 'Cierre', index: number): string {
  return `${kind} del ${label.toLowerCase()}, franja ${index + 1}`
}
</script>

<template>
  <section :aria-labelledby="`${id}-title`" class="border-b border-line py-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-baseline gap-3">
        <h3 :id="`${id}-title`" class="text-base font-medium text-fg">{{ label }}</h3>
        <p class="text-sm" :class="isOpen ? 'text-fg' : 'text-fg-muted'">
          {{ isOpen ? 'Abierto' : 'Cerrado' }}
        </p>
      </div>
      <BaseButton v-if="!isOpen && pending.length === 0" variant="secondary" @click="openDay"
        >Abrir este día</BaseButton
      >
    </div>

    <p v-if="!isOpen && pending.length === 0" class="mt-1 text-sm text-fg-muted">
      No atiende este día.
    </p>
    <p v-else-if="!isOpen" class="mt-1 text-sm text-fg-muted">
      Escriba la apertura y el cierre, y guarde la franja para abrir este día.
    </p>

    <p
      v-if="dayError"
      role="alert"
      class="mt-3 flex items-start gap-2 rounded-field bg-danger-surface p-3 text-sm text-danger"
    >
      <CircleAlert :size="16" :stroke-width="1.5" class="mt-0.5 shrink-0" aria-hidden="true" />
      <span>{{ dayError }}</span>
    </p>

    <ul v-if="rows.length > 0" class="mt-3 flex flex-col gap-4">
      <li v-for="(row, index) in rows" :key="row.key" :data-testid="`slot-${row.key}`">
        <div class="flex flex-wrap items-center gap-3">
          <TimeField
            :label="hourLabel('Apertura', index)"
            :model-value="row.start"
            :invalid="errors[row.key] !== undefined"
            :described-by="errors[row.key] ? `${id}-${row.key}-error` : undefined"
            @update:model-value="(value: string) => setHour(row, 'start', value)"
          />
          <span class="text-sm text-fg-muted" aria-hidden="true">a</span>
          <TimeField
            :label="hourLabel('Cierre', index)"
            :model-value="row.end"
            :invalid="errors[row.key] !== undefined"
            :described-by="errors[row.key] ? `${id}-${row.key}-error` : undefined"
            @update:model-value="(value: string) => setHour(row, 'end', value)"
          />
          <div class="flex flex-wrap items-center gap-2">
            <BaseButton
              v-if="row.dirty"
              :loading="busy === row.key"
              :disabled="busy !== null"
              @click="save(row)"
              >{{ row.id === null ? 'Guardar franja' : 'Guardar cambio' }}</BaseButton
            >
            <BaseButton
              v-if="row.dirty && row.id !== null"
              variant="secondary"
              :disabled="busy !== null"
              @click="discard(row)"
              >Descartar</BaseButton
            >
            <BaseButton
              v-if="!row.dirty || row.id === null"
              variant="secondary"
              :disabled="busy !== null"
              :aria-label="
                row.id === null
                  ? `Quitar la franja sin guardar del ${label.toLowerCase()}`
                  : `Quitar la franja de ${row.start} a ${row.end} del ${label.toLowerCase()}`
              "
              @click="remove(row)"
              >Quitar</BaseButton
            >
          </div>
        </div>
        <p
          v-if="errors[row.key]"
          :id="`${id}-${row.key}-error`"
          role="alert"
          class="mt-2 flex items-start gap-2 text-sm text-danger"
        >
          <CircleAlert :size="16" :stroke-width="1.5" class="mt-0.5 shrink-0" aria-hidden="true" />
          <span>{{ errors[row.key] }}</span>
        </p>
      </li>
    </ul>

    <BaseButton
      v-if="isOpen || pending.length > 0"
      variant="secondary"
      class="mt-3"
      :disabled="busy !== null"
      @click="openDay"
    >
      <template #icon><Plus :size="16" :stroke-width="1.5" aria-hidden="true" /></template>
      Agregar franja
    </BaseButton>
  </section>
</template>
