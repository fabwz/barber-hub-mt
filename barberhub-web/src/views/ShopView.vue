<script setup lang="ts">
import { computed, onBeforeUnmount, watch } from 'vue'
import { useRoute } from 'vue-router'

import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import ServiceRow from '@/components/ServiceRow.vue'
import ShopHeader from '@/components/ShopHeader.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import { useShopStore } from '@/stores/shop'

const route = useRoute()
const store = useShopStore()

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

    <div v-else-if="store.status === 'ready' && store.shop" class="px-5 md:px-8">
      <ShopHeader
        :name="store.shop.profile.nombre"
        :city="store.shop.profile.ciudad"
        :phone="store.shop.profile.telefono"
      />
      <section class="max-w-180 py-5 md:py-8" aria-labelledby="services-title">
        <h2 id="services-title" class="text-lg font-medium">Servicios</h2>
        <ul v-if="store.shop.services.length > 0" class="mt-2">
          <ServiceRow
            v-for="service in store.shop.services"
            :key="service.id"
            :name="service.nombre"
            :duration-minutes="service.duracion_minutos"
            :price="service.precio"
            :base="service.base"
            :vat="service.iva"
          />
        </ul>
        <EmptyState
          v-else
          title="Esta barbería aún no tiene servicios."
          description="Vuelva a consultar más tarde."
        />
      </section>
    </div>

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
