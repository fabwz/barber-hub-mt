<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { CalendarOff, Trash2 } from 'lucide-vue-next'

import { isApiError } from '@/api'
import AbsenceConflicts from '@/components/AbsenceConflicts.vue'
import BaseButton from '@/components/BaseButton.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import SegmentedControl from '@/components/SegmentedControl.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import TextField from '@/components/TextField.vue'
import TimeField from '@/components/TimeField.vue'
import { absencePeriodLabel } from '@/composables/absencePeriod'
import { readConflicts, type ConflictingReservation } from '@/composables/absenceConflicts'
import { todayInCostaRica } from '@/composables/format'
import { isValidTime } from '@/composables/timeOfDay'
import { useFormErrors } from '@/composables/useFormErrors'
import { useAbsencesStore, type Absence } from '@/stores/absences'
import { useTeamStore } from '@/stores/team'

// «Bloqueos de agenda» (RF-07, láminas 30a a 30l): the owner blocks hours or days of a collaborator, sees the blocks that
// have not ended and removes them. Saving over reservations is a 409 with the list (CO-06): the owner reschedules or
// cancels each one here, with the actions that already exist, and saves again. «Guardar» waits for that.
type Mode = 'hours' | 'days'
type Field = 'collaborator' | 'date' | 'endDate' | 'from' | 'to'

const route = useRoute()
const absences = useAbsencesStore()
const team = useTeamStore()

const barberia = computed(() => String(route.params.barberia))
const today = todayInCostaRica()

onMounted(() => {
  void absences.load(barberia.value)
  void team.load(barberia.value)
})

/** Only an active collaborator has an agenda to block (RF-07). */
const collaborators = computed(() => team.items.filter((item) => item.activo))
const nameOf = (id: string): string =>
  team.items.find((item) => item.id === id)?.nombre ?? 'Colaborador'

const collaborator = ref('')
const mode = ref<Mode>('hours')
const date = ref('')
const endDate = ref('')
const from = ref('')
const to = ref('')

const saving = ref(false)
const failure = ref('')
const done = ref('')
const errors = useFormErrors<Field>({
  colaborador: 'collaborator',
  fecha_desde: 'date',
  fecha_hasta: 'endDate',
  hora_desde: 'from',
  hora_hasta: 'to',
})

// The reservations in the way, and what the owner did with each one.
const conflicts = ref<{ list: ConflictingReservation[]; total: number } | null>(null)
const resolved = ref<Record<string, string>>({})

const pendingConflicts = computed(
  () =>
    conflicts.value?.list.filter((reservation) => !(reservation.id in resolved.value)).length ?? 0,
)
/** The list says it is whole until every reservation shown is resolved; the next save tells if more were hidden. */
const canSave = computed(() => conflicts.value === null || pendingConflicts.value === 0)

const dateError = computed(() => errors.fieldErrors.value.date ?? errors.fieldErrors.value.endDate)
const timeError = computed(() => errors.fieldErrors.value.from ?? errors.fieldErrors.value.to)

// Another range or another collaborator is another question: the reservations found for the old one no longer apply.
watch([collaborator, mode, date, endDate, from, to], () => {
  conflicts.value = null
  resolved.value = {}
})

function range(): { desde: string; hasta: string; horaDesde?: string; horaHasta?: string } {
  return mode.value === 'hours'
    ? { desde: date.value, hasta: date.value, horaDesde: from.value, horaHasta: to.value }
    : { desde: date.value, hasta: endDate.value }
}

function check(): boolean {
  errors.clear()
  if (collaborator.value === '') errors.setField('collaborator', 'Elija el colaborador.')
  if (date.value === '') {
    errors.setField('date', mode.value === 'hours' ? 'Elija la fecha.' : 'Elija el primer día.')
  } else if (date.value < today) {
    errors.setField('date', 'La fecha no puede ser anterior a hoy.')
  }
  if (mode.value === 'days') {
    if (endDate.value === '') errors.setField('endDate', 'Elija el último día.')
    else if (date.value !== '' && endDate.value < date.value) {
      errors.setField('endDate', 'El último día no puede ser anterior al primero.')
    }
  } else if (!isValidTime(from.value) || !isValidTime(to.value)) {
    errors.setField('from', 'Escriba las horas como HH:MM, por ejemplo 14:00.')
  } else if (from.value >= to.value) {
    errors.setField('to', 'La hora final debe ser posterior a la inicial.')
  }
  return Object.keys(errors.fieldErrors.value).length === 0
}

async function save(): Promise<void> {
  if (saving.value || !canSave.value) return
  failure.value = ''
  done.value = ''
  if (!check()) return
  saving.value = true
  try {
    const { desde, hasta, horaDesde, horaHasta } = range()
    await absences.add(barberia.value, {
      colaborador: collaborator.value,
      fecha_desde: desde,
      fecha_hasta: hasta,
      ...(horaDesde === undefined || horaHasta === undefined
        ? {}
        : { hora_desde: horaDesde, hora_hasta: horaHasta }),
    })
    done.value = 'Bloqueo guardado. Esos espacios ya no se ofrecen.'
    reset()
  } catch (error) {
    if (!isApiError(error) || error.status === 401) return
    if (error.status === 409 && error.type.endsWith('ausencia-con-reservas')) {
      // The watcher clears the conflicts when the form changes, never here: the form is the same.
      const list = readConflicts(error.reservations)
      conflicts.value = { list, total: Math.max(error.reservationsTotal, list.length) }
      resolved.value = {}
    } else if (error.status === 409) {
      errors.setField(
        'collaborator',
        'Ese colaborador está inactivo: actívelo para bloquear su agenda.',
      )
    } else if (error.status === 404) {
      errors.setField('collaborator', 'Ese colaborador ya no está en la barbería.')
      void team.load(barberia.value)
    } else if (error.status === 422) {
      errors.setFromApiError(error)
    } else {
      failure.value = 'No pudimos guardar el bloqueo. Inténtelo de nuevo.'
    }
  } finally {
    saving.value = false
  }
}

function reset(): void {
  collaborator.value = ''
  date.value = ''
  endDate.value = ''
  from.value = ''
  to.value = ''
  errors.clear()
  conflicts.value = null
  resolved.value = {}
}

// Removing a block asks in its own row (lámina 30h): nothing else on the screen moves.
const confirming = ref<string | null>(null)
const removing = ref<string | null>(null)
const removeFailure = ref('')

async function remove(absence: Absence): Promise<void> {
  removing.value = absence.id
  removeFailure.value = ''
  try {
    await absences.remove(barberia.value, absence.id)
    done.value = 'Bloqueo quitado. Esos espacios vuelven a ofrecerse.'
  } catch (error) {
    if (!(isApiError(error) && error.status === 401)) {
      removeFailure.value = 'No pudimos quitar el bloqueo. Inténtelo de nuevo.'
    }
  } finally {
    removing.value = null
    confirming.value = null
  }
}

const SELECT_CLASS =
  'min-h-12 w-full max-w-120 rounded-field border bg-canvas px-3 text-base text-fg focus:border-accent focus:outline-none'
const MODES: Array<{ value: Mode; label: string }> = [
  { value: 'hours', label: 'Unas horas' },
  { value: 'days', label: 'Varios días' },
]
</script>

<template>
  <section aria-labelledby="absences-title" :aria-busy="absences.status === 'loading'">
    <h2 id="absences-title" class="text-lg font-medium">Bloqueos de agenda</h2>
    <p class="mt-1 text-sm text-fg-muted">
      Bloquee las horas o los días en que un colaborador no atiende. Esos espacios dejan de
      ofrecerse.
    </p>

    <div class="mt-6 flex flex-col gap-4">
      <div role="status">
        <NoticeBanner v-if="done">{{ done }}</NoticeBanner>
      </div>
      <NoticeBanner v-if="removeFailure" variant="error">{{ removeFailure }}</NoticeBanner>

      <h3 class="text-base font-medium">Bloqueos vigentes</h3>
      <SkeletonBlock
        v-if="
          absences.status === 'idle' ||
          (absences.status === 'loading' && absences.items.length === 0)
        "
        shape="row"
        :count="2"
        label="Cargando los bloqueos"
      />
      <ErrorState
        v-else-if="absences.status === 'error'"
        message="No pudimos cargar los bloqueos"
        description="Revise su conexión e inténtelo de nuevo."
        @retry="absences.load(barberia)"
      />
      <EmptyState
        v-else-if="absences.items.length === 0"
        title="No hay bloqueos vigentes."
        description="Cuando bloquee horas o días de un colaborador, aparecerán aquí."
      />
      <ul v-else class="flex flex-col border-t border-line" aria-label="Bloqueos vigentes">
        <li
          v-for="absence in absences.items"
          :key="absence.id"
          class="flex flex-wrap items-center justify-between gap-3 border-b border-line py-3"
          :data-testid="`absence-${absence.id}`"
        >
          <div class="min-w-0">
            <p class="font-medium text-fg">{{ nameOf(absence.colaborador) }}</p>
            <p class="text-sm text-fg-muted tabular-nums">{{ absencePeriodLabel(absence) }}</p>
          </div>
          <div
            v-if="confirming === absence.id"
            class="flex flex-col gap-2"
            role="group"
            :aria-label="`Quitar el bloqueo de ${nameOf(absence.colaborador)}`"
          >
            <p class="text-sm text-fg">
              ¿Quitar el bloqueo de {{ nameOf(absence.colaborador) }}? Esos espacios volverán a
              estar disponibles.
            </p>
            <div class="flex flex-wrap gap-2">
              <BaseButton :disabled="removing !== null" @click="confirming = null"
                >No, dejarlo</BaseButton
              >
              <BaseButton
                variant="secondary"
                :loading="removing === absence.id"
                :disabled="removing !== null"
                @click="remove(absence)"
                >Sí, quitar bloqueo</BaseButton
              >
            </div>
          </div>
          <BaseButton
            v-else
            variant="secondary"
            :aria-label="`Quitar el bloqueo de ${nameOf(absence.colaborador)}, ${absencePeriodLabel(absence)}`"
            @click="confirming = absence.id"
          >
            <template #icon><Trash2 :size="16" :stroke-width="1.5" aria-hidden="true" /></template>
            Quitar
          </BaseButton>
        </li>
      </ul>

      <form class="mt-4 flex max-w-xl flex-col gap-4" novalidate @submit.prevent="save">
        <h3 class="text-base font-medium">Nuevo bloqueo</h3>
        <NoticeBanner v-if="failure" variant="error">{{ failure }}</NoticeBanner>

        <div class="flex flex-col gap-2">
          <label for="absence-collaborator" class="text-sm font-medium text-fg">Colaborador</label>
          <select
            id="absence-collaborator"
            v-model="collaborator"
            :class="[
              SELECT_CLASS,
              errors.fieldErrors.value.collaborator ? 'border-danger-border' : 'border-field',
            ]"
            :aria-invalid="errors.fieldErrors.value.collaborator ? 'true' : undefined"
            :aria-describedby="
              errors.fieldErrors.value.collaborator ? 'absence-collaborator-error' : undefined
            "
            :disabled="team.status === 'loading' && collaborators.length === 0"
            @change="errors.clearField('collaborator')"
          >
            <option value="" disabled>Elija un colaborador</option>
            <option v-for="option in collaborators" :key="option.id" :value="option.id">
              {{ option.nombre }}
            </option>
          </select>
          <p
            v-if="errors.fieldErrors.value.collaborator"
            id="absence-collaborator-error"
            class="text-sm text-danger"
          >
            {{ errors.fieldErrors.value.collaborator }}
          </p>
        </div>

        <SegmentedControl v-model="mode" label="Duración del bloqueo" :options="MODES" />

        <template v-if="mode === 'hours'">
          <TextField
            v-model="date"
            type="date"
            label="Fecha"
            :min="today"
            :error="dateError"
            @input="errors.clearField('date')"
          />
          <fieldset class="flex flex-col gap-2">
            <legend class="text-sm font-medium text-fg">Horas</legend>
            <div class="flex flex-wrap items-end gap-4">
              <TimeField
                v-model="from"
                label="Desde"
                show-label
                :invalid="timeError !== undefined"
                described-by="absence-time-error"
              />
              <TimeField
                v-model="to"
                label="Hasta"
                show-label
                :invalid="timeError !== undefined"
                described-by="absence-time-error"
              />
            </div>
            <p v-if="timeError" id="absence-time-error" class="text-sm text-danger">
              {{ timeError }}
            </p>
          </fieldset>
        </template>
        <div v-else class="flex flex-wrap gap-4">
          <TextField
            v-model="date"
            type="date"
            label="Desde"
            :min="today"
            :error="errors.fieldErrors.value.date"
            @input="errors.clearField('date')"
          />
          <TextField
            v-model="endDate"
            type="date"
            label="Hasta"
            :min="date || today"
            :error="errors.fieldErrors.value.endDate"
            @input="errors.clearField('endDate')"
          />
        </div>

        <AbsenceConflicts
          v-if="conflicts"
          v-model:resolved="resolved"
          :shop-id="barberia"
          :reservations="conflicts.list"
          :total="conflicts.total"
        />

        <div class="flex flex-col gap-2">
          <div>
            <BaseButton type="submit" :loading="saving" :disabled="!canSave">
              <template #icon
                ><CalendarOff :size="16" :stroke-width="1.5" aria-hidden="true"
              /></template>
              Guardar bloqueo
            </BaseButton>
          </div>
          <p v-if="!canSave" class="text-sm text-fg-muted">
            Guardar se habilita cuando no queden reservas por resolver.
          </p>
        </div>
      </form>
    </div>
  </section>
</template>
