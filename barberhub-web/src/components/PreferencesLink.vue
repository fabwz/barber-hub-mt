<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { SlidersHorizontal } from 'lucide-vue-next'

// The one «Preferencias» link of the headers without a session (home, sign in, sign up, terms and
// privacy). A button-like link (DESIGN.md): on a phone only the icon in a 44 px circle, so it does
// not push «Volver» or the logotype; from 768 px a 44 px pill with the word. The name «Preferencias»
// is always there (visually hidden on a phone). On its own screen it is the current page.
</script>

<template>
  <RouterLink v-slot="{ href, navigate, isExactActive }" :to="{ name: 'preferences' }" custom>
    <a
      :href="href"
      :aria-current="isExactActive ? 'page' : undefined"
      class="grid size-11 flex-none place-items-center rounded-full border border-line-strong text-sm text-fg no-underline transition-colors duration-150 ease-out hover:border-line-hover hover:bg-surface md:inline-flex md:w-auto md:items-center md:rounded-pill md:border-transparent md:px-4"
      :class="isExactActive ? 'bg-elevated font-medium' : ''"
      @click="navigate"
    >
      <SlidersHorizontal :size="20" :stroke-width="1.5" class="md:hidden" aria-hidden="true" />
      <span class="sr-only md:not-sr-only">Preferencias</span>
    </a>
  </RouterLink>
</template>
