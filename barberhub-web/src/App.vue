<script setup lang="ts">
import OfferPrompt from '@/components/OfferPrompt.vue'
import OfferSkipLink from '@/components/OfferSkipLink.vue'
</script>

<template>
  <!-- «Ir al aviso» comes before everything else; the pop-up itself comes after the screen (#112). -->
  <OfferSkipLink />
  <!-- RegisterView stays alive so the form is not lost when the person reads the legal pages. -->
  <RouterView v-slot="{ Component }">
    <KeepAlive include="RegisterView">
      <component :is="Component" />
    </KeepAlive>
  </RouterView>
  <OfferPrompt />
</template>
