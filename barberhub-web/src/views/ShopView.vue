<script setup lang="ts">
import { computed, onBeforeUnmount, useTemplateRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CalendarDays } from 'lucide-vue-next'

import BookingPanel, {
  type PanelFailure,
  type PanelReservation,
  type PanelSelection,
} from '@/components/BookingPanel.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import OptionCard from '@/components/OptionCard.vue'
import OptionGroup from '@/components/OptionGroup.vue'
import ServiceRow from '@/components/ServiceRow.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import type { TimeSlot } from '@/components/TimeSlots.vue'
import { addDays, dateOf, timeOf, todayInCostaRica } from '@/composables/format'
import { useAvailability } from '@/composables/useAvailability'
import { HEADER_EXAMPLE } from '@/composables/shopHeaderExample'
import { readStoredPayment } from '@/composables/usePayment'
import { useBookingStore } from '@/stores/booking'
import { useSessionStore } from '@/stores/session'
import { useShopStore } from '@/stores/shop'

/** Days offered by the date picker: the booking window of DI-03. */
const WINDOW_DAYS = 30

const route = useRoute()
const router = useRouter()
const store = useShopStore()
const booking = useBookingStore()
const session = useSessionStore()

const link = computed(() => String(route.params.enlace))

// Reload when the route parameter changes; the store skips what it already has.
watch(link, (value) => void store.load(value), { immediate: true })

const previousTitle = document.title
watch(
  () => (store.status === 'ready' && store.shop ? store.shop.profile.nombre : null),
  (name) => {
    document.title = name ? `${name} · BarberHub` : previousTitle
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  document.title = previousTitle
})

// Today in Costa Rica, never in the browser zone.
const today = todayInCostaRica()

// A new shop starts a clean selection; the same one restores what was stored (it survives the login).
watch(
  () => (store.status === 'ready' ? store.shop : null),
  (shop) => {
    if (!shop) return
    booking.enter(shop.id)
    if (booking.serviceId && !shop.services.some((item) => item.id === booking.serviceId)) {
      booking.clear()
    }
  },
  { immediate: true },
)

// The date defaults to today and never stays in the past.
watch(
  () => booking.serviceId,
  (id) => {
    if (id && (!booking.date || booking.date < today)) booking.selectDate(today)
  },
  { immediate: true },
)

const availability = useAvailability({
  shopId: () => (store.status === 'ready' ? (store.shop?.id ?? null) : null),
  serviceId: () => booking.serviceId,
  barberId: () => booking.barberId,
  date: () => (booking.serviceId ? booking.date : null),
})

// A restored space is checked once against what the API lists now.
watch(
  () => availability.loaded.value,
  (loaded) => {
    if (loaded) {
      booking.revalidate(
        availability.allSlots.value.map((item) => ({ start: item.start, barberId: item.barberId })),
      )
    }
  },
)

// Coming back from «Cambiar reserva», the focus goes to the title of the screen.
const panel = useTemplateRef<InstanceType<typeof BookingPanel>>('panel')
watch(
  () => (booking.returned ? panel.value : null),
  (current) => {
    if (current === null) return
    current.focusTitle()
    booking.acknowledgeReturn()
  },
  { immediate: true, flush: 'post' },
)

const service = computed(
  () => store.shop?.services.find((item) => item.id === booking.serviceId) ?? null,
)

const days = computed(() =>
  Array.from({ length: WINDOW_DAYS }, (_, index) => {
    const value = addDays(today, index)
    return { value, closed: availability.days.value[value] === 'closed' }
  }),
)

const slots = computed<TimeSlot[]>(() =>
  availability.slots.value.map((item) => ({
    value: item.start,
    time: timeOf(item.start),
    barberName: item.barberName,
  })),
)

const hoursState = computed(() => {
  if (availability.error.value) return 'error'
  if (availability.loaded.value) return slots.value.length === 0 ? 'empty' : 'ready'
  return 'loading'
})

const nextDays = computed(() => availability.nextDays.value.map((item) => item.date))

const barberModel = computed({
  get: () => booking.barberId ?? '',
  set: (value: string) => booking.selectBarber(value === '' ? null : value),
})
const dateModel = computed({
  get: () => booking.date,
  set: (value: string | null) => {
    if (value) booking.selectDate(value)
  },
})
const slotModel = computed({
  get: () => booking.slot?.start ?? null,
  set: (value: string | null) => {
    const found = availability.slots.value.find((item) => item.start === value)
    if (found) booking.selectSlot({ start: found.start, barberId: found.barberId })
  },
})

function barberNameOf(id: string): string {
  return availability.barbers.value.find((item) => item.id === id)?.name ?? ''
}

const selection = computed<PanelSelection | null>(() => {
  const chosen = booking.slot
  if (!chosen || !service.value) return null
  const match = availability.allSlots.value.find(
    (item) => item.start === chosen.start && item.barberId === chosen.barberId,
  )
  return {
    serviceName: service.value.nombre,
    barberName: match?.barberName || barberNameOf(chosen.barberId),
    date: dateOf(chosen.start),
    startTime: timeOf(chosen.start),
    endTime: match ? timeOf(match.end) : undefined,
    price: service.value.precio,
  }
})

const reservation = computed<PanelReservation | null>(() => {
  const created = booking.reservation
  if (!created) return null
  const { reservation: data } = created
  return {
    serviceName: created.serviceName,
    barberName: created.barberName,
    date: dateOf(data.inicio),
    startTime: timeOf(data.inicio),
    endTime: timeOf(data.fin),
    price: data.precio,
    durationMinutes: data.duracion_minutos,
    status: data.estado === 'confirmada' ? 'confirmed' : 'temporary',
    expiresAt: data.expira_en,
  }
})

const failure = computed<PanelFailure | null>(() => {
  const error = booking.error
  if (!error) return null
  if (booking.alternatives.length > 0) {
    return {
      title: 'Ese espacio ya no está disponible.',
      description: 'Elija uno de los espacios cercanos o cambie la hora.',
    }
  }
  return { title: 'Error: no pudimos crear la reserva.', description: error.detail }
})

const alternatives = computed<TimeSlot[]>(() =>
  booking.alternatives.map((item) => ({ value: item.start, time: timeOf(item.start) })),
)

function onService(id: string | null): void {
  if (id) booking.selectService(id)
}

function pickAlternative(value: string): void {
  const found = booking.alternatives.find((item) => item.start === value)
  if (found) booking.pickAlternative(found)
}

function goToLogin() {
  return router.push({ name: 'login', query: { redirect: route.fullPath } })
}

/** A payment of the reservation was started: cancelling it would leave a charge without reservation. */
const paymentInProgress = computed(() => {
  const created = booking.reservation
  return created !== null && readStoredPayment()?.reserva === created.reservation.id
})

function goToPayment(): void {
  const created = booking.reservation
  if (!created) return
  void router.push({
    name: 'payment',
    params: { enlace: String(route.params.enlace), reserva: created.reservation.id },
  })
}

async function reserve(): Promise<void> {
  if (!session.isAuthenticated) {
    // The selection is already in sessionStorage: it is still here after the login.
    await goToLogin()
    return
  }
  if (!service.value || !selection.value) return
  await booking.reserve({
    serviceName: service.value.nombre,
    barberName: selection.value.barberName,
  })
  if (booking.error?.status === 401) await goToLogin()
}
</script>

<template>
  <main :aria-busy="store.status === 'loading'" class="mx-auto w-full max-w-7xl">
    <div v-if="store.status === 'loading' || store.status === 'idle'" class="px-5 md:px-8">
      <span class="sr-only">Cargando</span>
      <!-- Skeleton with the shape of the header -->
      <div class="border-b border-line" aria-hidden="true">
        <div
          class="aspect-2/1 w-full animate-pulse bg-line motion-reduce:animate-none md:aspect-3/1"
        />
        <div class="flex flex-col gap-3 pb-4">
          <div
            class="-mt-9 size-18 animate-pulse rounded-full bg-line motion-reduce:animate-none md:-mt-11 md:size-22"
          />
          <div class="h-9 w-2/3 animate-pulse rounded-field bg-line motion-reduce:animate-none" />
          <div class="h-4 w-1/2 animate-pulse rounded-field bg-line motion-reduce:animate-none" />
        </div>
      </div>
      <div class="max-w-180 py-5 md:py-8" aria-hidden="true">
        <SkeletonBlock shape="row" :count="4" />
      </div>
    </div>

    <template v-else-if="store.status === 'ready' && store.shop">
      <!-- The line under the header spans the whole 1280 px container, as in the design. -->
      <div class="border-b border-line px-5 md:px-8">
        <ShopHeader
          class="border-b-0!"
          wide
          v-bind="HEADER_EXAMPLE"
          :name="store.shop.profile.nombre"
          :city="store.shop.profile.ciudad"
          :phone="store.shop.profile.telefono"
        />
      </div>
      <div class="px-5 pt-6 pb-8 md:px-8 md:pt-8">
        <div v-if="session.isAuthenticated" class="mb-4 flex justify-end">
          <RouterLink
            :to="{ name: 'my-bookings', params: { enlace: link } }"
            class="inline-flex min-h-11 items-center gap-2 rounded-pill border border-line-strong px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:border-line-hover"
          >
            <CalendarDays :size="16" :stroke-width="1.5" aria-hidden="true" />
            Mis reservas
          </RouterLink>
        </div>
        <BookingPanel
          v-if="store.shop.services.length > 0"
          ref="panel"
          v-model:barber="barberModel"
          v-model:date="dateModel"
          v-model:slot="slotModel"
          :has-service="service !== null"
          :barbers="availability.barbers.value"
          :days="days"
          :slots="slots"
          :hours-state="hoursState"
          :next-days="nextDays"
          :total="service?.precio ?? null"
          :selection="selection"
          :submitting="booking.submitting"
          :failure="failure"
          :alternatives="alternatives"
          :notice="booking.notice"
          :reservation="reservation"
          @retry="availability.retry()"
          @reserve="reserve"
          :payment-in-progress="paymentInProgress"
          @restart="booking.clear()"
          @pay="goToPayment"
          @pick-alternative="pickAlternative"
        >
          <template #services>
            <OptionGroup
              :model-value="booking.serviceId"
              aria-label="Servicio"
              class="-mx-4 gap-0!"
              @update:model-value="onService"
            >
              <OptionCard
                v-for="item in store.shop.services"
                :key="item.id"
                :value="item.id"
                variant="row"
              >
                <ServiceRow
                  bare
                  :name="item.nombre"
                  :duration-minutes="item.duracion_minutos"
                  :price="item.precio"
                  :base="item.base"
                  :vat="item.iva"
                />
              </OptionCard>
            </OptionGroup>
          </template>
        </BookingPanel>
        <section v-else aria-labelledby="services-title">
          <h2 id="services-title" class="sr-only">Servicios</h2>
          <EmptyState
            title="Esta barbería aún no tiene servicios."
            description="Vuelva a consultar más tarde."
          />
        </section>
      </div>
    </template>

    <template v-else-if="store.status === 'not-found'">
      <h1 class="sr-only">BarberHub</h1>
      <EmptyState title="Esta barbería no existe." description="Revise el enlace o vaya al inicio.">
        <template #action>
          <RouterLink
            to="/"
            class="inline-flex min-h-11 items-center rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium no-underline transition-colors duration-150 ease-out hover:bg-btn-hover"
            >Ir al inicio</RouterLink
          >
        </template>
      </EmptyState>
    </template>

    <template v-else>
      <h1 class="sr-only">BarberHub</h1>
      <ErrorState
        message="Error: no pudimos cargar la barbería."
        description="Revise su conexión e intente de nuevo."
        @retry="store.load(link)"
      />
    </template>
  </main>
</template>
