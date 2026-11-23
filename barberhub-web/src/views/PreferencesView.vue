<script setup lang="ts">
import BrandLogo from '@/components/BrandLogo.vue'
import PreferencesLink from '@/components/PreferencesLink.vue'
import PreferencesPanel from '@/components/PreferencesPanel.vue'

// RF-48. Public: it needs no account and never calls the API; the choices live in the browser.
// With a session the router sends the person to «Mi cuenta · Apariencia», which hosts the same block.
</script>

<template>
  <div class="flex min-h-screen flex-col">
    <header class="flex h-16 flex-none items-center justify-between gap-2 px-5 md:px-8">
      <BrandLogo />
      <PreferencesLink />
    </header>
    <main class="px-5 pt-6 pb-12 md:px-8 md:pt-8">
      <div
        class="mx-auto w-full max-w-7xl md:rounded-card md:border md:border-line md:p-8"
        data-testid="preferences-card"
      >
        <h1 class="text-2xl">Preferencias</h1>
        <PreferencesPanel />
      </div>
    </main>
  </div>
</template>
