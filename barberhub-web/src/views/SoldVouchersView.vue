<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'

import BaseButton from '@/components/BaseButton.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoticeBanner from '@/components/NoticeBanner.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import StatusLabel from '@/components/StatusLabel.vue'
import { dateOf, formatDateWithYear, sessionsLabel } from '@/composables/format'
import { useVoucherDefinitionsStore, type SoldVoucher } from '@/stores/voucherDefinitions'

// «Bonos vendidos» (RF-29; lámina 36m and 36n): the vouchers the customers bought, to read only. Newest first, a page
// at a time with «Mostrar más». The name of the customer is all the API gives of the person.
const route = useRoute()
const store = useVoucherDefinitionsStore()

const shopId = computed(() => String(route.params.barberia))
const hasMore = computed(() => store.soldPage < store.soldLastPage)

onMounted(() => store.loadSold(shopId.value))

function expired(voucher: SoldVoucher): boolean {
  return voucher.estado === 'vencido'
}

/** «3 de 5 sesiones»: the balance of an expired voucher is 0 and says so. */
function balance(voucher: SoldVoucher): string {
  return `${voucher.saldo} de ${sessionsLabel(voucher.sesiones_totales)}`
}

/** «Vence vie 15 ene 2027» or «Venció mar 20 oct 2026». */
function expiry(voucher: SoldVoucher): string {
  if (voucher.vence_en === null) return ''
  return `${expired(voucher) ? 'Venció' : 'Vence'} ${formatDateWithYear(dateOf(voucher.vence_en))}`
}

const ROW_COLUMNS =
  'md:grid-cols-[minmax(0,1.4fr)_minmax(0,1.4fr)_minmax(0,1.2fr)_minmax(0,1.4fr)_6rem]'
</script>

<template>
  <section aria-label="Bonos vendidos" :aria-busy="store.soldStatus === 'loading'">
    <SkeletonBlock
      v-if="
        store.soldStatus === 'idle' || (store.soldStatus === 'loading' && store.sold.length === 0)
      "
      shape="row"
      :count="4"
      label="Cargando los bonos vendidos"
    />
    <ErrorState
      v-else-if="store.soldStatus === 'error'"
      message="No pudimos cargar los bonos vendidos"
      description="Revise su conexión e inténtelo de nuevo."
      @retry="store.loadSold(shopId)"
    />
    <EmptyState
      v-else-if="store.sold.length === 0"
      title="Aún no ha vendido bonos."
      description="Cuando un cliente compre un bono, aparecerá aquí con su saldo y su vencimiento."
    />

    <div v-else class="flex flex-col gap-4">
      <div>
        <div
          aria-hidden="true"
          class="hidden gap-x-4 border-b border-line pb-2 text-sm text-fg-muted md:grid"
          :class="ROW_COLUMNS"
        >
          <span>Cliente</span>
          <span>Bono</span>
          <span>Saldo</span>
          <span>Vencimiento</span>
          <span>Estado</span>
        </div>
        <ul class="flex flex-col" aria-label="Bonos vendidos de la barbería">
          <li
            v-for="voucher in store.sold"
            :key="voucher.id"
            class="grid gap-x-4 gap-y-1 border-b border-line py-4 md:items-center"
            :class="ROW_COLUMNS"
            :data-testid="`sold-${voucher.id}`"
          >
            <p class="min-w-0 font-medium break-words text-fg md:order-1">
              {{ voucher.cliente_nombre }}
            </p>
            <StatusLabel
              :status="expired(voucher) ? 'voucher_expired' : 'voucher_active'"
              class="md:order-5"
            />
            <p class="min-w-0 text-sm break-words md:order-2 md:text-base">{{ voucher.nombre }}</p>
            <div class="flex flex-wrap gap-x-2 text-sm text-fg-muted tabular-nums md:contents">
              <p class="md:order-3 md:text-fg">{{ balance(voucher) }}</p>
              <span class="md:hidden" aria-hidden="true">·</span>
              <p class="md:order-4" :class="expired(voucher) ? '' : 'md:text-fg'">
                {{ expiry(voucher) }}
              </p>
            </div>
          </li>
        </ul>
      </div>

      <NoticeBanner v-if="store.soldMoreFailed" variant="error">
        No pudimos cargar más bonos vendidos. Inténtelo de nuevo.
      </NoticeBanner>
      <div v-if="hasMore">
        <BaseButton
          variant="secondary"
          :loading="store.soldLoadingMore"
          @click="store.loadMoreSold(shopId)"
          >Mostrar más</BaseButton
        >
      </div>
    </div>
  </section>
</template>
