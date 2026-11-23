<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

import { api, isApiError, type ApiError } from '@/api'
import { networkError } from '@/api/errors'
import BaseButton from '@/components/BaseButton.vue'
import InlineNotice from '@/components/InlineNotice.vue'
import RescheduleForm from '@/components/RescheduleForm.vue'
import { absentFrom, closingAvailability } from '@/composables/reservationClosing'
import { timeOf } from '@/composables/format'
import type { AgendaPermissions } from '@/composables/useAgendaPermissions'

interface Props {
  shopId: string
  permissions: AgendaPermissions
  reservation: {
    id: string
    servicio: string
    barbero: string
    barbero_nombre: string
    estado: string
    inicio: string
    cliente_nombre: string
    plazo_cancelacion_horas: number
  }
}

const { shopId, permissions, reservation } = defineProps<Props>()

const emit = defineEmits<{
  /** The reservation changed in the API: the panel and the agenda read it again. */
  changed: []
}>()

type Mode = 'idle' | 'cancel' | 'reschedule'
type Pending = 'atendida' | 'ausente' | 'cancelar' | null

const mode = ref<Mode>('idle')
const pending = ref<Pending>(null)
const failure = ref<ApiError | null>(null)
/** What the last action did, for the person to read (`role="status"`). */
const done = ref('')

// The buttons wake up with the clock: «atendida» at the start and «ausente» ten minutes later.
const now = ref(Date.now())
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  timer = setInterval(() => (now.value = Date.now()), 15_000)
})
onBeforeUnmount(() => clearInterval(timer))

const confirmed = computed(() => reservation.estado === 'confirmada')
const availability = computed(() => closingAvailability(reservation.inicio, now.value))
const waitNote = computed(() =>
  availability.value.canAttend && availability.value.canMarkAbsent
    ? ''
    : `Podrá marcarla como atendida desde las ${timeOf(reservation.inicio)} y como ausente desde las ${absentFrom(reservation.inicio)}, diez minutos después de la hora de inicio.`,
)

/** A closed reservation shows no action, only why. */
const closedNote = computed(() => {
  switch (reservation.estado) {
    case 'completada':
      return 'Esta reserva ya fue marcada como atendida.'
    case 'ausente':
      return 'Esta reserva fue marcada como ausente.'
    case 'cancelada_por_cliente':
    case 'cancelada_por_barberia':
      return 'Esta reserva está cancelada.'
    case 'temporal':
      return 'Esta reserva espera el pago y aún no admite cambios.'
    default:
      return 'Esta reserva ya no admite cambios.'
  }
})

const busy = computed(() => pending.value !== null)

const PROGRESS: Record<Exclude<Pending, null>, string> = {
  atendida: 'Marcando como atendida…',
  ausente: 'Marcando como ausente…',
  cancelar: 'Cancelando la reserva…',
}
const SUCCESS: Record<Exclude<Pending, null>, string> = {
  atendida: 'Reserva marcada como atendida.',
  ausente: 'Reserva marcada como ausente.',
  cancelar: 'Reserva cancelada.',
}

async function run(action: Exclude<Pending, null>): Promise<void> {
  if (busy.value) return
  pending.value = action
  failure.value = null
  done.value = ''
  try {
    if (action === 'cancelar') {
      await api.POST('/barberias/{barberia}/reservas/{reserva}/cancelacion', {
        params: { path: { barberia: shopId, reserva: reservation.id } },
      })
    } else {
      await api.POST('/barberias/{barberia}/reservas/{reserva}/cierre', {
        params: { path: { barberia: shopId, reserva: reservation.id } },
        body: { estado: action },
      })
    }
    mode.value = 'idle'
    done.value = SUCCESS[action]
    emit('changed')
  } catch (caught) {
    failure.value = isApiError(caught) ? caught : networkError(caught)
    // The reservation may already be closed by someone else: read it again so the panel tells the truth.
    if (failure.value.status === 409) emit('changed')
  } finally {
    pending.value = null
  }
}

function onRescheduled(): void {
  mode.value = 'idle'
  failure.value = null
  done.value = 'Reserva reprogramada.'
  emit('changed')
}

function startCancel(): void {
  failure.value = null
  done.value = ''
  mode.value = 'cancel'
}

function startReschedule(): void {
  failure.value = null
  done.value = ''
  mode.value = 'reschedule'
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <!-- Progress and result of an action, read aloud without moving the focus. -->
    <div role="status">
      <InlineNotice v-if="pending !== null" :title="PROGRESS[pending]" />
      <InlineNotice v-else-if="done !== ''" :title="done" />
    </div>

    <div
      v-if="failure"
      role="alert"
      class="rounded-field bg-danger-surface p-4 text-sm"
      data-testid="action-error"
    >
      <p class="font-medium text-danger">No pudimos completar la acción.</p>
      <p class="mt-1 text-fg-muted">{{ failure.detail }}</p>
    </div>

    <RescheduleForm
      v-if="mode === 'reschedule'"
      compact
      deadline-note=""
      :shop-id="shopId"
      :reservation-id="reservation.id"
      :service-id="reservation.servicio"
      :barber-id="reservation.barbero"
      :barber-name="reservation.barbero_nombre"
      :start="reservation.inicio"
      :cancellation-hours="reservation.plazo_cancelacion_horas"
      @done="onRescheduled"
      @back="mode = 'idle'"
    />

    <template v-else-if="confirmed">
      <section
        v-if="mode === 'cancel'"
        aria-labelledby="cancel-title"
        class="flex flex-col gap-3 rounded-card border border-line p-4"
      >
        <h3 id="cancel-title" class="font-medium">
          ¿Cancelar la reserva de {{ reservation.cliente_nombre }}?
        </h3>
        <p class="text-sm text-fg-muted">
          Quedará como «cancelada por la barbería». El cliente recupera todo lo que pagó. Esta
          acción no se puede deshacer.
        </p>
        <div class="flex flex-col gap-2">
          <BaseButton :disabled="busy" @click="mode = 'idle'">No, mantenerla</BaseButton>
          <BaseButton
            variant="secondary"
            :loading="pending === 'cancelar'"
            :disabled="busy"
            @click="run('cancelar')"
            >{{ pending === 'cancelar' ? 'Cancelando' : 'Sí, cancelar reserva' }}</BaseButton
          >
        </div>
      </section>

      <div v-else class="flex flex-col gap-2">
        <template v-if="permissions.canClose">
          <BaseButton
            :disabled="!availability.canAttend || busy"
            :loading="pending === 'atendida'"
            @click="run('atendida')"
            >Marcar atendida</BaseButton
          >
          <BaseButton
            variant="secondary"
            :disabled="!availability.canMarkAbsent || busy"
            :loading="pending === 'ausente'"
            @click="run('ausente')"
            >Marcar ausente</BaseButton
          >
          <p v-if="waitNote" class="text-sm text-fg-muted">{{ waitNote }}</p>
        </template>
        <div
          v-if="permissions.canReschedule || permissions.canCancel"
          class="mt-2 flex flex-col gap-2 border-t border-line pt-4"
        >
          <BaseButton
            v-if="permissions.canReschedule"
            variant="secondary"
            :disabled="busy"
            @click="startReschedule"
            >Reprogramar</BaseButton
          >
          <BaseButton
            v-if="permissions.canCancel"
            variant="secondary"
            :disabled="busy"
            @click="startCancel"
            >Cancelar reserva</BaseButton
          >
        </div>
      </div>
    </template>

    <p v-else class="text-sm text-fg-muted" data-testid="closed-note">{{ closedNote }}</p>
  </div>
</template>
