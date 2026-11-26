<script setup lang="ts">
import { computed, onMounted } from 'vue'

import AccountNav, { type AccountSection } from '@/components/AccountNav.vue'
import ClientNavbar from '@/components/ClientNavbar.vue'
import StaffNavbar from '@/components/StaffNavbar.vue'
import { useActiveShopStore } from '@/stores/activeShop'

// The bar depends on one rule: with an active shop (owner or collaborator, confirmed by
// `GET /mis-barberias`) «Mi cuenta» wears the staff bar; without one, the customer bar. A reload
// only knows the stored id, so the list is read only when there is one (a customer asks nothing).
const shops = useActiveShopStore()
onMounted(() => {
  if (shops.status === 'idle' && shops.activeId !== null) void shops.loadStaffShops()
})

// «Mi cuenta» (RF-45, RF-40): common to customer, collaborator and owner. One route per section.
// Avisos (RF-47) is only for the customer: the same rule as the bar above, an active shop means
// staff. While a stored shop is still being confirmed the person is not known yet, so the link
// waits instead of flashing for someone who is staff. Idioma (#108) joins this list when its
// screen exists: no link to a screen that is not there.
const isCustomer = computed(
  () =>
    shops.active === null &&
    !(shops.activeId !== null && (shops.status === 'idle' || shops.status === 'loading')),
)

const sections = computed<AccountSection[]>(() => [
  { label: 'Datos', to: { name: 'account-data' } },
  { label: 'Seguridad', to: { name: 'account-security' } },
  ...(isCustomer.value ? [{ label: 'Avisos', to: { name: 'account-notices' } }] : []),
  { label: 'Apariencia', to: { name: 'account-appearance' } },
])
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <StaffNavbar v-if="shops.active" />
    <ClientNavbar v-else />
    <main class="px-5 pt-6 pb-12 md:px-8 md:pt-8">
      <div
        class="mx-auto w-full max-w-7xl md:rounded-card md:border md:border-line md:p-8"
        data-testid="account-card"
      >
        <h1 class="text-2xl">Mi cuenta</h1>
        <div class="mt-5 md:mt-8 md:grid md:grid-cols-[13.75rem_1px_minmax(0,1fr)] md:gap-8">
          <AccountNav :sections="sections" />
          <div class="hidden self-stretch bg-line md:block" aria-hidden="true" />
          <div
            class="mt-8 min-w-0 border-t border-line pt-6 md:mt-0 md:border-t-0 md:pt-0"
            data-testid="account-panel"
          >
            <RouterView />
          </div>
        </div>
      </div>
    </main>
  </div>
</template>
