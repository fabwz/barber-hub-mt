<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useTemplateRef } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'

import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import StatusLabel from '@/components/StatusLabel.vue'
import { colones, dateOf, formatShortDate, timeOf } from '@/composables/format'
import { HEADER_EXAMPLE } from '@/composables/shopHeaderExample'
import { statusKeyOf, useMyBookings, type CustomerBooking } from '@/composables/useMyBookings'
import { useShopStore } from '@/stores/shop'

type Phase = 'loading' | 'ready' | 'forbidden' | 'not-found' | 'error'

const route = useRoute()
const router = useRouter()
const shopStore = useShopStore()
const bookings = useMyBookings()

const enlace = computed(() => String(route.params.enlace))
const shopName = computed(() => shopStore.shop?.profile.nombre ?? '')

const phase = ref<Phase>('loading')
const title = useTemplateRef<HTMLElement>('title')

const isEmpty = computed(
  () => bookings.upcoming.value.length === 0 && bookings.past.value.length === 0,
)

const announcement = computed(() => {
  if (phase.value === 'loading') return 'Cargando sus reservas.'
  if (phase.value === 'error') return 'No pudimos cargar sus reservas.'
  if (phase.value !== 'ready') return ''
  if (isEmpty.value) return 'Aún no tiene reservas.'
  const total = bookings.upcoming.value.length + bookings.past.value.length
  return `${total} ${total === 1 ? 'reserva' : 'reservas'}.`
})

/** «jue 5 nov» and «10:30–11:15». */
function whenOf(item: CustomerBooking): { date: string; time: string } {
  return {
    date: formatShortDate(dateOf(item.inicio)),
    time: `${timeOf(item.inicio)}–${timeOf(item.fin)}`,
  }
}

/**
 * The money line of a reservation. Only a temporary one has a figure the API gives for sure (what is
 * due now). A confirmed one reports the whole price as the balance because the API does not subtract
 * the deposit already paid, so it shows the total, not a balance.
 */
function moneyOf(item: CustomerBooking): { label: string; amount: number } | null {
  if (item.estado === 'temporal') return { label: 'A pagar ahora', amount: item.monto_a_pagar }
  if (item.estado === 'confirmada') return { label: 'Total', amount: item.precio }
  return null
}

/**
 * Back to wherever the person was (the confirmation, the shop, a reservation...). With nothing behind
 * (the address was opened directly), back to the shop.
 */
function goBack(): void {
  const previous = router.options.history.state.back
  const list = router.resolve({ name: 'my-bookings', params: { enlace: enlace.value } }).path
  // What is behind must be somewhere else: this list or one of its reservations would be a loop.
  if (typeof previous === 'string' && !previous.startsWith(list)) router.back()
  else void router.push({ name: 'shop', params: { enlace: enlace.value } })
}

async function start(): Promise<void> {
  phase.value = 'loading'
  await shopStore.load(enlace.value)
  if (shopStore.status === 'not-found') phase.value = 'not-found'
  else if (shopStore.status !== 'ready' || shopStore.shop === null) phase.value = 'error'
  else phase.value = await bookings.load(shopStore.shop.id)
  await nextTick()
  title.value?.focus()
}

onMounted(() => void start())
</script>

<template>
  <div class="mx-auto flex min-h-screen w-full max-w-7xl flex-col">
    <!-- Phone: a light header with the way back and the name of the shop -->
    <header class="flex flex-none items-center gap-2 px-5 pt-4 pb-2 md:hidden">
      <button
        type="button"
        class="-ml-3 inline-flex min-h-11 cursor-pointer items-center gap-1 rounded-pill pr-4 pl-2.5 text-sm font-medium text-fg transition-colors duration-150 ease-out hover:bg-surface"
        @click="goBack"
      >
        <ChevronLeft :size="18" :stroke-width="1.5" aria-hidden="true" />
        Volver
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
      :aria-busy="phase === 'loading' ? 'true' : undefined"
    >
      <!-- Desktop: the way back, above the card -->
      <button
        type="button"
        class="mb-4 hidden min-h-11 cursor-pointer items-center gap-1 self-start rounded-pill pr-4 pl-2.5 text-sm font-medium text-fg transition-colors duration-150 ease-out hover:bg-surface md:inline-flex"
        @click="goBack"
      >
        <ChevronLeft :size="18" :stroke-width="1.5" aria-hidden="true" />
        Volver
      </button>

      <div class="md:rounded-card md:border md:border-line md:p-8">
        <div class="md:border-b md:border-line md:pb-6">
          <h1 ref="title" tabindex="-1" class="w-fit text-2xl outline-offset-4">Mis reservas</h1>
        </div>

        <!-- Loading -->
        <div v-if="phase === 'loading'" class="mt-8 md:grid md:grid-cols-2 md:gap-8">
          <div class="flex flex-col gap-6">
            <SkeletonBlock shape="text" label="Cargando sus reservas" />
            <SkeletonBlock shape="block" :count="3" label="Cargando sus reservas" />
          </div>
          <div
            class="hidden flex-col gap-6 md:flex md:border-l md:border-line md:pl-8"
            aria-hidden="true"
          >
            <SkeletonBlock shape="text" label="Cargando sus reservas" />
            <SkeletonBlock shape="block" :count="3" label="Cargando sus reservas" />
          </div>
        </div>

        <!-- Not a customer of this shop (owner or collaborator) -->
        <div v-else-if="phase === 'forbidden'" class="mt-8 border-y border-line">
          <EmptyState
            title="Esta lista es para clientes"
            description="Como parte del equipo de esta barbería, no tiene reservas propias aquí."
          />
        </div>

        <!-- The shop does not exist -->
        <div v-else-if="phase === 'not-found'" class="mt-8 border-y border-line">
          <EmptyState
            title="No encontramos esta barbería"
            description="Revise el enlace o vaya al inicio."
          >
            <template #action>
              <RouterLink
                to="/"
                class="inline-flex min-h-11 items-center justify-center rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover"
              >
                Ir al inicio
              </RouterLink>
            </template>
          </EmptyState>
        </div>

        <!-- Error -->
        <div v-else-if="phase === 'error'" class="mt-8 rounded-card bg-danger-surface">
          <ErrorState
            message="No pudimos cargar sus reservas."
            description="Revise su conexión e intente de nuevo."
            @retry="start"
          />
        </div>

        <!-- Empty -->
        <div v-else-if="isEmpty" class="mt-8 border-y border-line">
          <EmptyState
            title="Aún no tiene reservas"
            description="Cuando reserve una cita, aparece aquí."
          >
            <template #action>
              <RouterLink
                :to="{ name: 'shop', params: { enlace } }"
                class="inline-flex min-h-11 items-center justify-center rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover"
              >
                Reservar
              </RouterLink>
            </template>
          </EmptyState>
        </div>

        <!-- Upcoming and past -->
        <!-- Desktop: upcoming on the left, past on the right, with a divider between them -->
        <div v-else class="md:mt-8 md:grid md:grid-cols-2 md:gap-8">
          <template
            v-for="group in [
              { id: 'upcoming', name: 'Próximas', items: bookings.upcoming.value },
              { id: 'past', name: 'Pasadas', items: bookings.past.value },
            ]"
            :key="group.id"
          >
            <section
              v-if="group.items.length > 0"
              :aria-labelledby="`${group.id}-title`"
              :class="
                group.id === 'past' ? 'md:col-start-2 md:border-l md:border-line md:pl-8' : ''
              "
            >
              <h2
                :id="`${group.id}-title`"
                class="text-lg font-medium md:mt-0"
                :class="group.id === 'upcoming' ? 'mt-8' : 'mt-10'"
              >
                {{ group.name }}
              </h2>
              <ul class="mt-2 border-t border-line">
                <li v-for="item in group.items" :key="item.id" class="border-b border-line">
                  <RouterLink
                    :to="{ name: 'booking-detail', params: { enlace, reserva: item.id } }"
                    class="-mx-4 flex min-h-11 items-center gap-3 p-4 text-fg no-underline transition-colors duration-150 ease-out hover:bg-surface"
                  >
                    <span class="flex min-w-0 flex-1 flex-col gap-1">
                      <span class="font-medium">{{ item.servicio_nombre }}</span>
                      <span class="text-sm text-fg-muted">con {{ item.barbero_nombre }}</span>
                      <span class="text-sm md:hidden">
                        {{ whenOf(item).date }}
                        <span class="ml-2 tabular-nums">{{ whenOf(item).time }}</span>
                      </span>
                      <span class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                        <StatusLabel :status="statusKeyOf(item.estado)" plain />
                        <span v-if="moneyOf(item)" class="text-fg-muted">
                          {{ moneyOf(item)!.label }}
                          <span class="ml-2 text-fg tabular-nums">{{
                            colones(moneyOf(item)!.amount)
                          }}</span>
                        </span>
                      </span>
                    </span>
                    <!-- Desktop: the day and the hour sit apart, on the right -->
                    <span class="hidden flex-none flex-col items-end gap-1 text-sm md:flex">
                      <span>{{ whenOf(item).date }}</span>
                      <span class="tabular-nums">{{ whenOf(item).time }}</span>
                    </span>
                    <ChevronRight
                      :size="18"
                      :stroke-width="1.5"
                      class="flex-none text-fg-subtle"
                      aria-hidden="true"
                    />
                  </RouterLink>
                  <!-- A temporary reservation that still holds its space goes on to pay; an expired one does not -->
                  <div v-if="item.estado === 'temporal'" class="pb-4">
                    <RouterLink
                      :to="{ name: 'payment', params: { enlace, reserva: item.id } }"
                      class="inline-flex min-h-11 items-center justify-center rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover"
                    >
                      Continuar al pago
                    </RouterLink>
                  </div>
                </li>
              </ul>
            </section>
          </template>
        </div>
      </div>
    </main>
  </div>
</template>
