<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { ArrowRight, Calendar, ChevronDown, CircleAlert } from 'lucide-vue-next'

import BaseButton from '@/components/BaseButton.vue'
import BookingSummary from '@/components/BookingSummary.vue'
import DatePicker, { type PickerDay } from '@/components/DatePicker.vue'
import ErrorState from '@/components/ErrorState.vue'
import InlineNotice from '@/components/InlineNotice.vue'
import TimeSlots, { type TimeSlot } from '@/components/TimeSlots.vue'
import { colones, formatShortDate, secondsUntil } from '@/composables/format'
import { useCountdown } from '@/composables/useCountdown'

export interface PanelBarber {
  id: string
  name: string
}

/** The choice in progress, once a space is picked. */
export interface PanelSelection {
  serviceName: string
  barberName: string
  date: string
  startTime: string
  endTime?: string
  price: number
}

/** The reservation that was created. */
export interface PanelReservation extends PanelSelection {
  /** Length of the service in minutes. */
  durationMinutes?: number
  status: 'temporary' | 'confirmed'
  /** ISO 8601 deadline to pay; `null` when the API does not bring it. */
  expiresAt: string | null
}

export interface PanelFailure {
  title: string
  description: string
}

interface Props {
  /** `false` while no service is chosen: the right column shows its empty state. */
  hasService: boolean
  barbers: PanelBarber[]
  days: PickerDay[]
  slots: TimeSlot[]
  /** State of the hours: loading, error, no hours that day, or hours to choose from. */
  hoursState: 'loading' | 'error' | 'empty' | 'ready'
  /** Nearest days that do have hours (only when the chosen day has none). */
  nextDays?: string[]
  /** Price of the chosen service (VAT included), shown in the bottom bar. */
  total?: number | null
  selection?: PanelSelection | null
  submitting?: boolean
  failure?: PanelFailure | null
  /** Spaces offered when the chosen one was taken. */
  alternatives?: TimeSlot[]
  notice?: string | null
  reservation?: PanelReservation | null
  /** A payment of the reservation is under way: it cannot be edited (the reservation is not cancelled). */
  paymentInProgress?: boolean
  /** The reservation is being cancelled to edit it. */
  editing?: boolean
}

const {
  hasService,
  barbers,
  days,
  slots,
  hoursState,
  nextDays = [],
  total = null,
  selection = null,
  submitting = false,
  failure = null,
  alternatives = [],
  notice = null,
  reservation = null,
  paymentInProgress = false,
  editing = false,
} = defineProps<Props>()

/** Chosen barber; the empty string is «Sin preferencia». */
const barber = defineModel<string>('barber', { default: '' })
const date = defineModel<string | null>('date', { default: null })
const slot = defineModel<string | null>('slot', { default: null })

const emit = defineEmits<{
  retry: []
  reserve: []
  restart: []
  /** «Continuar al pago» of a temporary reservation. */
  pay: []
  /** «Editar reserva»: back to the selection, giving the space up. */
  edit: []
  'pick-alternative': [value: string]
}>()

const barberLabelId = useId()
const hourLabelId = useId()

const anyBarber = computed(() => barber.value === '')
const barberName = computed(() => barbers.find((item) => item.id === barber.value)?.name ?? '')
const dayText = computed(() => (date.value ? formatShortDate(date.value) : ''))

const emptyTitle = computed(() =>
  anyBarber.value
    ? `No quedan horas el ${dayText.value}.`
    : `No quedan horas el ${dayText.value} con ${barberName.value}.`,
)

/** The line of the bottom bar: what is chosen, or what is missing. */
const summaryHint = computed(() => {
  if (!hasService) return 'Elija un servicio para continuar'
  if (hoursState === 'loading') return 'Cargando…'
  return 'Elija una hora para continuar'
})

const announcement = computed(() => {
  if (reservation) return 'Reserva temporal creada.'
  if (!hasService) return ''
  if (hoursState === 'loading') return 'Cargando horas disponibles.'
  if (hoursState === 'error') return 'No pudimos cargar los horarios.'
  if (hoursState === 'empty') return `No hay horas disponibles el ${dayText.value}.`
  return `${slots.length} ${slots.length === 1 ? 'hora disponible' : 'horas disponibles'} el ${dayText.value}.`
})

// The countdown of a temporary reservation, from the deadline the API gives (10 minutes otherwise).
const countdown = useCountdown()
const counting = ref(false)
watch(
  () => reservation,
  (current) => {
    if (current && current.status === 'temporary') {
      countdown.start(current.expiresAt ? secondsUntil(current.expiresAt) : 600)
      counting.value = true
    } else {
      countdown.stop()
      counting.value = false
    }
  },
  { immediate: true },
)
const expired = computed(() => counting.value && countdown.remaining.value === 0)
</script>

<template>
  <section
    aria-labelledby="booking-title"
    class="md:rounded-card md:border md:border-line md:p-8"
    :aria-busy="hasService && hoursState === 'loading'"
  >
    <div class="md:border-b md:border-line md:pb-6">
      <h2 id="booking-title" class="text-2xl">Reservar cita</h2>
    </div>
    <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>

    <div
      class="mt-8 flex flex-col gap-8 md:mt-6"
      :class="reservation ? 'md:mx-auto md:w-full md:max-w-110' : 'md:grid md:grid-cols-2'"
    >
      <!-- Left: service and barber (gone once there is a reservation) -->
      <div v-if="!reservation" class="flex min-w-0 flex-col gap-8">
        <div>
          <h3 class="mb-2 text-lg font-medium">Servicio</h3>
          <slot name="services" />
        </div>

        <div v-if="hasService && !reservation">
          <h3 :id="barberLabelId" class="mb-4 text-lg font-medium">Barbero</h3>
          <div class="relative">
            <select
              v-model="barber"
              :aria-labelledby="barberLabelId"
              class="h-13 w-full cursor-pointer appearance-none rounded-field border border-field bg-canvas pr-11 pl-4 text-base font-medium text-fg transition-colors duration-150 ease-out hover:border-fg-muted"
            >
              <option value="">Sin preferencia</option>
              <option v-for="item in barbers" :key="item.id" :value="item.id">
                {{ item.name }}
              </option>
            </select>
            <ChevronDown
              :size="18"
              :stroke-width="1.5"
              class="pointer-events-none absolute top-4 right-4 text-fg-muted"
              aria-hidden="true"
            />
          </div>
        </div>
      </div>

      <!-- Right: date and hour, the empty state or the reservation created -->
      <div
        class="flex min-w-0 flex-col gap-8"
        :class="reservation ? '' : 'md:border-l md:border-line md:pl-8'"
      >
        <template v-if="reservation">
          <div class="flex flex-col gap-4">
            <h3 class="text-lg font-medium">Su reserva</h3>
            <template v-if="expired">
              <InlineNotice title="La reserva temporal venció.">
                El espacio volvió a estar disponible. Elija una hora de nuevo.
              </InlineNotice>
              <div>
                <BaseButton @click="emit('restart')">Elegir otro horario</BaseButton>
              </div>
            </template>
            <template v-else>
              <BookingSummary
                :service-name="reservation.serviceName"
                :barber-name="reservation.barberName"
                :date="reservation.date"
                :start-time="reservation.startTime"
                :end-time="reservation.endTime"
                :price="reservation.price"
                :status="reservation.status"
                :duration-minutes="reservation.durationMinutes"
                :remaining="countdown.formatted.value"
              />
              <template v-if="reservation.status === 'temporary'">
                <InlineNotice v-if="paymentInProgress" title="Tiene un pago en proceso.">
                  Continúe al pago para ver su estado.
                </InlineNotice>
                <div class="flex flex-wrap gap-3">
                  <BaseButton size="large" @click="emit('pay')">Continuar al pago</BaseButton>
                  <BaseButton
                    v-if="!paymentInProgress"
                    size="large"
                    variant="secondary"
                    :loading="editing"
                    @click="emit('edit')"
                  >
                    Editar reserva
                  </BaseButton>
                </div>
              </template>
            </template>
          </div>
        </template>

        <div
          v-else-if="!hasService"
          class="flex flex-col items-center gap-4 px-8 py-16 text-center"
        >
          <div
            class="grid size-14 place-items-center rounded-full border border-line-strong bg-surface"
            aria-hidden="true"
          >
            <Calendar :size="24" :stroke-width="1.5" class="text-fg-muted" />
          </div>
          <div>
            <p class="text-lg font-medium">Elija un servicio</p>
            <p class="mt-1 max-w-90 text-sm text-fg-muted">
              Con el servicio elegido podrá escoger barbero, día y hora disponibles.
            </p>
          </div>
        </div>

        <template v-else>
          <InlineNotice v-if="notice" :title="notice">Elija otra hora.</InlineNotice>

          <DatePicker v-model="date" :days="days" />

          <div :aria-busy="hoursState === 'loading'">
            <h3 :id="hourLabelId" class="mb-4 text-lg font-medium">Hora</h3>

            <div v-if="hoursState === 'loading'" class="flex flex-wrap gap-2">
              <span class="sr-only">Cargando</span>
              <div
                v-for="index in 8"
                :key="index"
                class="h-11 w-18 animate-pulse rounded-pill bg-line motion-reduce:animate-none"
                aria-hidden="true"
              />
            </div>

            <div
              v-else-if="hoursState === 'error'"
              role="alert"
              class="flex items-start gap-3 rounded-field bg-danger-surface p-4"
            >
              <CircleAlert
                :size="20"
                :stroke-width="1.5"
                class="mt-0.5 flex-none text-danger"
                aria-hidden="true"
              />
              <div class="flex flex-col items-start gap-4">
                <div>
                  <p class="font-medium text-danger">No pudimos cargar los horarios.</p>
                  <p class="mt-1 text-sm text-fg-muted">
                    Revise su conexión e intente de nuevo. Lo que ya eligió se mantiene.
                  </p>
                </div>
                <BaseButton variant="secondary" @click="emit('retry')">Reintentar</BaseButton>
              </div>
            </div>

            <div
              v-else-if="hoursState === 'empty'"
              class="flex flex-col gap-4 border-y border-line py-4"
            >
              <div>
                <p class="font-medium">{{ emptyTitle }}</p>
                <p class="mt-1 text-sm text-fg-muted">
                  Pruebe otro día<template v-if="!anyBarber"
                    >, o elija «Sin preferencia» para ver las horas de todos los barberos</template
                  >.
                </p>
              </div>
              <div class="flex flex-wrap gap-2">
                <BaseButton
                  v-for="next in nextDays"
                  :key="next"
                  variant="secondary"
                  @click="date = next"
                >
                  Ver {{ formatShortDate(next) }}
                </BaseButton>
                <button
                  v-if="!anyBarber"
                  type="button"
                  class="inline-flex min-h-11 items-center gap-1.5 rounded-pill px-3 text-sm font-medium text-fg transition-colors duration-150 ease-out hover:bg-surface"
                  @click="barber = ''"
                >
                  <span>Sin preferencia</span>
                  <ArrowRight :size="16" :stroke-width="1.5" class="flex-none" aria-hidden="true" />
                </button>
              </div>
            </div>

            <TimeSlots v-else v-model="slot" :slots="slots" :show-barber="anyBarber" />
          </div>

          <div v-if="failure" class="rounded-card bg-danger-surface">
            <ErrorState
              :message="failure.title"
              :description="failure.description"
              retry-label="Reintentar"
              @retry="emit('reserve')"
            />
          </div>
          <div v-if="alternatives.length > 0" class="flex flex-col gap-2">
            <h3 class="text-lg font-medium">Otros espacios cercanos</h3>
            <TimeSlots
              :model-value="null"
              :slots="alternatives"
              label="Otros espacios cercanos"
              @update:model-value="(value) => value && emit('pick-alternative', value)"
            />
          </div>
        </template>
      </div>
    </div>

    <!-- Bottom bar: on phone it sticks to the bottom of the screen with the summary in a card -->
    <div
      v-if="!reservation"
      class="mt-8 flex flex-col gap-4 border-t border-line max-md:sticky max-md:bottom-0 max-md:z-10 max-md:-mx-5 max-md:bg-canvas max-md:px-5 max-md:pt-4 max-md:pb-6 md:flex-row md:items-center md:justify-between md:gap-8 md:pt-6"
    >
      <div
        class="flex items-center justify-between gap-4 max-md:rounded-card max-md:border max-md:border-line max-md:bg-surface max-md:p-4 md:contents"
      >
        <p class="min-w-0 flex-1 text-base leading-snug">
          <template v-if="selection">
            <span class="font-medium">{{ selection.serviceName }}</span>
            <span class="ml-3 text-fg-muted tabular-nums">
              {{ formatShortDate(selection.date) }},
              <span class="ml-1 text-sm md:text-base">{{ selection.startTime }}</span>
              <span v-if="selection.barberName" class="ml-2">con {{ selection.barberName }}</span>
            </span>
          </template>
          <span v-else class="text-fg-muted">{{ summaryHint }}</span>
        </p>
        <p v-if="total !== null" class="text-base whitespace-nowrap tabular-nums md:text-lg">
          {{ colones(total) }}
          <span class="text-sm text-fg-muted">IVA incluido</span>
        </p>
      </div>
      <BaseButton
        size="large"
        class="w-full md:w-70 disabled:border-line! disabled:bg-surface! disabled:text-fg-muted! disabled:opacity-100!"
        :disabled="slot === null"
        :loading="submitting"
        @click="emit('reserve')"
      >
        Reservar
      </BaseButton>
    </div>
  </section>
</template>
