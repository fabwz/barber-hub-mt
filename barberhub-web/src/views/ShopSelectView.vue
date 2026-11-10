<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

import BaseButton from '@/components/BaseButton.vue'
import BrandLogo from '@/components/BrandLogo.vue'
import ErrorState from '@/components/ErrorState.vue'
import OptionCard from '@/components/OptionCard.vue'
import OptionGroup from '@/components/OptionGroup.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import { ROLE_LABELS } from '@/composables/roleLabels'
import { useActiveShopStore } from '@/stores/activeShop'

const router = useRouter()
const store = useActiveShopStore()

// No shop is preselected (UP-08): the person has to choose.
const selected = ref<string | null>(null)

const showList = computed(() => store.status === 'ready' && store.shops.length > 1)
const chosenName = computed(() => store.shops.find((shop) => shop.id === selected.value)?.name)
const announcement = computed(() =>
  chosenName.value === undefined ? '' : `Seleccionó ${chosenName.value}.`,
)

/** One shop enters directly, none goes to «/» (a customer is not an error), several show the list. */
async function decide(): Promise<void> {
  if (store.status !== 'ready') return
  if (store.shops.length === 0) {
    await router.replace('/')
  } else if (store.shops.length === 1) {
    store.select(store.shops[0]!.id)
    await router.replace('/')
  }
}

async function load(): Promise<void> {
  if (store.status !== 'ready') await store.loadStaffShops()
  await decide()
}

function submit(): void {
  if (selected.value === null) return
  store.select(selected.value)
  // TEMPORARY: the business panel does not exist yet (#78); until then the destination is «/».
  void router.push('/')
}

onMounted(load)
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <header class="flex h-16 flex-none items-center px-5 md:px-8">
      <BrandLogo />
    </header>
    <main
      class="px-5 pt-4 pb-10 md:px-8 md:pt-12"
      :aria-busy="store.status === 'ready' ? undefined : 'true'"
    >
      <div class="mx-auto w-full max-w-97.5 md:max-w-110">
        <h1 class="text-2xl">Elija una barbería</h1>

        <ErrorState
          v-if="store.status === 'error'"
          class="mt-6 p-0! md:p-0!"
          message="No pudimos cargar sus barberías."
          description="Revise su conexión e intente de nuevo."
          @retry="load"
        />

        <form v-else-if="showList" novalidate class="mt-2" @submit.prevent="submit">
          <p class="text-fg-muted">
            Tiene acceso a más de una. Elija con cuál entrar; puede cambiar de barbería sin cerrar
            sesión.
          </p>
          <OptionGroup v-model="selected" class="mt-6" aria-label="Barbería">
            <OptionCard v-for="shop in store.shops" :key="shop.id" :value="shop.id" variant="row">
              {{ shop.name }}
              <template #description>{{ ROLE_LABELS[shop.role] }}</template>
            </OptionCard>
          </OptionGroup>
          <p class="sr-only" aria-live="polite">{{ announcement }}</p>
          <BaseButton type="submit" size="large" class="mt-8 w-full" :disabled="selected === null">
            Continuar
          </BaseButton>
        </form>

        <!-- Loading, and also the instant before a single or no shop moves on to «/». -->
        <div v-else class="mt-6">
          <SkeletonBlock shape="row" :count="3" label="Cargando sus barberías" />
        </div>
      </div>
    </main>
  </div>
</template>
