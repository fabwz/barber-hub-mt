<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useTemplateRef, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ChevronLeft } from 'lucide-vue-next'

import { isApiError } from '@/api'
import BaseButton from '@/components/BaseButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import StatusLabel from '@/components/StatusLabel.vue'
import WaitlistOfferCard from '@/components/WaitlistOfferCard.vue'
import { dateOf, formatShortDate } from '@/composables/format'
import { shopHeaderProps } from '@/composables/shopHeaderProps'
import { useOfferActions } from '@/composables/useOfferActions'
import { canPayWith, useVouchers } from '@/composables/useVouchers'
import {
  MAX_WAITLIST_ENTRIES,
  useWaitlistEntries,
  type WaitlistEntry,
} from '@/composables/useWaitlist'
import { useOffersStore, type TrackedOffer } from '@/stores/offers'
import { useShopStore } from '@/stores/shop'

type Phase = 'loading' | 'ready' | 'forbidden' | 'not-found' | 'error'

const route = useRoute()
const router = useRouter()
const shopStore = useShopStore()
const offers = useOffersStore()
const actions = useOfferActions()
const vouchers = useVouchers()
const waitlist = useWaitlistEntries()

const enlace = computed(() => String(route.params.enlace))
const shopName = computed(() => shopStore.shop?.profile.nombre ?? '')
const shopId = computed(() => shopStore.shop?.id ?? null)

const phase = ref<Phase>('loading')
const title = useTemplateRef<HTMLElement>('title')

const announcement = computed(() => {
  if (phase.value === 'loading') return 'Cargando sus filas.'
  if (phase.value === 'error') return 'No pudimos cargar sus filas.'
  if (phase.value !== 'ready') return ''
  const total = waitlist.entries.value.length
  if (total === 0) return 'No está en ninguna fila.'
  return `${total} ${total === 1 ? 'fila' : 'filas'}.`
})

function serviceNameOf(id: string): string {
  return shopStore.shop?.services.find((item) => item.id === id)?.nombre ?? 'Servicio'
}

/** «Cualquier barbero» for a row without a preference; the name of the barber when it can be known. */
function barberLabelOf(entry: WaitlistEntry): string {
  if (entry.barbero === null) return 'Cualquier barbero'
  const name = offers.barberOf(entry)
  return name === '' ? 'Barbero elegido' : `con ${name}`
}

/** The offer of a row that has one, if this device knows it. */
function offerOf(entry: WaitlistEntry): TrackedOffer | null {
  if (shopId.value === null) return null
  return offers.offersOf(shopId.value).find((item) => item.offer.fila === entry.id) ?? null
}

/** An offer to answer here, or one whose ending is still to be told; not one this device closed. */
function showsCard(item: TrackedOffer | null): boolean {
  return item !== null && item.phase !== 'paying' && !(item.phase === 'closed' && item.hidden)
}

function canUseVoucher(item: TrackedOffer): boolean {
  return (
    vouchers.result.value === 'ready' &&
    vouchers.mine.value.some((voucher) => canPayWith(voucher, item.offer.servicio))
  )
}

// —— Leaving a row ——
const leaving = ref<WaitlistEntry | null>(null)
const leavingOpen = ref(false)
const leaveBusy = ref(false)
const leaveError = ref<string | null>(null)

function askToLeave(entry: WaitlistEntry): void {
  leaving.value = entry
  leaveError.value = null
  leavingOpen.value = true
}

async function confirmLeave(): Promise<void> {
  const entry = leaving.value
  if (entry === null || shopId.value === null || leaveBusy.value) return
  leaveBusy.value = true
  try {
    await waitlist.leave(shopId.value, entry.id)
    leaveError.value = null
  } catch (caught) {
    leaveError.value = isApiError(caught)
      ? caught.detail
      : 'No pudimos sacarlo de la fila. Intente de nuevo.'
  } finally {
    leavingOpen.value = false
    leaveBusy.value = false
  }
}

function goBack(): void {
  const previous = router.options.history.state.back
  const list = router.resolve({ name: 'waitlist', params: { enlace: enlace.value } }).path
  if (typeof previous === 'string' && !previous.startsWith(list)) router.back()
  else void router.push({ name: 'shop', params: { enlace: enlace.value } })
}

async function readNames(): Promise<void> {
  const id = shopId.value
  if (id === null) return
  await Promise.all(
    waitlist.entries.value.map((entry) =>
      offers.ensureNames(id, entry.servicio, entry.fecha, offers.missesBarberName(entry)),
    ),
  )
}

async function start(): Promise<void> {
  phase.value = 'loading'
  await shopStore.load(enlace.value)
  if (shopStore.status === 'not-found') phase.value = 'not-found'
  else if (shopStore.status !== 'ready' || shopStore.shop === null) phase.value = 'error'
  else {
    const id = shopStore.shop.id
    const [rows, read] = await Promise.all([
      waitlist.load(id),
      offers.refreshShop(id),
      vouchers.load(id, 'mine'),
    ])
    if (rows === 'forbidden' || read === 'forbidden') phase.value = 'forbidden'
    else if (rows === 'error' || read === 'error') phase.value = 'error'
    else phase.value = 'ready'
    void readNames()
  }
  await nextTick()
  title.value?.focus()
}

// An offer that arrives, ends or is answered changes the rows too: they are read again, quietly.
watch(
  () => offers.version,
  async () => {
    if (phase.value !== 'ready' || shopId.value === null) return
    await waitlist.load(shopId.value)
    void readNames()
  },
)

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

    <!-- Desktop: the same header of the shop as the other screens of the customer -->
    <div v-if="shopStore.shop" class="hidden border-b border-line px-8 md:block">
      <ShopHeader
        class="border-b-0!"
        wide
        name-tag="p"
        v-bind="shopHeaderProps(shopStore.shop.profile)"
      />
    </div>

    <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>

    <main
      class="flex flex-1 flex-col px-5 pt-4 pb-8 md:px-8 md:pt-8"
      :aria-busy="phase === 'loading' ? 'true' : undefined"
    >
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
          <h1 ref="title" tabindex="-1" class="w-fit text-2xl outline-offset-4">Lista de espera</h1>
          <p v-if="phase === 'ready'" class="mt-1 text-sm text-fg-muted">
            Tiene {{ waitlist.entries.value.length }} de {{ MAX_WAITLIST_ENTRIES }} filas activas.
          </p>
        </div>

        <!-- Loading -->
        <div v-if="phase === 'loading'" class="mt-8 flex flex-col gap-4">
          <SkeletonBlock shape="text" label="Cargando sus filas" />
          <SkeletonBlock shape="block" :count="2" label="Cargando sus filas" />
        </div>

        <!-- Owner or collaborator -->
        <div v-else-if="phase === 'forbidden'" class="mt-8 border-y border-line">
          <EmptyState
            title="Esta lista es para clientes"
            description="Como parte del equipo de esta barbería, no tiene filas propias aquí."
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
        <ErrorState
          v-else-if="phase === 'error'"
          class="mt-8"
          message="No pudimos cargar sus filas."
          description="Revise su conexión e intente de nuevo."
          @retry="start"
        />

        <!-- Empty -->
        <div v-else-if="waitlist.entries.value.length === 0" class="mt-8">
          <p v-if="leaveError" role="alert" class="mb-4 text-sm text-danger">{{ leaveError }}</p>
          <div class="border-y border-line">
            <EmptyState
              title="No está en ninguna fila"
              description="Cuando no haya espacio para lo que busca, puede anotarse al reservar y le avisamos si se libera uno."
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
        </div>

        <!-- The rows -->
        <div v-else class="mt-8">
          <p v-if="leaveError" role="alert" class="mb-4 text-sm text-danger">{{ leaveError }}</p>
          <ul class="border-t border-line">
            <li
              v-for="entry in waitlist.entries.value"
              :key="entry.id"
              class="flex flex-col gap-4 border-b border-line py-4"
            >
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 flex-col gap-1">
                  <span class="font-medium">{{ serviceNameOf(entry.servicio) }}</span>
                  <span class="text-sm text-fg-muted">{{ barberLabelOf(entry) }}</span>
                  <span class="text-sm">{{ formatShortDate(dateOf(entry.fecha)) }}</span>
                  <StatusLabel
                    :status="entry.estado === 'ofrecida' ? 'waitlist_offered' : 'waitlist_waiting'"
                  />
                </div>
                <BaseButton variant="secondary" @click="askToLeave(entry)">
                  Salir de la fila
                </BaseButton>
              </div>

              <!-- The space offered, with its countdown -->
              <div
                v-if="showsCard(offerOf(entry))"
                class="rounded-card border border-line bg-surface p-4"
              >
                <WaitlistOfferCard
                  :offer="offerOf(entry)!.offer"
                  :phase="offerOf(entry)!.phase"
                  :reason="offerOf(entry)!.reason"
                  :error="offerOf(entry)!.error"
                  :service-name="serviceNameOf(entry.servicio)"
                  :barber-name="offers.barberOf(offerOf(entry)!.offer)"
                  :can-use-voucher="canUseVoucher(offerOf(entry)!)"
                  heading-tag="h2"
                  @accept="actions.accept(offerOf(entry)!.offer.id, false)"
                  @use-voucher="actions.accept(offerOf(entry)!.offer.id, true)"
                  @reject="actions.reject(offerOf(entry)!.offer.id)"
                  @dismiss="offers.dismiss(offerOf(entry)!.offer.id)"
                />
              </div>
              <!-- Accepted: the payment is still to be made before the offer expires -->
              <div
                v-else-if="offerOf(entry)?.phase === 'paying' && offerOf(entry)!.offer.reserva"
                class="flex flex-col items-start gap-3 rounded-card border border-line bg-surface p-4"
              >
                <p class="text-sm">
                  <span class="font-medium">Pago pendiente.</span>
                  {{ ' ' }}
                  <span class="text-fg-muted">
                    Complete el pago antes de que venza la oferta para confirmar su reserva.
                  </span>
                </p>
                <RouterLink
                  :to="{
                    name: 'payment',
                    params: { enlace, reserva: offerOf(entry)!.offer.reserva! },
                  }"
                  class="inline-flex min-h-11 items-center justify-center rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover"
                >
                  Continuar al pago
                </RouterLink>
              </div>
            </li>
          </ul>
        </div>
      </div>
    </main>

    <ConfirmDialog
      v-model="leavingOpen"
      title="¿Salir de la fila?"
      description="Dejará de recibir ofertas para este servicio y este día. Puede anotarse de nuevo si no hay espacio."
      cancel-label="No, seguir en la fila"
      confirm-label="Sí, salir"
      :loading="leaveBusy"
      @confirm="confirmLeave"
    />
  </div>
</template>
