<script setup lang="ts">
import ClientNavbar from '@/components/ClientNavbar.vue'
import AccountNav, { type AccountSection } from '@/components/AccountNav.vue'

// «Mi cuenta» (RF-45, RF-40): common to customer, collaborator and owner. One route per section.
// Avisos (#106) and Idioma (#108) join this list when their screens exist: no link to a screen
// that is not there.
const SECTIONS: AccountSection[] = [
  { label: 'Datos', to: { name: 'account-data' } },
  { label: 'Seguridad', to: { name: 'account-security' } },
  { label: 'Apariencia', to: { name: 'account-appearance' } },
]
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <!-- TEMPORARY: the staff bar is not defined yet, so the owner and the collaborator see this one too. -->
    <ClientNavbar />
    <main class="px-5 pt-6 pb-12 md:px-8 md:pt-8">
      <div
        class="mx-auto w-full max-w-7xl md:rounded-card md:border md:border-line md:p-8"
        data-testid="account-card"
      >
        <h1 class="text-2xl">Mi cuenta</h1>
        <div class="mt-5 md:mt-8 md:grid md:grid-cols-[13.75rem_1px_minmax(0,1fr)] md:gap-8">
          <AccountNav :sections="SECTIONS" />
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
