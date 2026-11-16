<script setup lang="ts">
import { ref, watch } from 'vue'
import { ChevronRight } from 'lucide-vue-next'

import { shopInitials } from '@/composables/initials'

interface Props {
  name: string
  city: string
  /** Public link: the card goes to `/b/:enlace`. */
  link: string
  /** Logo address; `null` while the shop has none (the monogram takes its place). */
  logo?: string | null
}

const { name, city, link, logo = null } = defineProps<Props>()

// A logo that fails to load falls back to the monogram.
const logoFailed = ref(false)
watch(
  () => logo,
  () => (logoFailed.value = false),
)
</script>

<template>
  <RouterLink
    :to="{ name: 'shop', params: { enlace: link } }"
    class="flex w-full items-center gap-4 rounded-card border border-line p-4 text-fg no-underline transition-colors duration-150 ease-out hover:border-line-strong hover:bg-surface active:bg-elevated"
  >
    <img
      v-if="logo && !logoFailed"
      :src="logo"
      alt=""
      class="size-12 flex-none rounded-full border border-line-strong object-cover"
      @error="logoFailed = true"
    />
    <span
      v-else
      class="grid size-12 flex-none place-items-center rounded-full border border-line-strong bg-elevated text-sm font-medium"
      aria-hidden="true"
      >{{ shopInitials(name) }}</span
    >
    <span class="block min-w-0 flex-1">
      <span class="block truncate font-medium">{{ name }}</span>
      <span class="block text-sm text-fg-muted">{{ city }}</span>
    </span>
    <ChevronRight
      :size="18"
      :stroke-width="1.5"
      class="flex-none text-fg-subtle"
      aria-hidden="true"
    />
  </RouterLink>
</template>
