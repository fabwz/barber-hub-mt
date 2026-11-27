<script setup lang="ts">
import { computed, inject, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

import { api, isApiError, type ApiError } from '@/api'
import { networkError } from '@/api/errors'
import BackLink from '@/components/BackLink.vue'
import ErrorState from '@/components/ErrorState.vue'
import ReservationActions from '@/components/ReservationActions.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import StatusLabel from '@/components/StatusLabel.vue'
import { agendaContextKey } from '@/composables/agendaContext'
import { colones, dateOf, formatDateWithYear, timeOf } from '@/composables/format'
import { useAgendaPermissions } from '@/composables/useAgendaPermissions'
import { statusKeyOf } from '@/composables/useMyBookings'
import { useActiveShopStore } from '@/stores/activeShop'

interface HistoryEntry {
  de: string | null
  a: string
  autor: string
  fecha: string
}

/** The detail of the agenda. `historial` is the owner's: the collaborator's answer has none at all. */
interface Detail {
  id: string
  servicio: string
  servicio_nombre: string
  barbero: string
  barbero_nombre: string
  estado: string
  inicio: string
  fin: string
  precio: number
  duracion_minutos: number
  cliente_nombre: string
  /** Registered by the owner for a person without an account (RF-12). */
  asistida?: boolean
  /** Phone of the assisted client; only the owner gets it (CO-04). */
  cliente_telefono?: string | null
  plazo_cancelacion_horas: number
  saldo_pendiente: number | null
  historial?: HistoryEntry[]
}

const route = useRoute()
const shops = useActiveShopStore()
const agenda = inject(agendaContextKey, null)

const shopId = computed(() => shops.active?.id ?? '')
const reservationId = computed(() => String(route.params.reserva ?? ''))
const permissions = useAgendaPermissions(() => shops.active?.role ?? 'colaborador')

const detail = ref<Detail | null>(null)
const failure = ref<ApiError | null>(null)
const loading = ref(true)
let call = 0

/** `quiet` keeps what is shown while it reads again, so a message about an action is not lost. */
async function load(quiet = false): Promise<void> {
  const current = ++call
  if (!quiet) {
    loading.value = true
    detail.value = null
  }
  failure.value = null
  try {
    const { data } = await api.GET('/barberias/{barberia}/reservas/{reserva}', {
      params: { path: { barberia: shopId.value, reserva: reservationId.value } },
    })
    if (current !== call) return
    detail.value = data!.data as unknown as Detail
  } catch (caught) {
    if (current !== call) return
    failure.value = isApiError(caught) ? caught : networkError(caught)
  } finally {
    if (current === call) loading.value = false
  }
}

watch([shopId, reservationId], () => void load(), { immediate: true })

// The live agenda: when the event is about this reservation, or the connection came back, it is read again.
watch(
  () => agenda?.changes.value,
  (changes) => {
    if (!changes) return
    if (changes.ids === 'all' || changes.ids.includes(reservationId.value)) void load(true)
  },
)

async function onChanged(): Promise<void> {
  await Promise.all([load(true), agenda?.reload()])
}

const notFound = computed(() => failure.value?.status === 404)

const backTo = computed(() => ({
  name: 'staff-agenda',
  params: { barberia: String(route.params.barberia ?? '') },
  query: route.query,
}))

const when = computed(() =>
  detail.value === null
    ? ''
    : `${formatDateWithYear(dateOf(detail.value.inicio))}, ${timeOf(detail.value.inicio)}–${timeOf(detail.value.fin)}`,
)

/** Newest first, as the design shows it; a change from a status to itself is a rescheduling. */
const history = computed(() => [...(detail.value?.historial ?? [])].reverse())

function historyDate(moment: string): string {
  return `${formatDateWithYear(dateOf(moment))}, ${timeOf(moment)}`
}
</script>

<template>
  <section
    aria-labelledby="panel-title"
    :aria-busy="loading"
    class="flex flex-col gap-6 px-5 py-6 md:px-6"
  >
    <div>
      <!-- Phone: the panel is the whole screen, so the way out says where it leads. -->
      <BackLink :to="backTo" class="-ml-2.5">
        <template #default>Volver a la agenda</template>
      </BackLink>
    </div>

    <SkeletonBlock v-if="loading" shape="row" :count="4" label="Cargando el detalle" />

    <ErrorState
      v-else-if="failure"
      :message="notFound ? 'No encontramos la reserva.' : 'No pudimos cargar la reserva.'"
      :description="
        notFound
          ? 'Puede que ya no exista o que no sea de su agenda.'
          : 'Revise su conexión e intente de nuevo.'
      "
      :retry-label="notFound ? 'Volver a la agenda' : 'Reintentar'"
      @retry="notFound ? $router.push(backTo) : load()"
    />

    <template v-else-if="detail">
      <header>
        <h2 id="panel-title" class="text-2xl">{{ detail.servicio_nombre }}</h2>
        <div class="mt-1">
          <StatusLabel :status="statusKeyOf(detail.estado)" />
        </div>
      </header>

      <section aria-labelledby="panel-booking">
        <h3 id="panel-booking" class="mb-2 font-medium">Reserva</h3>
        <dl class="divide-y divide-line border-y border-line text-sm">
          <div class="flex justify-between gap-4 py-3">
            <dt class="text-fg-muted">Servicio</dt>
            <dd class="text-right">
              {{ detail.servicio_nombre }} · {{ detail.duracion_minutos }} min
            </dd>
          </div>
          <div class="flex justify-between gap-4 py-3">
            <dt class="text-fg-muted">Barbero</dt>
            <dd class="text-right">{{ detail.barbero_nombre }}</dd>
          </div>
          <div class="flex justify-between gap-4 py-3">
            <dt class="text-fg-muted">Fecha y hora</dt>
            <dd class="text-right tabular-nums">{{ when }}</dd>
          </div>
          <div class="flex justify-between gap-4 py-3">
            <dt class="text-fg-muted">Cliente</dt>
            <dd class="text-right">
              {{ detail.cliente_nombre }}
              <span v-if="detail.asistida" class="block text-fg-muted">Reserva asistida</span>
            </dd>
          </div>
          <div v-if="detail.cliente_telefono" class="flex justify-between gap-4 py-3">
            <dt class="text-fg-muted">Teléfono</dt>
            <dd class="text-right tabular-nums">{{ detail.cliente_telefono }}</dd>
          </div>
        </dl>
      </section>

      <!-- The payment, the deposit and its state are not here: the API does not deliver them in the detail yet. -->
      <section aria-labelledby="panel-charge">
        <h3 id="panel-charge" class="mb-2 font-medium">Cobro</h3>
        <dl class="divide-y divide-line border-y border-line text-sm">
          <div v-if="permissions.canSeeTotal" class="flex justify-between gap-4 py-3">
            <dt class="text-fg-muted">Total <span class="text-fg-subtle">(IVA incluido)</span></dt>
            <dd class="text-right tabular-nums">{{ colones(detail.precio) }}</dd>
          </div>
          <div v-if="detail.saldo_pendiente !== null" class="flex justify-between gap-4 py-3">
            <dt class="text-fg-muted">Saldo pendiente</dt>
            <dd class="text-right tabular-nums">{{ colones(detail.saldo_pendiente) }}</dd>
          </div>
        </dl>
      </section>

      <section v-if="permissions.canSeeHistory" aria-labelledby="panel-history">
        <h3 id="panel-history" class="mb-2 font-medium">Historial de estados</h3>
        <ul class="divide-y divide-line border-y border-line text-sm">
          <li v-for="(entry, index) in history" :key="index" class="py-3">
            <p v-if="entry.de === entry.a" class="font-medium">Reprogramada</p>
            <StatusLabel v-else :status="statusKeyOf(entry.a)" />
            <p class="mt-0.5 text-fg-muted tabular-nums">
              {{ historyDate(entry.fecha) }} · {{ entry.autor }}
            </p>
          </li>
        </ul>
      </section>

      <ReservationActions
        :shop-id="shopId"
        :permissions="permissions"
        :reservation="detail"
        @changed="onChanged"
      />
    </template>
  </section>
</template>
