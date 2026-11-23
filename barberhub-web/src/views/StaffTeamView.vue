<script setup lang="ts">
import { computed } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'

import SectionNav, { type Section } from '@/components/SectionNav.vue'
import StaffNavbar from '@/components/StaffNavbar.vue'

// «Equipo» (RF-04, RF-05): one section per route, as links with `aria-current` (DESIGN.md), under the staff bar.
// «Colaboradores» joins when its route exists (#76, entrega 3): no link to a screen that is not there yet.
const route = useRoute()
const router = useRouter()

const barberia = computed(() => String(route.params.barberia))

const sections = computed<Section[]>(() => {
  const params = { barberia: barberia.value }
  const list: Section[] = []
  if (router.hasRoute('staff-team-members')) {
    list.push({ label: 'Colaboradores', to: { name: 'staff-team-members', params } })
  }
  list.push({ label: 'Horario de la barbería', to: { name: 'staff-team', params } })
  return list
})
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <StaffNavbar />
    <main class="mx-auto w-full max-w-5xl flex-1 px-5 py-6 md:px-8">
      <h1 class="text-2xl">Equipo</h1>
      <p class="mt-1 text-sm text-fg-muted">Administre el horario de la barbería.</p>
      <div class="mt-5">
        <SectionNav label="Secciones de Equipo" :sections="sections" />
      </div>
      <div class="mt-6">
        <RouterView />
      </div>
    </main>
  </div>
</template>
