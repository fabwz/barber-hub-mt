<script setup lang="ts">
import { computed } from 'vue'
import { RouterView, useRoute } from 'vue-router'

import SectionNav, { type Section } from '@/components/SectionNav.vue'
import StaffNavbar from '@/components/StaffNavbar.vue'

// «Configuración» (RF-01, RF-08, RF-09; láminas 34 y 35): one section per route, as links with `aria-current`
// (DESIGN.md), under the staff bar.
const route = useRoute()
const barberia = computed(() => String(route.params.barberia))
const sections = computed<Section[]>(() => {
  const params = { barberia: barberia.value }
  return [
    { label: 'Datos públicos', to: { name: 'staff-settings', params } },
    { label: 'Cobro y políticas', to: { name: 'staff-settings-billing', params } },
  ]
})
const subtitle = computed(() =>
  route.name === 'staff-settings-billing'
    ? 'Cómo cobra y cancela su barbería, y qué funciones ofrece.'
    : 'Los datos que sus clientes ven al llegar a su barbería.',
)
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <StaffNavbar />
    <main class="mx-auto w-full max-w-3xl flex-1 px-5 py-6 md:px-8">
      <h1 class="text-2xl">Configuración</h1>
      <p class="mt-1 text-sm text-fg-muted">{{ subtitle }}</p>
      <div class="mt-5">
        <SectionNav label="Secciones de Configuración" :sections="sections" />
      </div>
      <div class="mt-6">
        <RouterView />
      </div>
    </main>
  </div>
</template>
