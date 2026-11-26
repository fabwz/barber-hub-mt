<script setup lang="ts">
import { computed, useId } from 'vue'
import { Plus } from 'lucide-vue-next'
import { RouterLink, RouterView, useRoute } from 'vue-router'

import SectionNav, { type Section } from '@/components/SectionNav.vue'
import StaffNavbar from '@/components/StaffNavbar.vue'
import { useVoucherDefinitionsStore } from '@/stores/voucherDefinitions'

// «Bonos» of the owner (RF-26, RF-29; lámina 36): the vouchers the shop sells and the ones its customers bought, as
// two sections that are routes (links with `aria-current`, as in «Equipo»). «Nuevo bono» is here, above both.
const route = useRoute()
const store = useVoucherDefinitionsStore()

const barberia = computed(() => String(route.params.barberia))

const sections = computed<Section[]>(() => {
  const params = { barberia: barberia.value }
  return [
    { label: 'Bonos del negocio', to: { name: 'staff-vouchers', params } },
    { label: 'Bonos vendidos', to: { name: 'staff-vouchers-sold', params } },
  ]
})

const newLink = computed(() => ({
  name: 'staff-voucher-new',
  params: { barberia: barberia.value },
}))
// With the vouchers switched off the API refuses a new one (409): the button says so before the trip (lámina 36e).
const blocked = computed(() => store.vouchersEnabled === false)
const noteId = useId()

const BUTTON_CLASS =
  'inline-flex min-h-11 items-center gap-2 rounded-pill border border-line-strong bg-elevated px-5 text-sm font-medium text-fg no-underline'
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <StaffNavbar />
    <main class="mx-auto w-full max-w-5xl flex-1 px-5 py-6 md:px-8">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 class="text-2xl">Bonos</h1>
          <p class="mt-1 text-sm text-fg-muted">
            Paquetes de sesiones que sus clientes compran por adelantado.
          </p>
        </div>
        <div class="flex flex-col items-start gap-1 md:items-end">
          <button
            v-if="blocked"
            type="button"
            disabled
            :aria-describedby="noteId"
            :class="BUTTON_CLASS"
            class="cursor-not-allowed opacity-50"
          >
            <Plus :size="16" :stroke-width="1.5" aria-hidden="true" />
            Nuevo bono
          </button>
          <RouterLink
            v-else
            :to="newLink"
            :class="BUTTON_CLASS"
            class="transition-colors duration-150 ease-out hover:bg-btn-hover"
          >
            <Plus :size="16" :stroke-width="1.5" aria-hidden="true" />
            Nuevo bono
          </RouterLink>
          <p v-if="blocked" :id="noteId" class="max-w-64 text-sm text-fg-muted md:text-right">
            No puede crear bonos nuevos mientras estén desactivados.
          </p>
        </div>
      </div>
      <div class="mt-5">
        <SectionNav label="Secciones de Bonos" :sections="sections" />
      </div>
      <div class="mt-6">
        <RouterView />
      </div>
    </main>
  </div>
</template>
