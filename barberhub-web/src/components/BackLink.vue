<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import { ChevronLeft } from 'lucide-vue-next'

interface Props {
  /** Where «Volver» leads. */
  to: RouteLocationRaw
}

// A link with the look of a button (DESIGN.md): no underline, 44 px target and the focus ring of
// the base styles. One root element so the caller's classes reach the link.
defineProps<Props>()
</script>

<template>
  <RouterLink
    :to="to"
    class="inline-flex min-h-11 min-w-11 cursor-pointer items-center gap-1 rounded-pill pr-4 pl-2.5 text-sm font-medium text-fg no-underline transition-colors duration-150 ease-out hover:bg-surface"
  >
    <ChevronLeft :size="18" :stroke-width="1.5" aria-hidden="true" />
    <slot>Volver</slot>
  </RouterLink>
</template>
