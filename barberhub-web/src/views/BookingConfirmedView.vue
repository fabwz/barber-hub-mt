<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useTemplateRef } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Check, ChevronLeft } from 'lucide-vue-next'

import { api, isApiError, type components } from '@/api'
import BaseButton from '@/components/BaseButton.vue'
import BookingSummary from '@/components/BookingSummary.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import { colones, dateOf, timeOf } from '@/composables/format'
import { HEADER_EXAMPLE } from '@/composables/shopHeaderExample'
import { usePayment } from '@/composables/usePayment'
import { useBookingStore } from '@/stores/booking'
import { useShopStore } from '@/stores/shop'

type CustomerReservation = components['schemas']['ReservaDetalleDeCliente']
type LoadState = 'loading' | 'ready' | 'not-found' | 'error'

const route = useRoute()
const router = useRouter()
const shopStore = useShopStore()
const payments = usePayment()
const booking = useBookingStore()

const enlace = computed(() => String(route.params.enlace))
const reservationId = computed(() => String(route.params.reserva))

const state = ref<LoadState>('loading')
const reservation = ref<CustomerReservation | null>(null)
const title = useTemplateRef<HTMLElement>('title')

const shopId = computed(() => shopStore.shop?.id ?? '')
const shopName = computed(() => shopStore.shop?.profile.nombre ?? '')

/**
 * The deposit is what the approved PAYMENT charged, not `monto_a_pagar` (it is 0 once paid). The
 * contract has no way to list the payments of a reservation, so the payment is the one this browser
 * kept to pay it; opened from another browser there is no deposit to show.
 */
const deposit = ref<number | null>(null)

/**
 * `saldo_en_local` of a confirmed reservation is the whole price (the API quotes a reservation that
 * no longer waits for payment as owing nothing now), so the balance is what the price left after the
 * deposit the payment took.
 */
const balance = computed(() => {
  const detail = reservation.value
  if (detail === null || deposit.value === null) return null
  return Math.max(0, detail.precio - deposit.value)
})

const cancellationHours = computed(() => reservation.value?.plazo_cancelacion_horas ?? 0)
// «Ver mis reservas» has no screen yet: it is offered once the route exists.
const hasMyBookings = computed(() => router.hasRoute('my-bookings'))

const announcement = computed(() =>
  state.value === 'loading'
    ? 'Cargando su reserva.'
    : state.value === 'ready'
      ? 'Reserva confirmada. Revise los datos de su cita aquí.'
      : '',
)

/** The reservation is not confirmed (or its payment is not a clean approval): the payment page says what it is. */
function goToPayment(): void {
  void router.replace({
    name: 'payment',
    params: { enlace: enlace.value, reserva: reservationId.value },
  })
}

async function load(): Promise<void> {
  state.value = 'loading'
  await shopStore.load(enlace.value)
  if (shopStore.status === 'not-found') {
    state.value = 'not-found'
    return
  }
  if (shopStore.status !== 'ready') {
    state.value = 'error'
    return
  }
  try {
    const { data } = await api.GET('/barberias/{barberia}/reservas/{reserva}', {
      params: { path: { barberia: shopId.value, reserva: reservationId.value } },
    })
    const detail = data!.data
    // The view of the shop's agenda is not a customer's reservation.
    if (!('monto_a_pagar' in detail)) {
      state.value = 'not-found'
      return
    }
    if (detail.estado !== 'confirmada') {
      goToPayment()
      return
    }
    reservation.value = detail
    if (!(await readPayment())) return
    // The shop page keeps this reservation as «temporary, payment in progress»: it is confirmed now.
    if (booking.reservation?.reservation.id === reservationId.value) booking.clear()
    state.value = 'ready'
    await nextTick()
    title.value?.focus()
  } catch (error) {
    state.value = isApiError(error) && error.status === 404 ? 'not-found' : 'error'
  }
}

/**
 * Reads the payment kept for this reservation. Returns false when it is not an approval to celebrate
 * (the page then goes to the payment screen). Without it, or if the provider does not answer, the
 * confirmation is still true (the reservation says so) and just shows no deposit.
 */
async function readPayment(): Promise<boolean> {
  if (payments.restore(enlace.value, reservationId.value) === null) return true
  try {
    const paid = await payments.refresh(shopId.value)
    if (paid === null) return true
    if (paid.estado !== 'aprobado' || paid.devolucion_pendiente) {
      goToPayment()
      return false
    }
    deposit.value = paid.monto
  } catch {
    // The reservation is confirmed; only the deposit stays unknown.
  }
  return true
}

onMounted(() => void load())
</script>

<template>
  <div class="mx-auto flex min-h-screen w-full max-w-7xl flex-col">
    <!-- Phone: a light header with the way back and the name of the shop -->
    <header class="flex flex-none items-center gap-2 px-5 pt-4 pb-2 md:hidden">
      <RouterLink
        :to="{ name: 'shop', params: { enlace } }"
        class="-ml-3 inline-flex min-h-11 items-center gap-1 rounded-pill pr-4 pl-2.5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-surface"
      >
        <ChevronLeft :size="18" :stroke-width="1.5" aria-hidden="true" />
        Volver
      </RouterLink>
      <span class="ml-auto text-sm font-medium">{{ shopName }}</span>
    </header>

    <!-- Desktop: the same header of the shop as the payment page -->
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
      <div class="md:rounded-card md:border md:border-line md:p-8">
        <!-- Loading -->
        <div v-if="state === 'loading'" class="flex flex-col gap-6">
          <h1 class="text-2xl">Reserva confirmada</h1>
          <SkeletonBlock shape="block" label="Cargando su reserva" />
          <SkeletonBlock shape="row" :count="2" label="Cargando el pago" />
        </div>

        <!-- Empty: there is no such reservation -->
        <template v-else-if="state === 'not-found'">
          <h1 class="text-2xl">Reserva confirmada</h1>
          <div class="mt-6 border-y border-line">
            <EmptyState
              title="No encontramos esta reserva"
              description="Puede que el enlace esté incompleto. Vuelva a la barbería para reservar una cita."
            >
              <template #action>
                <RouterLink
                  :to="{ name: 'shop', params: { enlace } }"
                  class="inline-flex min-h-11 items-center justify-center rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover"
                >
                  Volver a la barbería
                </RouterLink>
              </template>
            </EmptyState>
          </div>
        </template>

        <!-- Error -->
        <div v-else-if="state === 'error'" class="rounded-card bg-danger-surface">
          <ErrorState message="Error: no pudimos cargar su reserva." @retry="load" />
        </div>

        <!-- Confirmed -->
        <div v-else-if="reservation" class="flex flex-col gap-6 md:grid md:grid-cols-2 md:gap-8">
          <div class="flex items-start gap-3 md:col-span-2 md:border-b md:border-line md:pb-6">
            <Check
              :size="28"
              :stroke-width="1.5"
              class="mt-0.5 flex-none text-accent"
              aria-hidden="true"
            />
            <div>
              <h1 ref="title" tabindex="-1" class="text-2xl outline-offset-4">
                Reserva confirmada
              </h1>
              <p class="mt-2 text-fg-muted">
                <template v-if="deposit !== null">
                  Su pago de <span class="tabular-nums">{{ colones(deposit) }}</span> fue aprobado y
                  el espacio es suyo.
                </template>
                Revise los datos de su cita aquí.
              </p>
            </div>
          </div>

          <!-- Left on desktop: the appointment -->
          <section aria-labelledby="appointment-title" class="flex min-w-0 flex-col gap-4">
            <h2 id="appointment-title" class="text-lg font-medium">Su cita</h2>
            <BookingSummary
              :service-name="reservation.servicio_nombre"
              :barber-name="reservation.barbero_nombre"
              :date="dateOf(reservation.inicio)"
              :start-time="timeOf(reservation.inicio)"
              :end-time="timeOf(reservation.fin)"
              :price="reservation.precio"
              :duration-minutes="reservation.duracion_minutos"
            />
          </section>

          <!-- Right on desktop: what was paid and what is left -->
          <section
            aria-labelledby="payment-title"
            class="flex min-w-0 flex-col gap-4 md:border-l md:border-line md:pl-8"
          >
            <h2 id="payment-title" class="text-lg font-medium">Pago</h2>
            <dl
              v-if="deposit !== null && balance !== null"
              class="flex flex-col gap-2 rounded-card border border-line bg-surface p-4 text-base"
            >
              <div class="flex justify-between gap-6">
                <dt class="text-fg-muted">Depósito pagado</dt>
                <dd class="text-right tabular-nums">{{ colones(deposit) }}</dd>
              </div>
              <div class="flex justify-between gap-6">
                <dt class="font-medium">Saldo en el local</dt>
                <dd class="text-right text-lg tabular-nums">{{ colones(balance) }}</dd>
              </div>
            </dl>
            <p class="max-w-110 text-sm text-fg-subtle">
              <template v-if="deposit !== null">El saldo se paga en el local. </template>
              <template v-if="cancellationHours > 0">
                Puede cancelar o cambiar la hora sin costo hasta {{ cancellationHours }}
                {{ cancellationHours === 1 ? 'hora' : 'horas' }} antes de la cita. Cada barbería
                define este plazo.
              </template>
            </p>
          </section>

          <div
            class="flex flex-col gap-3 border-t border-line pt-6 md:col-span-2 md:flex-row md:items-center md:justify-end md:gap-4"
          >
            <RouterLink
              :to="{ name: 'shop', params: { enlace } }"
              class="inline-flex min-h-12 items-center justify-center rounded-pill border border-line-strong px-5 text-base font-normal text-fg no-underline transition-colors duration-150 ease-out hover:border-line-hover md:w-70"
            >
              Volver a la barbería
            </RouterLink>
            <BaseButton
              v-if="hasMyBookings"
              size="large"
              class="w-full text-base! md:w-70"
              @click="router.push({ name: 'my-bookings' })"
            >
              Ver mis reservas
            </BaseButton>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>
