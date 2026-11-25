<script setup lang="ts">
import { computed } from 'vue'

import { OFFER_PROMPT_ID } from '@/composables/useOfferNotices'
import { useOffersStore } from '@/stores/offers'

const offers = useOffersStore()
const visible = computed(() => offers.current !== null)

/** Moves the focus to the pop-up on request; it never moves by itself. */
function go(): void {
  document.getElementById(OFFER_PROMPT_ID)?.focus()
}
</script>

<template>
  <!-- The first stop of the keyboard while an offer is on screen; hidden until it has the focus. -->
  <a
    v-if="visible"
    href="#oferta"
    class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:inline-flex focus:min-h-11 focus:items-center focus:rounded-pill focus:border focus:border-line-strong focus:bg-elevated focus:px-5 focus:text-sm focus:font-medium focus:text-fg focus:no-underline"
    @click.prevent="go"
  >
    Ir al aviso
  </a>
</template>
