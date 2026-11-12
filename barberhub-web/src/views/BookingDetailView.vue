<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useTemplateRef } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ChevronLeft } from 'lucide-vue-next'

import { api, isApiError, type ApiError, type components } from '@/api'
import { networkError } from '@/api/errors'
import BaseButton from '@/components/BaseButton.vue'
import BookingSummary from '@/components/BookingSummary.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import RescheduleForm, { type RescheduledBooking } from '@/components/RescheduleForm.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import StatusLabel from '@/components/StatusLabel.vue'
import { colones, dateOf, formatShortDate, timeOf } from '@/composables/format'
import { HEADER_EXAMPLE } from '@/composables/shopHeaderExample'
import { statusKeyOf } from '@/composables/useMyBookings'
import { useShopStore } from '@/stores/shop'

type CustomerReservation = components['schemas']['ReservaDetalleDeCliente']
type LoadState = 'loading' | 'ready' | 'not-found' | 'error'
type Mode = 'view' | 'reschedule' | 'cancel'

const route = useRoute()
const router = useRouter()
const shopStore = useShopStore()

const enlace = computed(() => String(route.params.enlace))
const reservationId = computed(() => String(route.params.reserva))
const shopName = computed(() => shopStore.shop?.profile.nombre ?? '')

const state = ref<LoadState>('loading')
const mode = ref<Mode>('view')
const reservation = ref<CustomerReservation | null>(null)
/** What happened after an action: shown in view mode, announced politely. */
const notice = ref<string | null>(null)
const cancelling = ref(false)
const cancelFailure = ref<ApiError | null>(null)

const title = useTemplateRef<HTMLElement>('title')
const cancelTitle = useTemplateRef<HTMLElement>('cancelTitle')

/** Only a confirmed reservation that has not started offers to change or cancel it (RE-04, CA-01). */
const canChange = computed(() => {
  const current = reservation.value
  return (
    current !== null && current.estado === 'confirmada' && Date.parse(current.inicio) > Date.now()
  )
})
const canPay = computed(() => reservation.value?.estado === 'temporal')

/** The free window the API stores with the reservation, as hours before the appointment. */
const deadline = computed(() => {
  const hours = reservation.value?.plazo_cancelacion_horas
  if (hours === undefined) return ''
  return hours <= 0
    ? 'hasta el inicio de la cita'
    : `hasta ${hours} ${hours === 1 ? 'hora' : 'horas'} antes de la cita`
})

const announcement = computed(() => {
  if (state.value === 'loading') return 'Cargando su reserva.'
  if (state.value === 'error') return 'No pudimos cargar su reserva.'
  return ''
})

async function focus(target: 'title' | 'cancel'): Promise<void> {
  await nextTick()
  ;(target === 'title' ? title.value : cancelTitle.value)?.focus()
}

async function load(): Promise<void> {
  state.value = 'loading'
  mode.value = 'view'
  notice.value = null
  await shopStore.load(enlace.value)
  if (shopStore.status === 'not-found') {
    state.value = 'not-found'
  } else if (shopStore.status !== 'ready' || shopStore.shop === null) {
    state.value = 'error'
  } else {
    try {
      const { data } = await api.GET('/barberias/{barberia}/reservas/{reserva}', {
        params: { path: { barberia: shopStore.shop.id, reserva: reservationId.value } },
      })
      const detail = data!.data
      // The view of the shop's agenda is not a reservation of the customer.
      if ('monto_a_pagar' in detail) {
        reservation.value = detail
        state.value = 'ready'
      } else {
        state.value = 'not-found'
      }
    } catch (error) {
      state.value = isApiError(error) && error.status === 404 ? 'not-found' : 'error'
    }
  }
  await focus('title')
}

/**
 * Back to the list. When the list is the entry right behind, go back to it instead of adding another
 * one: pushing it again would make «Volver» of the list return here, in a loop.
 */
function backToList(): void {
  const list = router.resolve({ name: 'my-bookings', params: { enlace: enlace.value } }).path
  if (router.options.history.state.back === list) router.back()
  else void router.push(list)
}

function startReschedule(): void {
  notice.value = null
  mode.value = 'reschedule'
}

function startCancel(): void {
  notice.value = null
  cancelFailure.value = null
  mode.value = 'cancel'
  void focus('cancel')
}

function backToView(): void {
  mode.value = 'view'
  void focus('title')
}

function onRescheduled(moved: RescheduledBooking): void {
  if (reservation.value !== null) {
    reservation.value = { ...reservation.value, inicio: moved.inicio, fin: moved.fin }
    notice.value = `Su cita quedó para el ${formatShortDate(dateOf(moved.inicio))}, a las ${timeOf(moved.inicio)}.`
  }
  backToView()
}

/** What the API classified, in plain words and with no promise about money (it applies none yet). */
function classificationText(classification: string): string {
  if (classification === 'a_tiempo') return 'Cancelación a tiempo: la hizo dentro del plazo.'
  if (classification === 'tardia') return 'Cancelación tardía: la hizo después del plazo.'
  if (classification === 'por_la_barberia') return 'La canceló la barbería.'
  return ''
}

async function cancel(): Promise<void> {
  // A second click while the request is on its way must not send another one.
  if (cancelling.value || reservation.value === null || shopStore.shop === null) return
  cancelling.value = true
  cancelFailure.value = null
  try {
    const { data } = await api.POST('/barberias/{barberia}/reservas/{reserva}/cancelacion', {
      params: { path: { barberia: shopStore.shop.id, reserva: reservationId.value } },
    })
    const cancelled = data!.data
    reservation.value = { ...reservation.value, estado: cancelled.estado }
    notice.value = ['Su reserva quedó cancelada.', classificationText(cancelled.clasificacion)]
      .filter((part) => part !== '')
      .join(' ')
    backToView()
  } catch (caught) {
    cancelFailure.value = isApiError(caught) ? caught : networkError(caught)
  } finally {
    cancelling.value = false
  }
}

onMounted(() => void load())
</script>

<template>
  <div class="mx-auto flex min-h-screen w-full max-w-7xl flex-col">
    <!-- Phone: a light header with the way back and the name of the shop -->
    <header class="flex flex-none items-center gap-2 px-5 pt-4 pb-2 md:hidden">
      <button
        type="button"
        class="-ml-3 inline-flex min-h-11 cursor-pointer items-center gap-1 rounded-pill pr-4 pl-2.5 text-sm font-medium text-fg transition-colors duration-150 ease-out hover:bg-surface"
        @click="backToList"
      >
        <ChevronLeft :size="18" :stroke-width="1.5" aria-hidden="true" />
        Mis reservas
      </button>
      <span class="ml-auto text-sm font-medium">{{ shopName }}</span>
    </header>

    <!-- Desktop: the same header of the shop as the other booking screens -->
    <div v-if="shopStore.shop" class="hidden border-b border-line px-8 md:block">
      <ShopHeader
        class="border-b-0!"
        wide
        name-tag="p"
        v-bind="HEADER_EXAMPLE"
        :name="shopStore.shop.profile.nombre"
        :city="shopStore.shop.profile.ciudad"
        :phone="shopStore.shop.profile.telefono"
      />
    </div>

    <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>

    <main
      class="flex flex-1 flex-col px-5 pt-4 pb-8 md:px-8 md:pt-8"
      :aria-busy="state === 'loading' ? 'true' : undefined"
    >
      <!-- Desktop: the way back, above the card -->
      <button
        type="button"
        class="mb-4 hidden min-h-11 cursor-pointer items-center gap-1 self-start rounded-pill pr-4 pl-2.5 text-sm font-medium text-fg transition-colors duration-150 ease-out hover:bg-surface md:inline-flex"
        @click="backToList"
      >
        <ChevronLeft :size="18" :stroke-width="1.5" aria-hidden="true" />
        Mis reservas
      </button>

      <div class="md:rounded-card md:border md:border-line md:p-8">
        <!-- Loading -->
        <div v-if="state === 'loading'" class="flex flex-col gap-6 md:grid md:grid-cols-2 md:gap-8">
          <h1
            ref="title"
            tabindex="-1"
            class="text-2xl outline-offset-4 md:col-span-2 md:border-b md:border-line md:pb-6"
          >
            Su reserva
          </h1>
          <div class="flex flex-col gap-6">
            <SkeletonBlock shape="block" label="Cargando su reserva" />
          </div>
          <div class="flex flex-col gap-6 md:border-l md:border-line md:pl-8">
            <SkeletonBlock shape="row" :count="2" label="Cargando el pago" />
          </div>
        </div>

        <!-- Empty: there is no such reservation -->
        <template v-else-if="state === 'not-found'">
          <h1 ref="title" tabindex="-1" class="text-2xl outline-offset-4">Su reserva</h1>
          <div class="mt-6 border-y border-line">
            <EmptyState
              title="No encontramos esta reserva"
              description="Puede que el enlace esté incompleto o que la reserva no sea suya."
            >
              <template #action>
                <RouterLink
                  :to="{ name: 'my-bookings', params: { enlace } }"
                  class="inline-flex min-h-11 items-center justify-center rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover"
                >
                  Ver mis reservas
                </RouterLink>
              </template>
            </EmptyState>
          </div>
        </template>

        <!-- Error -->
        <template v-else-if="state === 'error'">
          <h1 ref="title" tabindex="-1" class="text-2xl outline-offset-4">Su reserva</h1>
          <div class="mt-6 rounded-card bg-danger-surface">
            <ErrorState message="No pudimos cargar su reserva." @retry="load" />
          </div>
        </template>

        <!-- Reschedule -->
        <RescheduleForm
          v-else-if="reservation && mode === 'reschedule' && shopStore.shop"
          :shop-id="shopStore.shop.id"
          :reservation-id="reservation.id"
          :service-id="reservation.servicio"
          :barber-id="reservation.barbero"
          :barber-name="reservation.barbero_nombre"
          :start="reservation.inicio"
          :cancellation-hours="reservation.plazo_cancelacion_horas"
          @done="onRescheduled"
          @back="backToView"
        />

        <!-- The reservation -->
        <!-- Desktop: the appointment on the left, changes and cancellation on the right -->
        <div v-else-if="reservation" class="flex flex-col gap-6 md:grid md:grid-cols-2 md:gap-8">
          <div class="flex flex-col gap-1 md:col-span-2 md:border-b md:border-line md:pb-6">
            <h1 ref="title" tabindex="-1" class="w-fit text-2xl outline-offset-4">Su reserva</h1>
            <StatusLabel :status="statusKeyOf(reservation.estado)" plain />
          </div>

          <NoticeBanner v-if="notice" class="md:col-span-2">{{ notice }}</NoticeBanner>

          <section aria-labelledby="appointment-title" class="flex min-w-0 flex-col gap-6">
            <h2 id="appointment-title" class="text-lg font-medium max-md:sr-only">Su cita</h2>
            <BookingSummary
              :service-name="reservation.servicio_nombre"
              :barber-name="reservation.barbero_nombre"
              :date="dateOf(reservation.inicio)"
              :start-time="timeOf(reservation.inicio)"
              :end-time="timeOf(reservation.fin)"
              :price="reservation.precio"
              :duration-minutes="reservation.duracion_minutos"
            />

            <!-- Only a temporary reservation has a figure due now -->
            <dl
              v-if="canPay"
              class="flex flex-col gap-2 rounded-card border border-line bg-surface p-4 text-base"
            >
              <div class="flex justify-between gap-6">
                <dt class="text-fg-muted">A pagar ahora</dt>
                <dd class="text-right tabular-nums">{{ colones(reservation.monto_a_pagar) }}</dd>
              </div>
              <div class="flex justify-between gap-6">
                <dt class="font-medium">Saldo en el local</dt>
                <dd class="text-right text-lg tabular-nums">
                  {{ colones(reservation.saldo_en_local) }}
                </dd>
              </div>
            </dl>
          </section>

          <section
            aria-labelledby="changes-title"
            class="flex min-w-0 flex-col gap-6 md:border-l md:border-line md:pl-8"
          >
            <h2 id="changes-title" class="text-lg font-medium max-md:sr-only">
              Cambios y cancelación
            </h2>

            <p v-if="!canPay && !canChange" class="text-fg-muted">
              Esta reserva ya no admite cambios ni cancelación.
            </p>

            <RouterLink
              v-if="canPay"
              :to="{ name: 'payment', params: { enlace, reserva: reservation.id } }"
              class="inline-flex min-h-12 w-full items-center justify-center rounded-pill border border-line-strong bg-elevated px-5 text-base font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover md:w-70"
            >
              Continuar al pago
            </RouterLink>

            <!-- Confirmed and still to come: change or cancel -->
            <template v-if="canChange && mode === 'view'">
              <p class="text-sm text-fg-subtle md:max-w-110 md:text-base md:text-fg-muted">
                Puede cancelar o cambiar la hora sin costo {{ deadline }}. Cada barbería define este
                plazo.
              </p>
              <div class="grid grid-cols-2 gap-3 md:flex">
                <BaseButton
                  variant="secondary"
                  size="large"
                  class="md:w-50"
                  @click="startReschedule"
                >
                  Reprogramar
                </BaseButton>
                <BaseButton variant="secondary" size="large" class="md:w-50" @click="startCancel">
                  Cancelar
                </BaseButton>
              </div>
            </template>

            <!-- Inline confirmation: not a modal -->
            <div
              v-if="canChange && mode === 'cancel'"
              role="group"
              aria-labelledby="cancel-title"
              class="flex flex-col gap-4 border-y border-line py-4"
            >
              <div>
                <h3
                  id="cancel-title"
                  ref="cancelTitle"
                  tabindex="-1"
                  class="text-lg font-medium outline-offset-4"
                >
                  ¿Cancelar esta reserva?
                </h3>
                <p class="mt-1 text-sm text-fg-muted">
                  Puede cancelar sin penalización {{ deadline }}.
                </p>
              </div>
              <NoticeBanner v-if="cancelFailure" variant="error">
                <p class="font-medium">{{ cancelFailure.title }}</p>
                <p class="mt-1 text-fg-muted">{{ cancelFailure.detail }}</p>
              </NoticeBanner>
              <div class="flex flex-col gap-3 md:flex-row">
                <BaseButton
                  size="large"
                  class="w-full md:w-50"
                  :disabled="cancelling"
                  @click="backToView"
                >
                  Mantener reserva
                </BaseButton>
                <BaseButton
                  variant="secondary"
                  size="large"
                  class="w-full md:w-50"
                  :loading="cancelling"
                  @click="cancel"
                >
                  Sí, cancelar reserva
                </BaseButton>
              </div>
            </div>
          </section>
        </div>
      </div>
    </main>
  </div>
</template>
