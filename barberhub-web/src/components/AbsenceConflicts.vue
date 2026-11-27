<script setup lang="ts">
import { computed, ref } from 'vue'
import { Check, TriangleAlert } from 'lucide-vue-next'

import { api, isApiError, type ApiError } from '@/api'
import { networkError } from '@/api/errors'
import BaseButton from '@/components/BaseButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import ErrorState from '@/components/ErrorState.vue'
import RescheduleForm, { type RescheduledBooking } from '@/components/RescheduleForm.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import type { ConflictingReservation } from '@/composables/absenceConflicts'
import { dateOf, formatShortDate, timeOf } from '@/composables/format'

interface Props {
  shopId: string
  reservations: ConflictingReservation[]
  /** How many there are in all; the list may be shorter. */
  total: number
}

const { shopId, reservations, total } = defineProps<Props>()

/** What the owner did with each reservation, by id: the line that replaces its buttons. */
const resolved = defineModel<Record<string, string>>('resolved', { required: true })

const emit = defineEmits<{
  /** The owner changed a reservation: the block may be saved now. */
  changed: []
}>()

/** The part of the detail the form to move a reservation needs (the same fields the agenda reads). */
interface Detail {
  servicio: string
  barbero: string
  barbero_nombre: string
  inicio: string
  plazo_cancelacion_horas: number
}

const moving = ref<string | null>(null)
const detail = ref<Detail | null>(null)
const detailFailure = ref<ApiError | null>(null)
const loadingDetail = ref(false)

const cancelling = ref<ConflictingReservation | null>(null)
const cancelOpen = ref(false)
const cancelBusy = ref(false)
const actionFailure = ref<ApiError | null>(null)

const pending = computed(() =>
  reservations.filter((reservation) => !(reservation.id in resolved.value)),
)
const hidden = computed(() => Math.max(0, total - reservations.length))
/** What is left to resolve: those not listed, which the next save shows, plus the listed ones not resolved yet. */
const remaining = computed(() => pending.value.length + hidden.value)

function when(reservation: ConflictingReservation): string {
  const day = dateOf(reservation.start)
  return `${formatShortDate(day)}, ${timeOf(reservation.start)}–${timeOf(reservation.end)}`
}

function markResolved(id: string, text: string): void {
  resolved.value = { ...resolved.value, [id]: text }
  emit('changed')
}

async function startMove(reservation: ConflictingReservation): Promise<void> {
  moving.value = reservation.id
  detail.value = null
  detailFailure.value = null
  actionFailure.value = null
  loadingDetail.value = true
  try {
    const { data } = await api.GET('/barberias/{barberia}/reservas/{reserva}', {
      params: { path: { barberia: shopId, reserva: reservation.id } },
    })
    detail.value = data!.data as unknown as Detail
  } catch (caught) {
    detailFailure.value = isApiError(caught) ? caught : networkError(caught)
  } finally {
    loadingDetail.value = false
  }
}

function onMoved(booking: RescheduledBooking): void {
  const id = moving.value
  moving.value = null
  detail.value = null
  if (id === null) return
  markResolved(
    id,
    `Reprogramada a ${formatShortDate(dateOf(booking.inicio))}, ${timeOf(booking.inicio)}`,
  )
}

function askToCancel(reservation: ConflictingReservation): void {
  cancelling.value = reservation
  actionFailure.value = null
  cancelOpen.value = true
}

async function cancel(): Promise<void> {
  const reservation = cancelling.value
  if (reservation === null) return
  cancelBusy.value = true
  try {
    await api.POST('/barberias/{barberia}/reservas/{reserva}/cancelacion', {
      params: { path: { barberia: shopId, reserva: reservation.id } },
    })
    markResolved(reservation.id, 'Cancelada por la barbería')
  } catch (caught) {
    actionFailure.value = isApiError(caught) ? caught : networkError(caught)
  } finally {
    cancelBusy.value = false
    cancelOpen.value = false
    cancelling.value = null
  }
}
</script>

<template>
  <section aria-labelledby="conflicts-title" class="rounded-card border border-line p-4">
    <h3 id="conflicts-title" class="flex items-start gap-2 text-base font-medium">
      <TriangleAlert :size="18" :stroke-width="1.5" class="mt-0.5 shrink-0" aria-hidden="true" />
      <span>
        <template v-if="remaining > 0">
          Hay {{ remaining }} {{ remaining === 1 ? 'reserva' : 'reservas' }} en ese rango
        </template>
        <template v-else>Ya no quedan reservas por resolver en ese rango.</template>
      </span>
    </h3>
    <p v-if="remaining > 0" class="mt-1 text-sm text-fg-muted">
      Resuélvalas antes de guardar el bloqueo. Puede reprogramarlas o cancelarlas.
    </p>

    <div v-if="actionFailure" role="alert" class="mt-3 rounded-field bg-danger-surface p-4 text-sm">
      <p class="font-medium text-danger">No pudimos completar la acción.</p>
      <p class="mt-1 text-fg-muted">{{ actionFailure.detail }}</p>
    </div>

    <ul class="mt-3 flex flex-col" aria-label="Reservas por resolver">
      <li
        v-for="reservation in reservations"
        :key="reservation.id"
        class="border-b border-line py-3"
        :data-testid="`conflict-${reservation.id}`"
      >
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium text-fg tabular-nums">
              {{ when(reservation) }} · {{ reservation.customer }}
            </p>
            <p class="text-sm text-fg-muted">{{ reservation.service }}</p>
            <p
              v-if="reservation.temporary && !(reservation.id in resolved)"
              class="text-sm text-fg-muted"
            >
              Espera el pago: si no paga a tiempo se libera sola, o puede cancelarla.
            </p>
          </div>
          <p
            v-if="reservation.id in resolved"
            class="flex items-center gap-2 text-sm font-medium text-fg"
          >
            <Check :size="16" :stroke-width="1.5" aria-hidden="true" />
            {{ resolved[reservation.id] }}
          </p>
          <div v-else class="flex flex-wrap items-center gap-2">
            <BaseButton
              v-if="!reservation.temporary"
              variant="secondary"
              :disabled="loadingDetail || cancelBusy"
              :aria-label="`Reprogramar la reserva de ${reservation.customer} del ${when(reservation)}`"
              @click="startMove(reservation)"
              >Reprogramar</BaseButton
            >
            <BaseButton
              variant="secondary"
              :disabled="loadingDetail || cancelBusy"
              :aria-label="`Cancelar la reserva de ${reservation.customer} del ${when(reservation)}`"
              @click="askToCancel(reservation)"
              >Cancelar</BaseButton
            >
          </div>
        </div>

        <div v-if="moving === reservation.id" class="mt-3">
          <SkeletonBlock v-if="loadingDetail" shape="block" label="Cargando la reserva" />
          <ErrorState
            v-else-if="detailFailure"
            message="No pudimos cargar la reserva"
            description="Revise su conexión e inténtelo de nuevo."
            @retry="startMove(reservation)"
          />
          <RescheduleForm
            v-else-if="detail"
            compact
            deadline-note=""
            :shop-id="shopId"
            :reservation-id="reservation.id"
            :service-id="detail.servicio"
            :barber-id="detail.barbero"
            :barber-name="detail.barbero_nombre"
            :start="detail.inicio"
            :cancellation-hours="detail.plazo_cancelacion_horas"
            @done="onMoved"
            @back="moving = null"
          />
        </div>
      </li>
    </ul>
    <p v-if="hidden > 0" class="mt-2 text-sm text-fg-muted">
      y {{ hidden }} más. Al resolver estas, vuelva a guardar y verá las siguientes.
    </p>

    <ConfirmDialog
      v-model="cancelOpen"
      :title="`¿Cancelar la reserva de ${cancelling?.customer ?? ''}?`"
      description="Quedará como «cancelada por la barbería». El cliente recupera todo lo que pagó y recibe un aviso. Esta acción no se puede deshacer."
      cancel-label="No, mantenerla"
      confirm-label="Sí, cancelar reserva"
      :loading="cancelBusy"
      @confirm="cancel"
    />
  </section>
</template>
