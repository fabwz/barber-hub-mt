<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'

interface Props {
  to: RouteLocationRaw
  /** `bar` is the pill of the desktop navbar; `panel` is the 48 px row of the mobile menu. */
  variant?: 'bar' | 'panel'
}

const { to, variant = 'bar' } = defineProps<Props>()
</script>

<template>
  <RouterLink v-slot="{ href, navigate, isExactActive }" :to="to" custom>
    <a
      :href="href"
      :aria-current="isExactActive ? 'page' : undefined"
      class="flex items-center no-underline transition-colors duration-150 ease-out"
      :class="[
        variant === 'bar'
          ? 'min-h-11 rounded-pill px-4 text-sm hover:bg-surface'
          : 'min-h-12 rounded-field px-3 text-base hover:bg-elevated',
        isExactActive
          ? 'bg-elevated font-medium text-fg'
          : variant === 'bar'
            ? 'text-fg-muted'
            : 'text-fg',
      ]"
      @click="navigate"
    >
      <slot />
    </a>
  </RouterLink>
</template>
