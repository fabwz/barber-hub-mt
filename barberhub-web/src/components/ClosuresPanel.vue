<script setup lang="ts">
import { ref } from 'vue'
import { CalendarX } from 'lucide-vue-next'

import { isApiError } from '@/api'
import BaseButton from '@/components/BaseButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import TextField from '@/components/TextField.vue'
import { formatDateWithYear, todayInCostaRica } from '@/composables/format'
import { useFormErrors } from '@/composables/useFormErrors'
import { useScheduleStore, type Closure } from '@/stores/schedule'

interface Props {
  barberia: string
}

const { barberia } = defineProps<Props>()

const emit = defineEmits<{
  /** An action ended well; the text is for the notice of the screen. */
  done: [message: string]
}>()

// Exceptions by date (HO-01): days the shop does not open, whatever its weekly schedule says. The API lists them
// from today on and refuses a date that already passed (422) or that already has one (409).
type Field = 'date' | 'reason'

const MAX_REASON = 200
const store = useScheduleStore()

const today = todayInCostaRica()
const date = ref('')
const reason = ref('')
const adding = ref(false)
const failure = ref('')
const errors = useFormErrors<Field>({ fecha: 'date', motivo: 'reason' })

const removing = ref<Closure | null>(null)
const confirmOpen = ref(false)
const removingBusy = ref(false)
const removeFailure = ref('')

function check(): boolean {
  errors.clear()
  if (date.value === '') errors.setField('date', 'Elija la fecha del cierre.')
  else if (date.value < today) errors.setField('date', 'La fecha no puede ser anterior a hoy.')
  return errors.fieldErrors.value.date === undefined
}

async function add(): Promise<void> {
  if (adding.value) return
  failure.value = ''
  if (!check()) return
  adding.value = true
  try {
    const text = reason.value.trim()
    await store.addClosure(barberia, {
      fecha: date.value,
      ...(text === '' ? {} : { motivo: text }),
    })
    date.value = ''
    reason.value = ''
    errors.clear()
    emit('done', 'Fecha cerrada agregada.')
  } catch (error) {
    if (!isApiError(error)) return
    if (error.status === 409) errors.setField('date', 'Ya hay una fecha cerrada ese día.')
    else if (error.status === 422) {
      errors.setFromApiError(error)
      if (
        errors.fieldErrors.value.date === undefined &&
        errors.fieldErrors.value.reason === undefined
      ) {
        errors.setField('date', 'La fecha no puede ser anterior a hoy.')
      }
    } else if (error.status !== 401) {
      failure.value = 'No pudimos agregar la fecha cerrada. Inténtelo de nuevo.'
    }
  } finally {
    adding.value = false
  }
}

function askToRemove(closure: Closure): void {
  removing.value = closure
  removeFailure.value = ''
  confirmOpen.value = true
}

async function remove(): Promise<void> {
  if (removing.value === null) return
  removingBusy.value = true
  try {
    await store.removeClosure(barberia, removing.value.id)
    emit('done', 'Cierre quitado.')
  } catch (error) {
    if (!(isApiError(error) && error.status === 401)) {
      removeFailure.value = 'No pudimos quitar el cierre. Inténtelo de nuevo.'
    }
  } finally {
    removingBusy.value = false
    confirmOpen.value = false
    removing.value = null
  }
}
</script>

<template>
  <section aria-labelledby="closures-title" class="mt-8">
    <h2 id="closures-title" class="text-lg font-medium">Excepciones por fecha</h2>
    <p class="mt-1 text-sm text-fg-muted">
      Feriados o cierres especiales: la barbería no atiende esos días.
    </p>

    <NoticeBanner v-if="removeFailure" variant="error" class="mt-4">{{
      removeFailure
    }}</NoticeBanner>

    <ul v-if="store.closures.length > 0" class="mt-4 flex flex-col" aria-label="Fechas cerradas">
      <li
        v-for="closure in store.closures"
        :key="closure.id"
        class="flex flex-wrap items-center justify-between gap-3 border-b border-line py-3"
      >
        <div class="min-w-0">
          <p class="font-medium text-fg tabular-nums">{{ formatDateWithYear(closure.fecha) }}</p>
          <p v-if="closure.motivo" class="text-sm text-fg-muted">{{ closure.motivo }}</p>
        </div>
        <BaseButton
          variant="secondary"
          :aria-label="`Quitar el cierre del ${formatDateWithYear(closure.fecha)}`"
          @click="askToRemove(closure)"
          >Quitar</BaseButton
        >
      </li>
    </ul>
    <p v-else class="mt-4 text-sm text-fg-muted">No hay fechas cerradas.</p>

    <form class="mt-6 flex max-w-xl flex-col gap-4" novalidate @submit.prevent="add">
      <h3 class="text-base font-medium">Agregar una fecha cerrada</h3>
      <NoticeBanner v-if="failure" variant="error">{{ failure }}</NoticeBanner>
      <TextField
        v-model="date"
        type="date"
        label="Fecha"
        :min="today"
        :error="errors.fieldErrors.value.date"
        @input="errors.clearField('date')"
      />
      <div class="flex flex-col gap-2">
        <TextField
          v-model="reason"
          label="Motivo (opcional)"
          autocomplete="off"
          :maxlength="MAX_REASON"
          aria-describedby="closure-reason-help"
          :error="errors.fieldErrors.value.reason"
          @input="errors.clearField('reason')"
        />
        <p id="closure-reason-help" class="text-sm text-fg-muted">
          Una nota breve para recordar el motivo. Los clientes no la ven.
        </p>
      </div>
      <div>
        <BaseButton type="submit" :loading="adding">
          <template #icon><CalendarX :size="16" :stroke-width="1.5" aria-hidden="true" /></template>
          Agregar fecha cerrada
        </BaseButton>
      </div>
    </form>

    <ConfirmDialog
      v-model="confirmOpen"
      :title="`¿Quitar el cierre del ${removing ? formatDateWithYear(removing.fecha) : ''}?`"
      description="Esa fecha volverá a regirse por el horario semanal."
      cancel-label="No, mantener el cierre"
      confirm-label="Sí, quitar el cierre"
      :loading="removingBusy"
      @confirm="remove"
    />
  </section>
</template>
