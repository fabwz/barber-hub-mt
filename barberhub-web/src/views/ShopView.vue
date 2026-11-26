<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useTemplateRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CalendarDays, Hourglass, Ticket } from 'lucide-vue-next'

import AiBookingBlock, { type BlockNotice } from '@/components/AiBookingBlock.vue'
import BackLink from '@/components/BackLink.vue'
import BookingPanel, {
  type PanelFailure,
  type PanelMissing,
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
import { addDays, dateOf, formatShortDate, timeOf, todayInCostaRica } from '@/composables/format'
import { useAvailability } from '@/composables/useAvailability'
import { shopHeaderProps } from '@/composables/shopHeaderProps'
import { readStoredPayment } from '@/composables/usePayment'
import { canPayWith, useVouchers } from '@/composables/useVouchers'
import { useWaitlistJoin } from '@/composables/useWaitlist'
import { useAiBookingStore, type BookingProposal } from '@/stores/aiBooking'
import { useBookingStore } from '@/stores/booking'
import { useSessionStore } from '@/stores/session'
import { useShopStore } from '@/stores/shop'

/** Days offered by the date picker: the booking window of DI-03. */
const WINDOW_DAYS = 30

const route = useRoute()
const router = useRouter()
const store = useShopStore()
const booking = useBookingStore()
const ai = useAiBookingStore()
const session = useSessionStore()
const vouchers = useVouchers()
const waitlist = useWaitlistJoin()

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
    ai.enter(shop.id)
    if (booking.serviceId && !shop.services.some((item) => item.id === booking.serviceId)) {
      booking.clear()
    }
  },
  { immediate: true },
)

// The vouchers of the person decide whether «Pagar con bono» is offered. They are read quietly: if
// they cannot be read, nothing is offered and the booking goes on as usual.
watch(
  () => (store.status === 'ready' && session.isAuthenticated ? store.shop : null),
  (shop) => {
    if (shop) void vouchers.load(shop.id)
  },
  { immediate: true },
)

/** Owner and collaborator get 403: they are offered nothing about vouchers. */
const staff = computed(() => vouchers.result.value === 'forbidden')

/** The waitlist is on in this shop (RF-09) and the person is not part of its team: «Avisarme si se libera» is offered. */
const waitlistOffer = computed(
  () => store.shop?.profile.lista_espera_activa === true && !staff.value,
)

/** Asks to join the row of the service, the day and the barber chosen (RF-30); without a session it goes to log in first. */
function joinWaitlist(): void {
  const shop = store.shop
  if (!shop || !booking.serviceId || !booking.date) return
  if (!session.isAuthenticated) {
    void router.push({ name: 'login', query: { redirect: route.fullPath } })
    return
  }
  void waitlist.join({
    shopId: shop.id,
    service: booking.serviceId,
    date: booking.date,
    barber: booking.barberId,
  })
}

/** The API found room for the combination: the space is picked instead of waiting. */
function pickWaitlistSpace(start: string, barberId: string): void {
  waitlist.reset()
  booking.selectDate(dateOf(start))
  booking.selectSlot({ start, barberId })
  availability.retry()
}

// A new service, day or barber is another row: what was said about the last one no longer applies.
watch(
  () => [booking.serviceId, booking.date, booking.barberId],
  () => waitlist.reset(),
)

/** How the reservation is paid: `bono` with a session of a voucher, `metodo` in the next step. */
const payWith = ref('metodo')

/** The vouchers are on, and one of the person's covers the service chosen. The API picks which. */
const voucherOffer = computed(
  () =>
    session.isAuthenticated &&
    vouchers.result.value === 'ready' &&
    vouchers.enabled.value &&
    booking.serviceId !== null &&
    vouchers.mine.value.some((item) => canPayWith(item, booking.serviceId!)),
)
watch([voucherOffer, () => booking.serviceId], () => {
  payWith.value = 'metodo'
})

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
  set: (value: string) => {
    forget('barbero')
    booking.selectBarber(value === '' ? null : value)
  },
})
const dateModel = computed({
  get: () => booking.date,
  set: (value: string | null) => {
    if (value) {
      forget('fecha')
      booking.selectDate(value)
    }
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
    paidWithVoucher: created.paidWithVoucher,
    cancellationHours: data.plazo_cancelacion_horas,
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
  // The API refused the voucher (stable types): no reservation and no session were used.
  if (error.type.endsWith('saldo-insuficiente')) {
    return {
      title: 'Su bono ya no tiene sesiones disponibles.',
      description:
        'No se reservó ni se usó ninguna sesión. Reserve de nuevo y pague con tarjeta o SINPE Móvil.',
    }
  }
  if (error.type.endsWith('bono-vencido')) {
    return {
      title: 'Su bono vence antes de la fecha de la cita.',
      description:
        'No se reservó ni se usó ninguna sesión. Elija otra fecha, o reserve de nuevo y pague con tarjeta o SINPE Móvil.',
    }
  }
  return { title: 'No pudimos crear la reserva.', description: error.detail }
})

const alternatives = computed<TimeSlot[]>(() =>
  booking.alternatives.map((item) => ({ value: item.start, time: timeOf(item.start) })),
)

function onService(id: string | null): void {
  if (id) {
    forget('servicio')
    booking.selectService(id)
  }
}

function pickAlternative(value: string): void {
  const found = booking.alternatives.find((item) => item.start === value)
  if (found) booking.pickAlternative(found)
}

// —— Assistant (RF-37): a phrase becomes a proposal that fills the manual choices; it books nothing ——

const BAND_TEXT = { manana: 'por la mañana', tarde: 'por la tarde', noche: 'por la noche' } as const
const FIELD_TEXT = {
  servicio: 'el servicio',
  barbero: 'el barbero',
  fecha: 'el día',
  hora: 'la hora',
}

/** Open on desktop, folded on a phone; open as well when there is a phrase or a proposal to see. */
const aiOpen = ref(
  (typeof globalThis.matchMedia === 'function' &&
    globalThis.matchMedia('(min-width: 48rem)').matches) ||
    ai.phrase !== '' ||
    ai.state !== 'idle',
)

/** Choices the proposal could not make, marked «Falta elegir» until the person makes them. */
const flagged = ref<PanelMissing[]>([])

function forget(field: PanelMissing): void {
  flagged.value = flagged.value.filter((item) => item !== field)
}

const proposal = computed(() => (ai.state === 'ready' ? ai.proposal : null))

function barberNameFor(id: string | null): string {
  if (id === null) return ''
  const known = proposal.value?.candidates.find((item) => item.id === id)?.name
  return known || barberNameOf(id)
}

/** The barber the phrase named, when the catalog resolved it: the catalog name or, failing that, the words used. */
function namedBarber(current: BookingProposal): string {
  if (current.barberId === null) return ''
  return barberNameFor(current.barberId) || (current.barberText ?? '')
}

function capitalize(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1)
}

/** The request in plain words, made only of what the API resolved. */
const aiSentence = computed(() => {
  const current = proposal.value
  if (current === null) return ''
  const serviceName = store.shop?.services.find((item) => item.id === current.serviceId)?.nombre
  const barber = namedBarber(current)
  // Without a recognized service there is no filler: only what the API resolved is shown.
  const parts = [
    serviceName ? serviceName + (barber ? ` con ${barber}` : '') : '',
    current.date ? `el ${formatShortDate(current.date)}` : '',
    current.time ? `a las ${current.time}` : '',
    current.band ? BAND_TEXT[current.band] : '',
  ].filter((part) => part !== '')
  return `${capitalize(parts.join(', '))}.`
})

function quoted(text: string | null): string {
  return text ? `«${text}»` : ''
}

function missingText(current: BookingProposal): string {
  const fields = current.missing.length > 0 ? current.missing : current.field ? [current.field] : []
  return fields.map((field) => FIELD_TEXT[field]).join(' ni ')
}

const aiWarning = computed<BlockNotice | null>(() => {
  const current = proposal.value
  if (current === null) return null
  switch (current.status) {
    case 'incompleta':
      return {
        title: 'Faltan datos',
        body: `No pudimos saber ${missingText(current)}. ${
          flagged.value.length > 0
            ? 'Complete lo que falta abajo, donde quedó resaltado.'
            : 'Elíjala abajo.'
        }`,
      }
    case 'ambigua': {
      const names = current.candidates.map((item) => quoted(item.name)).join(', ')
      return {
        title: 'Hay varias opciones',
        body: `Su frase encaja con más de un ${current.field === 'servicio' ? 'servicio' : 'barbero'}${
          names ? `: ${names}` : ''
        }. Elija uno abajo, donde quedó resaltado.`,
      }
    }
    case 'no_reconocida': {
      const service = current.field === 'servicio'
      const said = quoted(service ? current.serviceText : current.barberText)
      const known = namedBarber(current)
      if (!service && known !== '') {
        // The barber exists in this shop but does not offer the service: both are named.
        const offered = store.shop?.services.find((item) => item.id === current.serviceId)?.nombre
        return {
          title: 'No disponible en esta barbería',
          body: `${known} no ofrece ${quoted(offered ?? current.serviceText) || 'ese servicio'}. Elija un barbero de abajo, donde quedó resaltado.`,
        }
      }
      return {
        title: 'No disponible en esta barbería',
        body: service
          ? `Esta barbería no ofrece ${said || 'ese servicio'}. Elija un servicio de abajo, donde quedó resaltado.`
          : `Esta barbería no tiene un barbero llamado ${said || 'ese'}. Elija un barbero de abajo, donde quedó resaltado.`,
      }
    }
    case 'no_disponible':
      return current.alternatives.length > 0
        ? {
            title: 'Ese espacio no está disponible.',
            body: 'Estos espacios del mismo día son los más cercanos a lo que pidió.',
          }
        : null
    default:
      return null
  }
})

const aiEmpty = computed<BlockNotice | null>(() => {
  const current = proposal.value
  if (current?.status !== 'no_disponible' || current.alternatives.length > 0) return null
  const barber = namedBarber(current)
  return {
    title: `No quedan espacios${current.date ? ` el ${formatShortDate(current.date)}` : ''}${
      barber ? ` con ${barber}` : ''
    }.`,
    body: barber
      ? 'Pruebe otro día, o elija «Sin preferencia» abajo para ver las horas de todos los barberos.'
      : 'Pruebe otro día.',
  }
})

/** The spaces to offer: those of the proposal, the nearest ones, or the exact space when it is free. */
const aiSpacesRaw = computed(() => {
  const current = proposal.value
  if (current === null) return []
  if (current.status === 'no_disponible') return current.alternatives
  if (current.spaces.length > 0) return current.spaces
  return current.status === 'valida' && current.start && current.end && current.barberId
    ? [{ barberId: current.barberId, start: current.start, end: current.end }]
    : []
})

const aiSpaces = computed<TimeSlot[]>(() =>
  aiSpacesRaw.value.map((item) => ({
    value: item.start,
    time: timeOf(item.start),
    barberName: barberNameFor(item.barberId),
  })),
)

const aiSpacesFor = computed(() => {
  const first = aiSpacesRaw.value[0]
  const current = proposal.value
  if (!first || current === null) return ''
  const barber = namedBarber(current)
  return `${formatShortDate(dateOf(first.start))}${barber ? ` con ${barber}` : ''}`
})

/** Too many requests (429): another text and the wait that `Retry-After` brings. */
const aiFailure = computed<BlockNotice | null>(() => {
  const error = ai.error
  if (error === null || (error.status !== 429 && !error.type.endsWith('demasiadas-solicitudes'))) {
    return null
  }
  return {
    title: 'No pudimos procesar su solicitud.',
    body: 'Hizo muchas solicitudes seguidas. Espere un momento o continúe a mano aquí abajo. Su frase se conserva.',
  }
})

/** Fills service, barber and day through the booking store. Nothing is reserved. */
function applyProposal(current: BookingProposal): void {
  const marks: PanelMissing[] = []
  const known = store.shop?.services.some((item) => item.id === current.serviceId) ?? false
  if (current.serviceId !== null && known) {
    booking.selectService(current.serviceId)
    // A barber who does not offer the service is shown in the request but never selected.
    booking.selectBarber(current.status === 'no_reconocida' ? null : current.barberId)
  } else if (
    booking.serviceId === null &&
    (current.missing.includes('servicio') || current.field === 'servicio')
  ) {
    marks.push('servicio')
  }
  if (current.date !== null) booking.selectDate(current.date)
  else if (current.missing.includes('fecha') || current.field === 'fecha') marks.push('fecha')
  if (
    current.field === 'barbero' &&
    (current.status === 'ambigua' || current.status === 'no_reconocida')
  ) {
    marks.push('barbero')
  }
  flagged.value = marks
  aiOpen.value = true
}

async function generate(): Promise<void> {
  if (!session.isAuthenticated) {
    // The phrase stays in the store: it is still here after the login.
    await goToLogin()
    return
  }
  if (!store.shop) return
  flagged.value = []
  const found = await ai.propose(store.shop.id)
  if (ai.error?.status === 401) {
    await goToLogin()
    return
  }
  if (found && ai.proposal) applyProposal(ai.proposal)
}

/** A chip of the proposal completes the hour below; the reservation is still made with «Reservar». */
function pickSpace(value: string): void {
  const found = aiSpacesRaw.value.find((item) => item.start === value)
  if (found) booking.selectSlot({ start: found.start, barberId: found.barberId })
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
  const withVoucher = voucherOffer.value && payWith.value === 'bono'
  await booking.reserve(
    { serviceName: service.value.nombre, barberName: selection.value.barberName },
    { withVoucher },
  )
  if (booking.error?.status === 401) await goToLogin()
  const refused = booking.error?.type ?? ''
  if (refused.endsWith('saldo-insuficiente') || refused.endsWith('bono-vencido')) {
    // The balance or the validity changed: read the vouchers again and go back to the usual payment.
    payWith.value = 'metodo'
    if (store.shop) void vouchers.load(store.shop.id)
  }
}
</script>

<template>
  <main :aria-busy="store.status === 'loading'" class="mx-auto w-full max-w-7xl">
    <!-- The way back to the directory, in every state of the screen. -->
    <div class="px-5 pt-4 md:px-8 md:pt-6">
      <BackLink :to="{ name: 'directory' }" class="-ml-3" />
    </div>
    <div v-if="store.status === 'loading' || store.status === 'idle'" class="px-5 md:px-8">
      <span class="sr-only">Cargando</span>
      <!-- Skeleton with the shape of the header -->
      <div class="border-b border-line" aria-hidden="true">
        <div class="aspect-2/1 w-full animate-pulse bg-line md:aspect-3/1" />
        <div class="flex flex-col gap-3 pb-4">
          <div class="-mt-9 size-18 animate-pulse rounded-full bg-line md:-mt-11 md:size-22" />
          <div class="h-9 w-2/3 animate-pulse rounded-field bg-line" />
          <div class="h-4 w-1/2 animate-pulse rounded-field bg-line" />
        </div>
      </div>
      <div class="max-w-180 py-5 md:py-8" aria-hidden="true">
        <SkeletonBlock shape="row" :count="4" />
      </div>
    </div>

    <template v-else-if="store.status === 'ready' && store.shop">
      <!-- The line under the header spans the whole 1280 px container, as in the design. -->
      <div class="border-b border-line px-5 md:px-8">
        <ShopHeader class="border-b-0!" wide v-bind="shopHeaderProps(store.shop.profile)" />
      </div>
      <div class="px-5 pt-6 pb-8 md:px-8 md:pt-8">
        <div v-if="session.isAuthenticated" class="mb-4 flex flex-wrap justify-end gap-2">
          <RouterLink
            :to="{ name: 'my-bookings', params: { enlace: link } }"
            class="inline-flex min-h-11 items-center gap-2 rounded-pill border border-line-strong px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:border-line-hover"
          >
            <CalendarDays :size="16" :stroke-width="1.5" aria-hidden="true" />
            Mis reservas
          </RouterLink>
          <RouterLink
            v-if="!staff"
            :to="{ name: 'waitlist', params: { enlace: link } }"
            class="inline-flex min-h-11 items-center gap-2 rounded-pill border border-line-strong px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:border-line-hover"
          >
            <Hourglass :size="16" :stroke-width="1.5" aria-hidden="true" />
            Lista de espera
          </RouterLink>
          <RouterLink
            v-if="!staff"
            :to="{ name: 'vouchers', params: { enlace: link } }"
            class="inline-flex min-h-11 items-center gap-2 rounded-pill border border-line-strong px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:border-line-hover"
          >
            <Ticket :size="16" :stroke-width="1.5" aria-hidden="true" />
            Mis bonos
          </RouterLink>
        </div>
        <BookingPanel
          v-if="store.shop.services.length > 0"
          ref="panel"
          v-model:barber="barberModel"
          v-model:date="dateModel"
          v-model:slot="slotModel"
          v-model:pay-with="payWith"
          :voucher-offer="voucherOffer"
          :waitlist="waitlistOffer"
          :join-state="waitlist.state.value"
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
          :missing="flagged"
          @restart="booking.clear()"
          @pay="goToPayment"
          @view-bookings="router.push({ name: 'my-bookings', params: { enlace: link } })"
          @view-vouchers="router.push({ name: 'vouchers', params: { enlace: link } })"
          @join-waitlist="joinWaitlist"
          @view-waitlist="router.push({ name: 'waitlist', params: { enlace: link } })"
          @pick-waitlist-space="pickWaitlistSpace"
          @pick-alternative="pickAlternative"
        >
          <template #assistant>
            <AiBookingBlock
              v-model:open="aiOpen"
              v-model:phrase="ai.phrase"
              :state="ai.state"
              :sentence="aiSentence"
              :warning="aiWarning"
              :empty="aiEmpty"
              :spaces="aiSpaces"
              :spaces-for="aiSpacesFor"
              :show-barber="proposal !== null && proposal.barberId === null"
              :selected="booking.slot?.start ?? null"
              :failure="aiFailure"
              :retry-after="ai.error?.retryAfter ?? null"
              @submit="generate"
              @retry="generate"
              @pick="pickSpace"
            />
          </template>

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
                  :image="item.imagen"
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
        class="mx-5 mt-6 md:mx-8 md:mt-8"
        message="No pudimos cargar la barbería."
        description="Revise su conexión e intente de nuevo."
        @retry="store.load(link)"
      />
    </template>
  </main>
</template>
