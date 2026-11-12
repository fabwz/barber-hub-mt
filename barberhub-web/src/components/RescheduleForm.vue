<script setup lang="ts">
import { computed, onMounted, ref, useTemplateRef, watch } from 'vue'

import { api, isApiError, type ApiError, type components } from '@/api'
import { networkError } from '@/api/errors'
import BaseButton from '@/components/BaseButton.vue'
import DatePicker from '@/components/DatePicker.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import TimeSlots, { type TimeSlot } from '@/components/TimeSlots.vue'
import { addDays, dateOf, formatShortDate, timeOf, todayInCostaRica } from '@/composables/format'
import { useAvailability } from '@/composables/useAvailability'

export type RescheduledBooking = components['schemas']['Reserva']

interface Props {
  shopId: string
  reservationId: string
  serviceId: string
  barberId: string
  barberName: string
  /** ISO 8601 start of the reservation being moved. */
  start: string
  /** Hours before the start up to which the change is free (CA-01). */
  cancellationHours: number
}

/** Days offered by the date picker: the booking window of DI-03. */
const WINDOW_DAYS = 30

const props = defineProps<Props>()

const emit = defineEmits<{
  /** The reservation was moved; carries the reservation the API answers. */
  done: [booking: RescheduledBooking]
  /** The person goes back to the reservation without changing it. */
  back: []
}>()

const today = todayInCostaRica()
const title = useTemplateRef<HTMLElement>('title')
onMounted(() => title.value?.focus())

const date = ref<string | null>(dateOf(props.start) < today ? today : dateOf(props.start))
const slot = ref<string | null>(null)
const submitting = ref(false)
const failure = ref<ApiError | null>(null)
/** Spaces the API offers when the chosen one was taken (409 `espacio-no-disponible`). */
const alternatives = ref<TimeSlot[]>([])

// Same service and same barber as the reservation: only the day and the hour change.
const availability = useAvailability({
  shopId: () => props.shopId,
  serviceId: () => props.serviceId,
  barberId: () => props.barberId,
  date: () => date.value,
})

// Another day drops the chosen hour (one picked from the alternatives already belongs to its day).
watch(date, (value) => {
  if (slot.value !== null && dateOf(slot.value) !== value) slot.value = null
  failure.value = null
  alternatives.value = []
})

const days = computed(() =>
  Array.from({ length: WINDOW_DAYS }, (_, index) => {
    const value = addDays(today, index)
    return { value, closed: availability.days.value[value] === 'closed' }
  }),
)

const slots = computed<TimeSlot[]>(() =>
  availability.slots.value.map((item) => ({ value: item.start, time: timeOf(item.start) })),
)

const hoursState = computed(() => {
  if (availability.error.value) return 'error'
  if (availability.loaded.value) return slots.value.length === 0 ? 'empty' : 'ready'
  return 'loading'
})

const dayText = computed(() => (date.value ? formatShortDate(date.value) : ''))

const announcement = computed(() => {
  if (hoursState.value === 'loading') return 'Cargando horas disponibles.'
  if (hoursState.value === 'error') return 'No pudimos cargar los horarios.'
  if (hoursState.value === 'empty') return `No hay horas disponibles el ${dayText.value}.`
  return `${slots.value.length} ${slots.value.length === 1 ? 'hora disponible' : 'horas disponibles'} el ${dayText.value}.`
})

const deadline = computed(() => {
  const hours = props.cancellationHours
  if (hours <= 0) return 'Puede cambiar la hora sin costo hasta el inicio de la cita.'
  return `Puede cambiar la hora sin costo hasta ${hours} ${hours === 1 ? 'hora' : 'horas'} antes de la cita.`
})

/** The API says why the space is gone and offers others; any other failure shows what the API says. */
const spaceGone = computed(
  () => failure.value?.status === 409 && failure.value.type.endsWith('espacio-no-disponible'),
)

/** The contract does not type the 409 members, so they are read defensively. */
function readAlternatives(raw: unknown[]): TimeSlot[] {
  return raw.flatMap((item): TimeSlot[] => {
    if (typeof item !== 'object' || item === null) return []
    const { inicio } = item as Record<string, unknown>
    return typeof inicio === 'string' ? [{ value: inicio, time: timeOf(inicio) }] : []
  })
}

function pickAlternative(value: string | null): void {
  if (value === null) return
  failure.value = null
  alternatives.value = []
  date.value = dateOf(value)
  slot.value = value
}

async function confirm(): Promise<void> {
  if (slot.value === null || submitting.value) return
  submitting.value = true
  failure.value = null
  alternatives.value = []
  try {
    const { data } = await api.PATCH('/barberias/{barberia}/reservas/{reserva}', {
      params: { path: { barberia: props.shopId, reserva: props.reservationId } },
      body: { inicio: slot.value, barbero: props.barberId },
    })
    emit('done', data!.data)
  } catch (caught) {
    const error = isApiError(caught) ? caught : networkError(caught)
    failure.value = error
    if (error.status === 409 && error.type.endsWith('espacio-no-disponible')) {
      alternatives.value = readAlternatives(error.alternatives)
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <!-- Desktop: the date on the left, the hours on the right, the confirmation across the bottom -->
  <section
    aria-labelledby="reschedule-title"
    :aria-busy="hoursState === 'loading'"
    class="flex flex-col gap-8 md:grid md:grid-cols-2"
  >
    <div class="md:col-span-2 md:border-b md:border-line md:pb-6">
      <h1 id="reschedule-title" ref="title" tabindex="-1" class="text-2xl outline-offset-4">
        Reprogramar reserva
      </h1>
      <p class="mt-2 text-fg-muted">
        Elija otro día y otra hora con {{ barberName }}. No se vuelve a cobrar el depósito.
      </p>
      <p class="mt-1 text-sm text-fg-subtle">{{ deadline }}</p>
    </div>
    <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>

    <div class="min-w-0 md:pr-8">
      <DatePicker v-model="date" :days="days" wrap />
    </div>

    <div class="min-w-0 md:border-l md:border-line md:pl-8">
      <div>
        <h2 class="mb-4 text-lg font-medium">Hora</h2>

        <SkeletonBlock
          v-if="hoursState === 'loading'"
          shape="row"
          :count="2"
          label="Cargando horas disponibles"
        />

        <div v-else-if="hoursState === 'error'" class="rounded-card bg-danger-surface">
          <ErrorState
            message="No pudimos cargar los horarios."
            description="Revise su conexión e intente de nuevo. Su reserva actual no cambia."
            @retry="availability.retry()"
          />
        </div>

        <div v-else-if="hoursState === 'empty'" class="border-y border-line py-4">
          <p class="font-medium">No quedan horas el {{ dayText }} con {{ barberName }}.</p>
          <p class="mt-1 text-sm text-fg-muted">Pruebe otro día.</p>
          <div v-if="availability.nextDays.value.length > 0" class="mt-4 flex flex-wrap gap-2">
            <BaseButton
              v-for="next in availability.nextDays.value"
              :key="next.date"
              variant="secondary"
              @click="date = next.date"
            >
              Ver {{ formatShortDate(next.date) }}
            </BaseButton>
          </div>
        </div>

        <TimeSlots v-else v-model="slot" :slots="slots" wrap />
      </div>
    </div>

    <!-- The new space was taken, or the change is not allowed: the original reservation stays as it was -->
    <div v-if="failure" class="flex flex-col gap-4 md:col-span-2">
      <NoticeBanner variant="error">
        <p class="font-medium">
          {{ spaceGone ? 'Ese espacio ya no está disponible.' : failure.title }}
        </p>
        <p class="mt-1 text-fg-muted">
          {{
            spaceGone
              ? 'Su reserva actual no cambió. Elija uno de los espacios cercanos o cambie la hora.'
              : `${failure.detail} Su reserva actual no cambió.`
          }}
        </p>
      </NoticeBanner>
      <div v-if="alternatives.length > 0" class="flex flex-col gap-2">
        <h2 class="text-lg font-medium">Otros espacios cercanos</h2>
        <TimeSlots
          :model-value="null"
          :slots="alternatives"
          label="Otros espacios cercanos"
          wrap
          @update:model-value="pickAlternative"
        />
      </div>
    </div>

    <div
      class="flex flex-col gap-3 border-t border-line pt-6 max-md:-mt-2 md:col-span-2 md:flex-row md:items-center md:justify-between md:gap-8"
    >
      <div class="flex min-w-0 flex-col gap-1 md:flex-1">
        <p class="text-base text-fg-muted md:text-lg">
          <template v-if="slot">
            Nueva cita
            <span class="font-medium text-fg tabular-nums"
              >{{ formatShortDate(dateOf(slot)) }}, {{ timeOf(slot) }}</span
            >
          </template>
          <template v-else>Elija una hora para continuar</template>
        </p>
        <p class="hidden text-sm text-fg-subtle md:block">
          Si el espacio se ocupa antes de confirmar, su reserva actual no cambia.
        </p>
      </div>
      <div class="flex flex-col gap-3 md:flex-none md:flex-row-reverse">
        <BaseButton
          size="large"
          class="w-full md:w-70"
          :disabled="slot === null"
          :loading="submitting"
          @click="confirm"
        >
          Confirmar nuevo horario
        </BaseButton>
        <BaseButton variant="secondary" size="large" class="w-full md:w-50" @click="emit('back')">
          Volver a la reserva
        </BaseButton>
      </div>
      <p class="text-sm text-fg-subtle md:hidden">
        Si el espacio se ocupa antes de confirmar, su reserva actual no cambia.
      </p>
    </div>
  </section>
</template>
