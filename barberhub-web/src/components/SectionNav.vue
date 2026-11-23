<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'

export interface Section {
  label: string
  to: RouteLocationRaw
  /** Current only on its own route (the default). `false`: also inside the routes under it. */
  exact?: boolean
}

interface Props {
  /** Names the group of links for assistive technology, e.g. «Secciones de Equipo». */
  label: string
  sections: Section[]
}

defineProps<Props>()
</script>

<template>
  <!-- Links, not tabs (DESIGN.md): each section is its own route, so the current one is `aria-current`. -->
  <nav :aria-label="label">
    <ul class="flex flex-wrap gap-2">
      <li v-for="section in sections" :key="section.label">
        <RouterLink v-slot="{ href, navigate, isActive, isExactActive }" :to="section.to" custom>
          <a
            :href="href"
            :aria-current="
              (section.exact === false ? isActive : isExactActive) ? 'page' : undefined
            "
            class="flex min-h-11 items-center rounded-pill border px-4 text-sm text-fg no-underline transition-colors duration-150 ease-out hover:bg-surface"
            :class="
              (section.exact === false ? isActive : isExactActive)
                ? 'border-line-strong bg-elevated font-medium'
                : 'border-line'
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
