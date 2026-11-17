<script setup lang="ts">
import { computed, useId, watch } from 'vue'
import { ChevronDown, CircleAlert } from 'lucide-vue-next'

import BaseButton from '@/components/BaseButton.vue'
import type { TimeSlot } from '@/components/TimeSlots.vue'
import { useCountdown } from '@/composables/useCountdown'
import { MAX_PHRASE_LENGTH, type AiBookingState } from '@/stores/aiBooking'

export interface BlockNotice {
  title: string
  body: string
}

interface Props {
  state: AiBookingState
  /** What was understood, in plain words («Corte + barba con Mauricio, el jue 5 nov.»). */
  sentence?: string
  /** A warning about data that is missing or that the shop does not have. */
  warning?: BlockNotice | null
  /** No spaces that day: the line above and below, like the hours of the booking panel. */
  empty?: BlockNotice | null
  /** The spaces of the proposal; none is chosen until the person picks one. */
  spaces?: TimeSlot[]
  /** «jue 5 nov con Mauricio»: what the spaces are for. */
  spacesFor?: string
  /** The space chosen below (its start), to mark its chip. */
  selected?: string | null
  /** Each chip says with whom it stays. */
  showBarber?: boolean
  /** What to tell the person when the request failed. */
  failure?: BlockNotice | null
  /** Seconds to wait before asking again (429 and 503 bring `Retry-After`). */
  retryAfter?: number | null
}

const {
  state,
  sentence = '',
  warning = null,
  empty = null,
  spaces = [],
  spacesFor = '',
  selected = null,
  showBarber = false,
  failure = null,
  retryAfter = null,
} = defineProps<Props>()

const open = defineModel<boolean>('open', { default: false })
const phrase = defineModel<string>('phrase', { default: '' })

const emit = defineEmits<{
  submit: []
  retry: []
  pick: [value: string]
}>()

const bodyId = useId()
const noteId = useId()

const loading = computed(() => state === 'loading')
const canSubmit = computed(() => phrase.value.trim() !== '' && !loading.value)

// The wait that the API asks for before another attempt.
const wait = useCountdown()
watch(
  () => (state === 'error' ? retryAfter : null),
  (seconds) => {
    if (seconds && seconds > 0) wait.start(seconds)
    else wait.stop()
  },
  { immediate: true },
)

/** One polite announcement for the states that have no focus of their own. */
const announcement = computed(() => {
  if (state === 'loading') return 'Generando propuesta.'
  if (state !== 'ready') return ''
  if (warning) return `${warning.title} ${warning.body}`
  if (empty) return empty.title
  return spaces.length > 0
    ? `Propuesta lista. ${spaces.length === 1 ? '1 espacio disponible' : `${spaces.length} espacios disponibles`}.`
    : 'Propuesta lista.'
})

function chipClasses(slot: TimeSlot): string[] {
  const base =
    'inline-flex min-h-11 items-center justify-center rounded-field border px-2 text-sm tabular-nums transition-colors duration-150 ease-out'
  return selected === slot.value
    ? [base, 'border-line-strong bg-elevated font-medium text-fg']
    : [base, 'border-line-strong bg-transparent text-fg hover:border-line-hover']
}

function chipLabel(slot: TimeSlot): string {
  return showBarber && slot.barberName ? `${slot.time} con ${slot.barberName}` : slot.time
}
</script>

<template>
  <section
    aria-label="Describa su cita"
    class="rounded-card border border-line px-4 pt-2 pb-4 md:px-6 md:pt-5 md:pb-6"
  >
    <button
      type="button"
      class="flex min-h-11 w-full items-center justify-between gap-3 text-left text-fg"
      :aria-expanded="open"
      :aria-controls="bodyId"
      @click="open = !open"
    >
      <span class="block min-w-0">
        <span class="block text-lg font-medium">Describa su cita</span>
        <span class="mt-0.5 block text-sm text-fg-muted">
          Escriba en una frase qué desea y generamos una propuesta.
        </span>
      </span>
      <ChevronDown
        :size="18"
        :stroke-width="1.5"
        class="flex-none text-fg-muted transition-transform duration-150 ease-out"
        :class="open ? 'rotate-180' : ''"
        aria-hidden="true"
      />
    </button>

    <div
      v-show="open"
      :id="bodyId"
      class="mt-4 grid items-start gap-x-8 gap-y-6 md:grid-cols-2"
      :aria-busy="loading"
    >
      <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>

      <form @submit.prevent="canSubmit && emit('submit')">
        <textarea
          v-model="phrase"
          aria-label="Describa su cita"
          :aria-describedby="noteId"
          rows="3"
          :maxlength="MAX_PHRASE_LENGTH"
          :disabled="loading"
          placeholder="Ejemplo: corte y barba con Mauricio el jueves por la tarde"
          class="block min-h-24 w-full resize-none rounded-field border border-field bg-canvas p-3 text-base text-fg transition-colors duration-150 ease-out placeholder:text-fg-subtle disabled:opacity-60 md:min-h-28"
        />
        <p :id="noteId" class="mt-2 text-sm text-fg-subtle">
          Se interpreta con inteligencia artificial. Nada se reserva hasta que usted continúe.
        </p>
        <BaseButton
          type="submit"
          size="large"
          class="mt-4 w-full md:w-50 disabled:border-line! disabled:bg-surface! disabled:text-fg-muted! disabled:opacity-100!"
          :disabled="!canSubmit"
        >
          {{ loading ? 'Generando propuesta…' : 'Generar propuesta' }}
        </BaseButton>
      </form>

      <div class="flex min-w-0 flex-col gap-4">
        <div v-if="state === 'idle'" class="hidden md:block">
          <p class="text-sm text-fg-muted">Puede escribir, por ejemplo:</p>
          <ul class="mt-2 flex flex-col gap-1.5 text-sm text-fg-muted">
            <li>«Corte y barba con Mauricio el jueves por la tarde»</li>
            <li>«Un fade el sábado en la mañana»</li>
            <li>«Barba con cualquier barbero mañana»</li>
          </ul>
        </div>

        <div v-else-if="state === 'loading'">
          <p class="text-sm text-fg-muted">Procesando su solicitud…</p>
          <div class="mt-3 flex flex-col gap-2.5" aria-hidden="true">
            <div class="h-4 w-4/5 animate-pulse rounded-field bg-line motion-reduce:animate-none" />
            <div class="h-4 w-3/5 animate-pulse rounded-field bg-line motion-reduce:animate-none" />
            <div class="mt-1.5 flex gap-2">
              <div
                v-for="index in 3"
                :key="index"
                class="h-11 w-18 animate-pulse rounded-field bg-line motion-reduce:animate-none"
              />
            </div>
          </div>
        </div>

        <div
          v-else-if="state === 'error'"
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
              <p class="font-medium text-danger">
                {{ failure?.title ?? 'No pudimos procesar su solicitud.' }}
              </p>
              <p class="mt-1 text-sm text-fg-muted">
                {{
                  failure?.body ??
                  'Puede continuar a mano: elija el servicio, el barbero, el día y la hora aquí abajo. Su frase se conserva.'
                }}
              </p>
              <p v-if="wait.active.value" class="mt-1 text-sm text-fg-muted">
                Podrá intentar de nuevo en {{ wait.remaining.value }} s.
              </p>
            </div>
            <BaseButton variant="secondary" :disabled="wait.active.value" @click="emit('retry')">
              Reintentar
            </BaseButton>
          </div>
        </div>

        <template v-else-if="state === 'ready'">
          <div v-if="sentence !== ''">
            <p class="text-sm text-fg-muted">Solicitud</p>
            <p class="mt-0.5 font-medium">{{ sentence }}</p>
          </div>

          <div v-if="warning" class="flex items-start gap-3 rounded-field bg-warning-surface p-4">
            <CircleAlert
              :size="20"
              :stroke-width="1.5"
              class="mt-0.5 flex-none text-warning"
              aria-hidden="true"
            />
            <div>
              <p class="font-medium text-warning">{{ warning.title }}</p>
              <p class="mt-1 text-sm text-fg-muted">{{ warning.body }}</p>
            </div>
          </div>

          <div v-if="spaces.length > 0" role="group" aria-label="Espacios disponibles">
            <p class="mb-2 text-sm text-fg-muted">
              Espacios disponibles<template v-if="spacesFor !== ''"> — {{ spacesFor }}</template>
            </p>
            <div class="grid grid-cols-4 gap-2">
              <button
                v-for="slot in spaces"
                :key="slot.value"
                type="button"
                :class="chipClasses(slot)"
                :aria-pressed="selected === slot.value ? 'true' : 'false'"
                :aria-label="chipLabel(slot)"
                @click="emit('pick', slot.value)"
              >
                <span class="flex flex-col items-center leading-tight">
                  <span>{{ slot.time }}</span>
                  <span
                    v-if="showBarber && slot.barberName"
                    class="text-xs font-normal text-fg-muted"
                  >
                    con {{ slot.barberName }}
                  </span>
                </span>
              </button>
            </div>
            <p class="mt-2 text-sm text-fg-subtle">
              Al elegir una hora se completa abajo. Revise y reserve para continuar al pago.
            </p>
          </div>

          <div v-if="empty" class="flex flex-col gap-1 border-y border-line py-4">
            <p class="font-medium">{{ empty.title }}</p>
            <p class="text-sm text-fg-muted">{{ empty.body }}</p>
          </div>
        </template>
      </div>
    </div>
  </section>
</template>
