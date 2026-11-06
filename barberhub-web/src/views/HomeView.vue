<script setup lang="ts">
// TEMPORARY: the real home page arrives with the next issues. This only lets
// the session flow (login, register, logout) be tried end to end.
import BaseButton from '@/components/BaseButton.vue'
import { useSessionStore } from '@/stores/session'

const session = useSessionStore()

// TEMPORARY: development-only links to the public shop page. Vite replaces
// import.meta.env.DEV with false in the build, so none of this ships.
const devShops = import.meta.env.DEV ? ['pura-navaja', 'fade-lab', 'don-chepe'] : []
</script>

<template>
  <main class="p-5 md:p-8">
    <h1 class="text-2xl text-fg">BarberHub</h1>
    <div v-if="session.isAuthenticated" class="mt-6 flex flex-col items-start gap-4">
      <p class="text-fg-muted">
        Sesión iniciada<template v-if="session.person"> como {{ session.person.email }}</template
        >.
      </p>
      <BaseButton variant="secondary" @click="session.logout()">Cerrar sesión</BaseButton>
    </div>
    <nav v-else class="mt-6 flex flex-wrap gap-4" aria-label="Acceso">
      <RouterLink
        to="/ingresar"
        class="inline-flex min-h-11 items-center font-medium text-fg underline"
        >Ingresar</RouterLink
      >
      <RouterLink
        to="/registro"
        class="inline-flex min-h-11 items-center font-medium text-fg underline"
        >Crear cuenta</RouterLink
      >
    </nav>
    <nav v-if="devShops.length > 0" class="mt-8" aria-label="Barberías de desarrollo (temporal)">
      <p class="text-sm text-fg-subtle">Temporal, solo en desarrollo</p>
      <ul class="mt-2 flex flex-wrap gap-4">
        <li v-for="shop in devShops" :key="shop">
          <RouterLink
            :to="`/b/${shop}`"
            class="inline-flex min-h-11 items-center font-medium text-fg underline"
            >/b/{{ shop }}</RouterLink
          >
        </li>
      </ul>
    </nav>
  </main>
</template>
