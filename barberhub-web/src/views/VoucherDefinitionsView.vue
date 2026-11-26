<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { Plus } from 'lucide-vue-next'

import { isApiError } from '@/api'
import BaseButton from '@/components/BaseButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import InlineNotice from '@/components/InlineNotice.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import StatusLabel from '@/components/StatusLabel.vue'
import { colones, sessionsLabel } from '@/composables/format'
import { useServiceAdminStore } from '@/stores/services'
import { useVoucherDefinitionsStore, type VoucherDefinition } from '@/stores/voucherDefinitions'

// «Bonos del negocio» (RF-26; lámina 36a to 36e): what the shop sells, on sale or withdrawn. Withdrawing asks first
// and leaves the balances already bought as they were (BO-01); putting it back on sale does not ask.
const route = useRoute()
const store = useVoucherDefinitionsStore()
const services = useServiceAdminStore()

const shopId = computed(() => String(route.params.barberia))
const newLink = computed(() => ({ name: 'staff-voucher-new', params: { barberia: shopId.value } }))
const settingsLink = computed(() => ({
  name: 'staff-settings-billing',
  params: { barberia: shopId.value },
}))

onMounted(() => {
  void store.load(shopId.value)
  // The names of the covered services come from the catalog of the owner: the API lists only their identifiers.
  void services.load(shopId.value)
})

/** The covered services of a voucher by name, in the order of the voucher. */
function coveredNames(voucher: VoucherDefinition): string {
  return voucher.servicios
    .map((id) => services.items.find((service) => service.id === id)?.nombre)
    .filter((name): name is string => name !== undefined)
    .join(', ')
}

const confirming = ref<VoucherDefinition | null>(null)
const confirmOpen = ref(false)
/** The voucher whose sale status is changing right now. */
const changing = ref<string | null>(null)
const done = ref('')
/** The change that failed, to try it again as it was. */
const failed = ref<{ voucher: VoucherDefinition; active: boolean } | null>(null)

function askToWithdraw(voucher: VoucherDefinition): void {
  confirming.value = voucher
  confirmOpen.value = true
}

async function setActive(voucher: VoucherDefinition, active: boolean): Promise<void> {
  changing.value = voucher.id
  done.value = ''
  failed.value = null
  try {
    await store.update(shopId.value, voucher.id, { activo: active })
    done.value = active
      ? `«${voucher.nombre}» volvió a la venta.`
      : `«${voucher.nombre}» se retiró de la venta. Los saldos de quienes lo compraron no cambian.`
  } catch (error) {
    // A 401 already closed the session; anything else is shown with a way to try again.
    if (!(isApiError(error) && error.status === 401)) failed.value = { voucher, active }
  } finally {
    changing.value = null
    confirmOpen.value = false
    confirming.value = null
  }
}

function confirmWithdrawal(): void {
  if (confirming.value !== null) void setActive(confirming.value, false)
}

function retry(): void {
  if (failed.value !== null) void setActive(failed.value.voucher, failed.value.active)
}

// The columns of the desktop list and its header; on a phone each voucher is a card.
const ROW_COLUMNS =
  'md:grid-cols-[minmax(0,1.6fr)_minmax(0,1.1fr)_minmax(0,1fr)_minmax(0,1.6fr)_6rem_auto]'
const NEW_LINK_CLASS =
  'inline-flex min-h-11 items-center gap-2 rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-btn-hover'
const LINK_CLASS =
  'inline-flex min-h-11 items-center rounded-pill border border-line-strong px-5 text-sm text-fg no-underline transition-colors duration-150 ease-out hover:border-line-hover'
</script>

<template>
  <section aria-label="Bonos del negocio" :aria-busy="store.status === 'loading'">
    <div class="flex flex-col gap-4">
      <!-- The vouchers are switched off (lámina 36e): the ones sold stay valid, but nothing new can be made. -->
      <div v-if="store.vouchersEnabled === false" class="flex flex-col items-start gap-3">
        <InlineNotice title="Los bonos están desactivados.">
          Los bonos ya vendidos siguen vigentes, pero no puede crear bonos nuevos ni los clientes
          comprarlos. Actívelos en Configuración, en «Cobro y políticas».
        </InlineNotice>
        <RouterLink :to="settingsLink" :class="LINK_CLASS">Ir a Cobro y políticas</RouterLink>
      </div>
      <NoticeBanner v-if="done">{{ done }}</NoticeBanner>
      <ErrorState
        v-if="failed"
        :message="`No pudimos cambiar la venta de «${failed.voucher.nombre}»`"
        description="Inténtelo de nuevo."
        @retry="retry"
      />

      <SkeletonBlock
        v-if="store.status === 'idle' || (store.status === 'loading' && store.items.length === 0)"
        shape="row"
        :count="4"
        label="Cargando los bonos"
      />
      <ErrorState
        v-else-if="store.status === 'error'"
        message="No pudimos cargar los bonos"
        description="Revise su conexión e inténtelo de nuevo."
        @retry="store.load(shopId)"
      />
      <EmptyState
        v-else-if="store.items.length === 0"
        title="Aún no tiene bonos."
        description="Cree un bono para que sus clientes paguen varias visitas por adelantado."
      >
        <template v-if="store.vouchersEnabled !== false" #action>
          <RouterLink :to="newLink" :class="NEW_LINK_CLASS">
            <Plus :size="16" :stroke-width="1.5" aria-hidden="true" />
            Crear mi primer bono
          </RouterLink>
        </template>
      </EmptyState>

      <div v-else>
        <div
          aria-hidden="true"
          class="hidden gap-x-4 border-b border-line pb-2 text-sm text-fg-muted md:grid"
          :class="ROW_COLUMNS"
        >
          <span>Bono</span>
          <span>Sesiones y vigencia</span>
          <span>Precio</span>
          <span>Servicios cubiertos</span>
          <span>Estado</span>
          <span class="w-56" />
        </div>
        <ul class="flex flex-col" aria-label="Bonos de la barbería">
          <li
            v-for="voucher in store.items"
            :key="voucher.id"
            class="grid gap-x-4 gap-y-1 border-b border-line py-4 md:items-center"
            :class="ROW_COLUMNS"
            :data-testid="`voucher-${voucher.id}`"
          >
            <p class="min-w-0 font-medium break-words text-fg md:order-1">{{ voucher.nombre }}</p>
            <StatusLabel
              :status="voucher.activo ? 'voucher_on_sale' : 'voucher_withdrawn'"
              class="md:order-5"
            />
            <div class="flex flex-wrap gap-x-2 text-sm tabular-nums md:contents">
              <p class="md:order-2">
                {{ sessionsLabel(voucher.sesiones) }} · {{ voucher.vigencia_dias }}
                {{ voucher.vigencia_dias === 1 ? 'día' : 'días' }}
              </p>
              <span class="text-fg-muted md:hidden" aria-hidden="true">·</span>
              <p class="md:order-3">
                {{ colones(voucher.precio) }}
                <span class="block text-fg-muted md:text-sm">IVA incluido</span>
              </p>
            </div>
            <p class="min-w-0 text-sm break-words text-fg-muted md:order-4 md:text-fg">
              <span class="md:sr-only">Cubre: </span>{{ coveredNames(voucher) }}
            </p>
            <div class="mt-2 flex flex-wrap items-center gap-2 md:order-6 md:mt-0">
              <RouterLink
                :to="{
                  name: 'staff-voucher-edit',
                  params: { barberia: shopId, bono: voucher.id },
                }"
                :aria-label="`Editar ${voucher.nombre}`"
                :class="LINK_CLASS"
              >
                Editar
              </RouterLink>
              <BaseButton
                v-if="voucher.activo"
                variant="secondary"
                :loading="changing === voucher.id"
                :disabled="changing !== null"
                :aria-label="`Retirar ${voucher.nombre}`"
                @click="askToWithdraw(voucher)"
                >Retirar</BaseButton
              >
              <BaseButton
                v-else
                variant="secondary"
                :loading="changing === voucher.id"
                :disabled="changing !== null"
                :aria-label="`Volver a vender ${voucher.nombre}`"
                @click="setActive(voucher, true)"
                >Volver a vender</BaseButton
              >
            </div>
          </li>
        </ul>
      </div>
    </div>

    <ConfirmDialog
      v-model="confirmOpen"
      :title="`¿Retirar «${confirming?.nombre ?? ''}» de la venta?`"
      description="Los clientes que ya lo compraron conservan su saldo y su vigencia. Podrá volver a ponerlo a la venta cuando quiera."
      cancel-label="No, mantenerlo"
      confirm-label="Sí, retirar"
      :loading="changing !== null"
      @confirm="confirmWithdrawal"
    />
  </section>
</template>
