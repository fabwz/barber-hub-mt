<script setup lang="ts">
import PreferencesPanel from '@/components/PreferencesPanel.vue'

// «Mi cuenta · Apariencia» (RF-48): the same block as «Preferencias» (theme, text size and motion),
// with the same store. Nothing is sent to the API: the choices live in the browser.
</script>

<template>
  <section aria-labelledby="account-appearance-title">
    <h2 id="account-appearance-title" class="text-xl">Apariencia</h2>
    <PreferencesPanel />
  </section>
</template>
