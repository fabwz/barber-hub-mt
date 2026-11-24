<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useTemplateRef } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import BackLink from '@/components/BackLink.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import VoucherCard from '@/components/VoucherCard.vue'
import VoucherOfferCard from '@/components/VoucherOfferCard.vue'
import { shopHeaderProps } from '@/composables/shopHeaderProps'
import { useVouchers } from '@/composables/useVouchers'
import { useShopStore } from '@/stores/shop'

type Phase = 'loading' | 'ready' | 'forbidden' | 'not-found' | 'error'

const route = useRoute()
const shopStore = useShopStore()
const vouchers = useVouchers()

const enlace = computed(() => String(route.params.enlace))
const shopName = computed(() => shopStore.shop?.profile.nombre ?? '')

const phase = ref<Phase>('loading')
const title = useTemplateRef<HTMLElement>('title')

const announcement = computed(() => {
  if (phase.value === 'loading') return 'Cargando sus bonos.'
  if (phase.value === 'error') return 'No pudimos cargar sus bonos.'
  if (phase.value !== 'ready') return ''
  return `${vouchers.mine.value.length} ${vouchers.mine.value.length === 1 ? 'bono comprado' : 'bonos comprados'}.`
})

async function start(): Promise<void> {
  phase.value = 'loading'
  await shopStore.load(enlace.value)
  if (shopStore.status === 'not-found') phase.value = 'not-found'
  else if (shopStore.status !== 'ready' || shopStore.shop === null) phase.value = 'error'
  else phase.value = await vouchers.load(shopStore.shop.id)
  await nextTick()
  title.value?.focus()
}

onMounted(() => void start())
</script>

<template>
  <div class="mx-auto flex min-h-screen w-full max-w-7xl flex-col">
    <!-- Phone: a light header with the way back and the name of the shop -->
    <header class="flex flex-none items-center gap-2 px-5 pt-4 pb-2 md:hidden">
      <BackLink :to="{ name: 'shop', params: { enlace } }" class="-ml-3" />
      <span class="ml-auto text-sm font-medium">{{ shopName }}</span>
    </header>

    <!-- Desktop: the same header of the shop as the other booking screens -->
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
      <!-- Desktop: the way back, above the card -->
      <BackLink
        :to="{ name: 'shop', params: { enlace } }"
        class="mb-4 hidden self-start md:inline-flex"
      />

      <div class="md:rounded-card md:border md:border-line md:p-8">
        <div class="md:border-b md:border-line md:pb-6">
          <h1 ref="title" tabindex="-1" class="w-fit text-2xl outline-offset-4">Bonos</h1>
        </div>

        <!-- Loading -->
        <div v-if="phase === 'loading'" class="mt-8 flex flex-col gap-8 md:grid md:grid-cols-2">
          <section aria-label="Mis bonos" class="flex flex-col gap-4">
            <h2 class="text-lg font-medium">Mis bonos</h2>
            <SkeletonBlock shape="block" :count="2" label="Cargando sus bonos" />
          </section>
          <section
            aria-label="Bonos a la venta"
            class="flex flex-col gap-4 md:border-l md:border-line md:pl-8"
          >
            <h2 class="text-lg font-medium">Bonos a la venta</h2>
            <SkeletonBlock shape="block" :count="2" label="Cargando los bonos a la venta" />
          </section>
        </div>

        <!-- Owner or collaborator: nothing is offered to them -->
        <div v-else-if="phase === 'forbidden'" class="mt-8 border-y border-line">
          <EmptyState
            title="Esta lista es para clientes"
            description="Como parte del equipo de esta barbería, no tiene bonos propios aquí."
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
          message="No pudimos cargar sus bonos."
          description="Revise su conexión e intente de nuevo."
          @retry="start"
        />

        <!-- Mine and on sale: side by side on desktop, with a divider -->
        <div v-else class="mt-8 flex flex-col gap-8 md:grid md:grid-cols-2">
          <section aria-labelledby="mine-title" class="flex min-w-0 flex-col gap-4">
            <h2 id="mine-title" class="text-lg font-medium">Mis bonos</h2>
            <ul v-if="vouchers.mine.value.length > 0" class="flex flex-col gap-3">
              <VoucherCard v-for="item in vouchers.mine.value" :key="item.id" :voucher="item" />
            </ul>
            <div v-else class="border-y border-line">
              <EmptyState
                title="Aún no tiene bonos"
                description="Cuando compre uno, aparece aquí con sus sesiones y su vencimiento."
              />
            </div>
          </section>

          <section
            aria-labelledby="sale-title"
            class="flex min-w-0 flex-col gap-4 md:border-l md:border-line md:pl-8"
          >
            <h2 id="sale-title" class="text-lg font-medium">Bonos a la venta</h2>
            <!-- Vouchers turned off: nothing to buy; the ones already bought stay visible and usable -->
            <div v-if="!vouchers.enabled.value" class="border-y border-line">
              <EmptyState
                title="Esta barbería no ofrece bonos por ahora"
                description="No puede comprar bonos aquí. Los que ya compró siguen visibles y puede usarlos."
              />
            </div>
            <!-- No vouchers on the shelf, or a shop that does not charge (no gateway): nothing can be bought -->
            <div
              v-else-if="vouchers.onSale.value.length === 0 || vouchers.gateway.value === null"
              class="border-y border-line"
            >
              <EmptyState
                title="Esta barbería no tiene bonos a la venta"
                description="Cuando publique uno, aparece aquí."
              />
            </div>
            <template v-else>
              <ul class="flex flex-col gap-3">
                <VoucherOfferCard
                  v-for="item in vouchers.onSale.value"
                  :key="item.id"
                  :voucher="item"
                  :to="{ name: 'voucher-purchase', params: { enlace, bono: item.id } }"
                />
              </ul>
              <p class="text-sm text-fg-subtle">
                Los bonos solo sirven en {{ shopName }}. Las sesiones que no use antes del
                vencimiento se pierden.
              </p>
            </template>
          </section>
        </div>
      </div>
    </main>
  </div>
</template>
