<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { Check, UserRound } from 'lucide-vue-next'

import { isApiError, type ApiError } from '@/api'
import BackLink from '@/components/BackLink.vue'
import BaseButton from '@/components/BaseButton.vue'
import DatePicker from '@/components/DatePicker.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import OptionCard from '@/components/OptionCard.vue'
import OptionGroup from '@/components/OptionGroup.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import StaffNavbar from '@/components/StaffNavbar.vue'
import TextField from '@/components/TextField.vue'
import TimeSlots, { type TimeSlot } from '@/components/TimeSlots.vue'
import {
  readOfferedSpaces,
  registerAssistedReservation,
  useAssistedClientSearch,
  type AssistedClient,
  type AssistedReservation,
  type OfferedSpace,
} from '@/composables/useAssistedClients'
import { useAvailability } from '@/composables/useAvailability'
import {
  addDays,
  colones,
  dateOf,
  durationLabel,
  formatShortDate,
  timeOf,
  todayInCostaRica,
} from '@/composables/format'
import { useFormErrors } from '@/composables/useFormErrors'
import { useServiceAdminStore } from '@/stores/services'
import { useTeamStore } from '@/stores/team'

// «Reserva asistida» (RF-12, lámina 29): the owner registers a reservation for a person who arrives or calls and has no
// account. They look for the person among the assisted clients of the shop or create a new one (name and phone, email
// optional), and choose service, barber and space from the same availability as the customer (RF-10). Nobody pays online:
// the server confirms it in the same operation (RE-07). A space that was taken answers `espacio-no-disponible` with others.
type Field = 'client' | 'name' | 'phone' | 'email' | 'service' | 'barber' | 'slot'
const FIELD_ORDER: readonly Field[] = [
  'client',
  'name',
  'phone',
  'email',
  'service',
  'barber',
  'slot',
]

const WINDOW_DAYS = 14
const TOO_LONG = 'Escriba un nombre de hasta 120 caracteres.'
const NAME_MISSING = 'Escriba el nombre del cliente.'
const PHONE_MISSING = 'Escriba un teléfono para poder contactar al cliente.'
const EMAIL_INVALID = 'Escriba un correo válido, por ejemplo nombre@correo.cr.'

const route = useRoute()
const services = useServiceAdminStore()
const team = useTeamStore()

const barberia = computed(() => String(route.params.barberia))
const today = todayInCostaRica()

onMounted(() => {
  void services.load(barberia.value)
  void team.load(barberia.value)
})

// -- The client ---------------------------------------------------------------------------------------------------------
const clientMode = ref<'search' | 'new'>('search')
const term = ref('')
const picked = ref<AssistedClient | null>(null)
const name = ref('')
const phone = ref('')
const email = ref('')

const search = useAssistedClientSearch(
  () => (clientMode.value === 'search' && picked.value === null ? barberia.value : null),
  term,
)
const searching = computed(() => term.value.trim() !== '')

function pick(client: AssistedClient): void {
  picked.value = client
  errors.clearField('client')
}

function changeClient(): void {
  picked.value = null
  void nextTick(() => document.getElementById('assisted-search')?.focus())
}

function startNew(): void {
  clientMode.value = 'new'
  picked.value = null
  errors.clearField('client')
  void nextTick(() => document.getElementById('assisted-name')?.focus())
}

function backToSearch(): void {
  clientMode.value = 'search'
  errors.clearField('name')
  errors.clearField('phone')
  errors.clearField('email')
  void nextTick(() => document.getElementById('assisted-search')?.focus())
}

// -- The space ----------------------------------------------------------------------------------------------------------
const serviceId = ref<string | null>(null)
const barberId = ref('')
const date = ref<string>(today)
const slot = ref<string | null>(null)

const activeServices = computed(() => services.items.filter((item) => item.activo))
const service = computed(
  () => activeServices.value.find((item) => item.id === serviceId.value) ?? null,
)
/** The collaborators that can attend the service: active and with it enabled (CO-01). */
const barbers = computed(() =>
  team.items.filter(
    (item) => item.activo && serviceId.value !== null && item.servicios.includes(serviceId.value),
  ),
)
const barber = computed(() => barbers.value.find((item) => item.id === barberId.value) ?? null)

const availability = useAvailability({
  shopId: () => barberia.value,
  serviceId: () => serviceId.value,
  barberId: () => (barberId.value === '' ? null : barberId.value),
  // Without a barber chosen there is nothing to ask: the owner chooses the barber (RF-12).
  date: () => (barberId.value === '' ? null : date.value),
})

const days = computed(() =>
  Array.from({ length: WINDOW_DAYS }, (_, index) => {
    const value = addDays(today, index)
    return { value, closed: availability.days.value[value] === 'closed' }
  }),
)

const slots = computed<TimeSlot[]>(() =>
  availability.allSlots.value
    .filter((item) => item.barberId === barberId.value)
    .map((item) => ({ value: item.start, time: timeOf(item.start) })),
)

const hoursState = computed<'idle' | 'loading' | 'error' | 'empty' | 'ready'>(() => {
  if (serviceId.value === null || barberId.value === '') return 'idle'
  if (availability.error.value !== null) return 'error'
  if (!availability.loaded.value) return 'loading'
  return slots.value.length === 0 ? 'empty' : 'ready'
})

watch(serviceId, () => {
  // A barber that does not offer the new service no longer applies.
  if (barberId.value !== '' && !barbers.value.some((item) => item.id === barberId.value)) {
    barberId.value = ''
  }
  slot.value = null
  errors.clearField('service')
})
watch([barberId, date], () => {
  slot.value = null
})
watch(barberId, () => errors.clearField('barber'))
watch(slot, () => errors.clearField('slot'))

const summarySlot = computed(() => slots.value.find((item) => item.value === slot.value) ?? null)

// -- Errors -------------------------------------------------------------------------------------------------------------
const errors = useFormErrors<Field>({
  cliente_asistido: 'client',
  cliente_nombre: 'name',
  cliente_telefono: 'phone',
  cliente_correo: 'email',
  servicio: 'service',
  barbero: 'barber',
  inicio: 'slot',
})

const submitting = ref(false)
const failure = ref('')
const offered = ref<OfferedSpace[]>([])
const done = ref<{ reservation: AssistedReservation; client: string; phone: string } | null>(null)

function validate(): boolean {
  errors.clear()
  if (clientMode.value === 'search') {
    if (picked.value === null) errors.setField('client', 'Elija un cliente o cree uno nuevo.')
  } else {
    const text = name.value.trim()
    if (text === '') errors.setField('name', NAME_MISSING)
    else if (text.length > 120) errors.setField('name', TOO_LONG)
    if (phone.value.trim() === '') errors.setField('phone', PHONE_MISSING)
    if (email.value.trim() !== '' && !/^\S+@\S+\.\S+$/.test(email.value.trim())) {
      errors.setField('email', EMAIL_INVALID)
    }
  }
  if (serviceId.value === null) errors.setField('service', 'Elija un servicio.')
  if (barberId.value === '') errors.setField('barber', 'Elija un barbero.')
  if (slot.value === null) errors.setField('slot', 'Elija una hora.')
  return Object.keys(errors.fieldErrors.value).length === 0
}

const FOCUS_TARGET: Record<Field, string> = {
  client: 'assisted-search',
  name: 'assisted-name',
  phone: 'assisted-phone',
  email: 'assisted-email',
  service: 'assisted-service',
  barber: 'assisted-barber',
  slot: 'assisted-hours',
}

function focusFirstError(): void {
  const first = errors.firstField(FIELD_ORDER)
  if (first === undefined) return
  void nextTick(() => {
    const target = document.getElementById(FOCUS_TARGET[first])
    const focusable =
      target?.matches('input, select, button') === true
        ? target
        : (target?.querySelector<HTMLElement>('[tabindex="0"], button') ?? target)
    focusable?.focus()
  })
}

function explain(error: ApiError): void {
  if (error.status === 409 && error.type.endsWith('espacio-no-disponible')) {
    offered.value = readOfferedSpaces(error.alternatives)
    slot.value = null
    // The space taken is no longer free: the hours are read again.
    availability.retry()
  } else if (error.status === 404) {
    failure.value =
      'Ya no encontramos el servicio, el barbero o el cliente elegido. Recargue la pantalla e inténtelo de nuevo.'
    void services.load(barberia.value)
    void team.load(barberia.value)
  } else if (error.status === 403) {
    failure.value = 'Solo el dueño de la barbería puede registrar una reserva asistida.'
  } else if (error.status === 422) {
    errors.setFromApiError(error)
    if (errors.generalError.value !== '') failure.value = errors.generalError.value
    else focusFirstError()
  } else {
    failure.value = 'No pudimos registrar la reserva. Inténtelo de nuevo.'
  }
}

async function submit(): Promise<void> {
  if (submitting.value) return
  failure.value = ''
  offered.value = []
  if (!validate()) {
    focusFirstError()
    return
  }
  submitting.value = true
  try {
    const existing = picked.value
    const reservation = await registerAssistedReservation(barberia.value, {
      servicio: serviceId.value!,
      barbero: barberId.value,
      inicio: slot.value!,
      ...(existing !== null
        ? { cliente_asistido: existing.id }
        : {
            cliente_nombre: name.value.trim(),
            cliente_telefono: phone.value.trim(),
            ...(email.value.trim() === '' ? {} : { cliente_correo: email.value.trim() }),
          }),
    })
    done.value = {
      reservation,
      client: existing?.nombre ?? name.value.trim(),
      phone: existing?.telefono ?? phone.value.trim(),
    }
  } catch (caught) {
    if (!isApiError(caught) || caught.status === 401) return
    explain(caught)
  } finally {
    submitting.value = false
  }
}

/** One of the spaces the API offered: the day and the hour are chosen again with it. */
function chooseOffered(space: OfferedSpace): void {
  offered.value = []
  barberId.value = space.barberId
  date.value = dateOf(space.start)
  void nextTick(() => {
    slot.value = space.start
  })
}

function another(): void {
  done.value = null
  clientMode.value = 'search'
  term.value = ''
  picked.value = null
  name.value = ''
  phone.value = ''
  email.value = ''
  serviceId.value = null
  barberId.value = ''
  date.value = today
  slot.value = null
  errors.clear()
  failure.value = ''
  offered.value = []
  void nextTick(() => document.getElementById('assisted-title')?.focus())
}

const agendaLink = computed(() => ({
  name: 'staff-agenda',
  params: { barberia: barberia.value },
}))
const reservationLink = computed(() =>
  done.value === null
    ? agendaLink.value
    : {
        name: 'staff-reservation',
        params: { barberia: barberia.value, reserva: done.value.reservation.id },
        query: { dia: dateOf(done.value.reservation.inicio) },
      },
)

const dayText = computed(() => formatShortDate(date.value))
const chosenWhen = computed(() =>
  summarySlot.value === null || barber.value === null
    ? ''
    : `${formatShortDate(date.value)}, ${summarySlot.value.time} con ${barber.value.nombre}`,
)

const SELECT_CLASS =
  'min-h-12 w-full max-w-120 rounded-field border bg-canvas px-3 text-base text-fg focus:border-accent focus:outline-none disabled:cursor-not-allowed disabled:border-line disabled:text-fg-subtle'
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <StaffNavbar />
    <main class="mx-auto w-full max-w-3xl flex-1 px-5 py-6 md:px-8">
      <BackLink :to="agendaLink">Volver a la agenda</BackLink>
      <h1 id="assisted-title" tabindex="-1" class="mt-4 text-2xl outline-offset-2">
        Reserva asistida
      </h1>
      <p class="mt-1 text-sm text-fg-muted">
        Registre una reserva para quien llega o llama. El cliente no necesita cuenta.
      </p>

      <!-- Done: the reservation is already confirmed, with nothing to pay online (RE-07) -->
      <section v-if="done" aria-labelledby="assisted-done-title" class="mt-6 flex flex-col gap-6">
        <div role="status" class="flex flex-col gap-4 rounded-card border border-line p-5">
          <h2 id="assisted-done-title" class="flex items-center gap-2 text-lg font-medium">
            <Check :size="20" :stroke-width="1.5" aria-hidden="true" />
            Reserva registrada
          </h2>
          <dl class="divide-y divide-line border-y border-line text-sm">
            <div class="flex justify-between gap-4 py-3">
              <dt class="text-fg-muted">Cliente</dt>
              <dd class="text-right">{{ done.client }} · {{ done.phone }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-3">
              <dt class="text-fg-muted">Servicio</dt>
              <dd class="text-right">{{ service?.nombre }}</dd>
            </div>
            <div class="flex justify-between gap-4 py-3">
              <dt class="text-fg-muted">Fecha y hora</dt>
              <dd class="text-right tabular-nums">
                {{ formatShortDate(dateOf(done.reservation.inicio)) }},
                {{ timeOf(done.reservation.inicio) }}–{{ timeOf(done.reservation.fin) }} con
                {{ barber?.nombre }}
              </dd>
            </div>
            <div class="flex justify-between gap-4 py-3">
              <dt class="text-fg-muted">
                Total <span class="text-fg-subtle">(IVA incluido)</span>
              </dt>
              <dd class="text-right tabular-nums">{{ colones(done.reservation.precio) }}</dd>
            </div>
          </dl>
          <p class="text-sm text-fg-muted">
            Confirmada, sin cobro en línea. El espacio ya no se ofrece a nadie más.
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <RouterLink
            :to="reservationLink"
            class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover"
            >Ver en la agenda</RouterLink
          >
          <BaseButton variant="secondary" @click="another">Registrar otra</BaseButton>
        </div>
      </section>

      <form v-else class="mt-6 flex flex-col gap-8" novalidate @submit.prevent="submit">
        <NoticeBanner v-if="failure" variant="error">{{ failure }}</NoticeBanner>

        <!-- Taken space: the closest ones are offered (RE-02) -->
        <div
          v-if="offered.length > 0"
          role="alert"
          class="flex flex-col gap-3 rounded-field bg-danger-surface p-4"
        >
          <h2 class="font-medium text-danger">Ese espacio ya no está libre</h2>
          <p class="text-sm text-fg-muted">
            Otra reserva lo ocupó hace un momento. Elija otra hora:
          </p>
          <ul class="flex flex-wrap gap-2">
            <li v-for="space in offered" :key="space.start">
              <BaseButton variant="secondary" @click="chooseOffered(space)">{{
                `${formatShortDate(dateOf(space.start))}, ${timeOf(space.start)}`
              }}</BaseButton>
            </li>
          </ul>
        </div>

        <!-- Client -->
        <section aria-labelledby="assisted-client-title" class="flex flex-col gap-4">
          <h2 id="assisted-client-title" class="text-lg font-medium">Cliente</h2>

          <template v-if="clientMode === 'search'">
            <div
              v-if="picked"
              class="flex flex-wrap items-center justify-between gap-3 rounded-card border border-line-strong bg-elevated p-4"
            >
              <p class="flex min-w-0 items-center gap-3">
                <UserRound :size="20" :stroke-width="1.5" class="flex-none" aria-hidden="true" />
                <span class="min-w-0">
                  <span class="block font-medium">{{ picked.nombre }}</span>
                  <span class="block text-sm text-fg-muted"
                    >{{ picked.telefono }} · {{ picked.correo ?? 'Sin correo' }}</span
                  >
                </span>
              </p>
              <BaseButton variant="secondary" @click="changeClient">Cambiar cliente</BaseButton>
            </div>

            <template v-else>
              <div class="flex flex-col gap-2">
                <label for="assisted-search" class="text-sm font-medium text-fg"
                  >Buscar cliente</label
                >
                <input
                  id="assisted-search"
                  v-model="term"
                  type="search"
                  autocomplete="off"
                  placeholder="Nombre o teléfono"
                  aria-describedby="assisted-search-hint"
                  :aria-invalid="errors.fieldErrors.value.client ? 'true' : undefined"
                  class="min-h-12 w-full rounded-field border bg-canvas px-4 text-base text-fg placeholder:text-fg-subtle focus:border-accent focus:outline-none"
                  :class="errors.fieldErrors.value.client ? 'border-danger-border' : 'border-field'"
                />
                <p id="assisted-search-hint" class="text-sm text-fg-muted">
                  Solo se buscan clientes asistidos de esta barbería.
                </p>
                <p v-if="errors.fieldErrors.value.client" class="text-sm text-danger">
                  {{ errors.fieldErrors.value.client }}
                </p>
              </div>

              <div aria-live="polite" :aria-busy="search.status.value === 'loading'">
                <SkeletonBlock
                  v-if="searching && search.status.value === 'loading'"
                  shape="row"
                  :count="2"
                  label="Buscando clientes"
                />
                <ErrorState
                  v-else-if="search.status.value === 'error'"
                  message="No pudimos buscar clientes"
                  description="Revise su conexión e inténtelo de nuevo."
                  @retry="search.retry"
                />
                <p
                  v-else-if="
                    searching &&
                    search.status.value === 'ready' &&
                    search.results.value.length === 0
                  "
                  class="text-sm text-fg-muted"
                >
                  No encontramos a nadie con ese dato. Puede crear un cliente nuevo.
                </p>
                <ul
                  v-else-if="search.results.value.length > 0"
                  aria-label="Clientes encontrados"
                  class="flex flex-col border-t border-line"
                >
                  <li
                    v-for="client in search.results.value"
                    :key="client.id"
                    class="border-b border-line"
                  >
                    <button
                      type="button"
                      class="flex min-h-14 w-full cursor-pointer flex-col items-start justify-center gap-0.5 px-3 py-2 text-left transition-colors duration-150 ease-out hover:bg-surface"
                      @click="pick(client)"
                    >
                      <span class="font-medium text-fg">{{ client.nombre }}</span>
                      <span class="text-sm text-fg-muted"
                        >{{ client.telefono }} · {{ client.correo ?? 'Sin correo' }}</span
                      >
                    </button>
                  </li>
                </ul>
              </div>

              <div>
                <BaseButton variant="secondary" @click="startNew">Crear cliente nuevo</BaseButton>
              </div>
            </template>
          </template>

          <template v-else>
            <TextField
              id="assisted-name"
              v-model="name"
              label="Nombre completo"
              autocomplete="off"
              maxlength="120"
              :error="errors.fieldErrors.value.name ?? ''"
              @input="errors.clearField('name')"
            />
            <TextField
              id="assisted-phone"
              v-model="phone"
              type="tel"
              label="Teléfono"
              autocomplete="off"
              maxlength="30"
              :error="errors.fieldErrors.value.phone ?? ''"
              @input="errors.clearField('phone')"
            />
            <div class="flex flex-col gap-2">
              <TextField
                id="assisted-email"
                v-model="email"
                type="email"
                label="Correo (opcional)"
                autocomplete="off"
                maxlength="255"
                aria-describedby="assisted-email-hint"
                :error="errors.fieldErrors.value.email ?? ''"
                @input="errors.clearField('email')"
              />
              <p id="assisted-email-hint" class="text-sm text-fg-muted">
                Sin correo, el cliente no recibirá recordatorios ni avisos.
              </p>
            </div>
            <div>
              <BaseButton variant="secondary" @click="backToSearch"
                >Buscar un cliente existente</BaseButton
              >
            </div>
          </template>
        </section>

        <!-- Service -->
        <section aria-labelledby="assisted-service-title" class="flex flex-col gap-4">
          <h2 id="assisted-service-title" class="text-lg font-medium">Servicio</h2>
          <SkeletonBlock
            v-if="
              services.status === 'idle' ||
              (services.status === 'loading' && services.items.length === 0)
            "
            shape="row"
            :count="3"
            label="Cargando los servicios"
          />
          <ErrorState
            v-else-if="services.status === 'error'"
            message="No pudimos cargar los servicios"
            description="Revise su conexión e inténtelo de nuevo."
            @retry="services.load(barberia)"
          />
          <EmptyState
            v-else-if="activeServices.length === 0"
            title="No hay servicios activos."
            description="Active o cree un servicio para poder registrar reservas."
          />
          <div v-else id="assisted-service">
            <OptionGroup v-model="serviceId" aria-labelledby="assisted-service-title">
              <OptionCard
                v-for="item in activeServices"
                :key="item.id"
                :value="item.id"
                variant="row"
              >
                {{ item.nombre }}
                <template #description>{{ durationLabel(item.duracion_minutos) }}</template>
                <template #trailing
                  >{{ colones(item.precio)
                  }}<span class="block text-xs text-fg-subtle">IVA incluido</span></template
                >
              </OptionCard>
            </OptionGroup>
            <p v-if="errors.fieldErrors.value.service" class="mt-2 text-sm text-danger">
              {{ errors.fieldErrors.value.service }}
            </p>
          </div>
        </section>

        <!-- Barber -->
        <section aria-labelledby="assisted-barber-title" class="flex flex-col gap-2">
          <h2 id="assisted-barber-title" class="text-lg font-medium">Barbero</h2>
          <label for="assisted-barber" class="sr-only">Barbero</label>
          <SkeletonBlock
            v-if="team.status === 'idle' || (team.status === 'loading' && team.items.length === 0)"
            shape="row"
            :count="1"
            label="Cargando los barberos"
          />
          <ErrorState
            v-else-if="team.status === 'error'"
            message="No pudimos cargar a los barberos"
            description="Revise su conexión e inténtelo de nuevo."
            @retry="team.load(barberia)"
          />
          <template v-else>
            <select
              id="assisted-barber"
              v-model="barberId"
              :disabled="serviceId === null"
              :class="[
                SELECT_CLASS,
                errors.fieldErrors.value.barber ? 'border-danger-border' : 'border-field',
              ]"
              :aria-invalid="errors.fieldErrors.value.barber ? 'true' : undefined"
              :aria-describedby="
                errors.fieldErrors.value.barber ? 'assisted-barber-error' : 'assisted-barber-hint'
              "
            >
              <option value="" disabled>
                {{ serviceId === null ? 'Elija primero un servicio' : 'Elija un barbero' }}
              </option>
              <option v-for="item in barbers" :key="item.id" :value="item.id">
                {{ item.nombre }}
              </option>
            </select>
            <p
              v-if="errors.fieldErrors.value.barber"
              id="assisted-barber-error"
              class="text-sm text-danger"
            >
              {{ errors.fieldErrors.value.barber }}
            </p>
            <p
              v-else-if="serviceId !== null && barbers.length === 0"
              id="assisted-barber-hint"
              class="text-sm text-fg-muted"
            >
              Ningún colaborador activo ofrece este servicio.
            </p>
          </template>
        </section>

        <!-- Date and hour -->
        <section aria-labelledby="assisted-hours-title" class="flex flex-col gap-4">
          <h2 id="assisted-hours-title" class="text-lg font-medium">Fecha y hora</h2>
          <p v-if="hoursState === 'idle'" class="text-sm text-fg-muted">
            Elija un servicio y un barbero para ver las horas libres.
          </p>
          <template v-else>
            <DatePicker v-model="date" :days="days" wrap />
            <div id="assisted-hours" aria-live="polite" :aria-busy="hoursState === 'loading'">
              <SkeletonBlock
                v-if="hoursState === 'loading'"
                shape="row"
                :count="2"
                label="Cargando horas"
              />
              <ErrorState
                v-else-if="hoursState === 'error'"
                message="No pudimos cargar los horarios"
                description="Revise su conexión e inténtelo de nuevo."
                @retry="availability.retry"
              />
              <EmptyState
                v-else-if="hoursState === 'empty'"
                :title="`No quedan horas el ${dayText} con ${barber?.nombre ?? 'ese barbero'}.`"
                description="Pruebe con otro día o con otro barbero."
              />
              <TimeSlots v-else v-model="slot" :slots="slots" wrap />
              <p v-if="errors.fieldErrors.value.slot" class="mt-2 text-sm text-danger">
                {{ errors.fieldErrors.value.slot }}
              </p>
            </div>
          </template>
        </section>

        <!-- Summary -->
        <section
          aria-labelledby="assisted-summary-title"
          class="flex flex-col gap-4 border-t border-line pt-6"
        >
          <h2 id="assisted-summary-title" class="sr-only">Resumen</h2>
          <p v-if="service && chosenWhen" class="flex flex-col gap-0.5">
            <span class="font-medium">{{ service.nombre }}</span>
            <span class="text-sm text-fg-muted tabular-nums">{{ chosenWhen }}</span>
            <span class="tabular-nums"
              >{{ colones(service.precio) }}
              <span class="text-sm text-fg-muted">IVA incluido · sin cobro en línea</span></span
            >
          </p>
          <p v-else class="text-sm text-fg-muted">Elija una hora para continuar.</p>
          <div>
            <BaseButton type="submit" size="large" :loading="submitting" :disabled="submitting"
              >Registrar reserva</BaseButton
            >
          </div>
        </section>
      </form>
    </main>
  </div>
</template>
