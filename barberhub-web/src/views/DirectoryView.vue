<script setup lang="ts">
import { computed } from 'vue'

import BaseButton from '@/components/BaseButton.vue'
import ClientNavbar from '@/components/ClientNavbar.vue'
import ErrorState from '@/components/ErrorState.vue'
import SearchField from '@/components/SearchField.vue'
import ShopCard from '@/components/ShopCard.vue'
import { useDirectory } from '@/composables/useDirectory'

const directory = useDirectory()
const { query, shops, status, total, searched, loadedOnce } = directory

const isLoading = computed(() => status.value === 'loading')
const hasSearch = computed(() => searched.value !== '')
const isEmpty = computed(() => status.value === 'ready' && shops.value.length === 0)
const isGeneralEmpty = computed(() => isEmpty.value && !hasSearch.value)
const isSearchEmpty = computed(() => isEmpty.value && hasSearch.value)
const isList = computed(() => status.value === 'ready' && shops.value.length > 0)
// Nothing to search in an empty directory or when it did not load (unless a search is under way).
const showField = computed(
  () => !isGeneralEmpty.value && !(status.value === 'error' && query.value.trim() === ''),
)
// Only the very first load disables the field: disabling it while a search reloads would drop the focus.
const fieldDisabled = computed(() => isLoading.value && !loadedOnce.value)
const count = computed(() => (total.value === 1 ? '1 barbería' : `${total.value} barberías`))
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <ClientNavbar />
    <main class="px-5 pt-4 pb-12 md:px-8 md:pt-12" :aria-busy="isLoading ? 'true' : undefined">
      <div class="mx-auto max-w-260">
        <h1 class="text-2xl">Barberías</h1>
        <p class="mt-2 text-fg-muted">
          Elija una barbería para ver sus servicios y reservar su cita.
        </p>

        <SearchField
          v-if="showField"
          v-model="query"
          :disabled="fieldDisabled"
          class="mt-6 md:max-w-120"
          @clear="directory.clear"
        />

        <!-- The region exists before its text, so the screen reader announces the count when it appears. -->
        <div role="status">
          <p v-if="isList && hasSearch" class="mt-4 text-sm text-fg-muted">{{ count }}</p>
        </div>

        <ul
          v-if="isLoading"
          class="mt-6 grid grid-cols-1 gap-3 md:grid-cols-3 md:gap-4"
          aria-hidden="true"
          data-testid="directory-skeleton"
        >
          <li
            v-for="index in 6"
            :key="index"
            class="items-center gap-4 rounded-card border border-line p-4"
            :class="index > 4 ? 'hidden md:flex' : 'flex'"
          >
            <div
              class="size-12 flex-none animate-pulse rounded-full bg-line motion-reduce:animate-none"
            />
            <div class="flex flex-1 flex-col gap-2">
              <div
                class="h-5 w-3/5 animate-pulse rounded-field bg-line motion-reduce:animate-none"
              />
              <div
                class="h-4 w-2/5 animate-pulse rounded-field bg-line motion-reduce:animate-none"
              />
            </div>
          </li>
        </ul>

        <ErrorState
          v-else-if="status === 'error'"
          class="mt-8 max-w-140"
          message="No pudimos cargar las barberías."
          description="Revise su conexión e intente de nuevo."
          @retry="directory.retry()"
        />

        <div v-else-if="isGeneralEmpty" class="mt-8 max-w-140 border-y border-line py-6">
          <p class="text-lg font-medium">Aún no hay barberías disponibles.</p>
          <p class="mt-2 text-fg-muted">Cuando haya una disponible, aparece aquí.</p>
        </div>

        <div v-else-if="isSearchEmpty" class="mt-6 max-w-140 border-y border-line py-6">
          <p role="status" class="text-lg font-medium">
            No encontramos barberías con “{{ searched }}”.
          </p>
          <p class="mt-2 text-fg-muted">
            Revise el nombre o la ciudad, o borre la búsqueda para ver todas.
          </p>
          <BaseButton variant="secondary" class="mt-4" @click="directory.clear()"
            >Borrar búsqueda</BaseButton
          >
        </div>

        <template v-else-if="isList">
          <ul class="mt-6 grid grid-cols-1 gap-3 md:grid-cols-3 md:gap-4">
            <li v-for="shop in shops" :key="shop.enlace" class="flex">
              <ShopCard
                :name="shop.nombre"
                :city="shop.ciudad"
                :link="shop.enlace"
                :logo="shop.logo"
              />
            </li>
          </ul>
          <div v-if="directory.hasMore.value" class="mt-6 flex flex-col items-start gap-2">
            <p v-if="directory.moreFailed.value" role="alert" class="text-sm text-danger">
              No pudimos cargar más barberías. Intente de nuevo.
            </p>
            <BaseButton
              variant="secondary"
              :loading="directory.loadingMore.value"
              @click="directory.loadMore()"
              >Ver más</BaseButton
            >
          </div>
        </template>
      </div>
    </main>
  </div>
</template>
