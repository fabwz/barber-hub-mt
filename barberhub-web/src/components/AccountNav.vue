<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'

export interface AccountSection {
  label: string
  to: RouteLocationRaw
}

interface Props {
  sections: AccountSection[]
}

defineProps<Props>()
</script>

<template>
  <!-- Links, not tabs: each section is its own route, so the current one is `aria-current`.
       Phone: 44 px pills that wrap onto another line. Desktop: a vertical list. -->
  <nav aria-label="Mi cuenta">
    <ul class="flex flex-wrap gap-2 md:flex-col md:gap-1">
      <li v-for="section in sections" :key="section.label" class="max-md:flex-none">
        <RouterLink v-slot="{ href, navigate, isActive }" :to="section.to" custom>
          <a
            :href="href"
            :aria-current="isActive ? 'page' : undefined"
            class="flex min-h-11 items-center justify-center rounded-pill border px-4 text-sm text-fg no-underline transition-colors duration-150 ease-out hover:bg-surface md:justify-start"
            :class="
              isActive
                ? 'border-line-strong bg-elevated font-medium'
                : 'border-line font-normal md:border-transparent'
            "
            @click="navigate"
          >
            {{ section.label }}
          </a>
        </RouterLink>
      </li>
    </ul>
  </nav>
</template>
