<script setup lang="ts">
import { computed, ref, useId } from 'vue'
import { ChevronDown, ExternalLink, MapPin } from 'lucide-vue-next'

import { vClickOutside } from '@/composables/vClickOutside'

interface Props {
  /** Street address. Without it the component renders nothing. */
  address?: string
  city?: string
}

const { address = '', city = '' } = defineProps<Props>()

const open = ref(false)
const button = ref<HTMLButtonElement | null>(null)
const panelId = useId()

const addressLine = computed(() => [address.trim(), city.trim()].filter(Boolean).join(', '))
const links = computed(() => {
  const query = encodeURIComponent(addressLine.value)
  return [
    {
      label: 'Ver en Google Maps',
      href: `https://www.google.com/maps/search/?api=1&query=${query}`,
    },
    { label: 'Cómo llegar', href: `https://www.google.com/maps/dir/?api=1&destination=${query}` },
    { label: 'Abrir en Waze', href: `https://waze.com/ul?q=${query}&navigate=yes` },
  ]
})

function close(): void {
  open.value = false
}

function closeAndFocus(): void {
  if (!open.value) return
  close()
  button.value?.focus()
}
</script>

<template>
  <div
    v-if="address.trim() !== ''"
    v-click-outside="close"
    class="flex flex-col"
    @keydown.esc="closeAndFocus"
  >
    <button
      ref="button"
      type="button"
      :aria-expanded="open"
      :aria-controls="panelId"
      class="-ml-2 inline-flex min-h-11 max-w-full items-center gap-2 self-start rounded-pill py-1.5 pr-3 pl-2 text-left text-sm text-fg-muted transition-colors duration-150 ease-out hover:bg-surface"
      :class="open ? 'bg-surface' : 'bg-transparent'"
      @click="open = !open"
    >
      <MapPin :size="16" :stroke-width="1.5" class="flex-none" aria-hidden="true" />
      <span class="text-pretty">{{ addressLine }}</span>
      <ChevronDown
        :size="16"
        :stroke-width="1.5"
        class="flex-none transition-transform duration-150 ease-out"
        :class="open ? 'rotate-180' : ''"
        aria-hidden="true"
      />
    </button>
    <ul
      v-show="open"
      :id="panelId"
      class="my-1 flex flex-col overflow-hidden rounded-card border border-line bg-surface"
    >
      <li v-for="link in links" :key="link.label" class="not-first:border-t not-first:border-line">
        <a
          :href="link.href"
          target="_blank"
          rel="noopener noreferrer"
          class="flex min-h-11 items-center justify-between gap-3 px-4 text-sm font-medium no-underline transition-colors duration-150 ease-out hover:bg-line"
        >
          {{ link.label }}
          <ExternalLink :size="16" :stroke-width="1.5" class="text-fg-muted" aria-hidden="true" />
        </a>
      </li>
    </ul>
  </div>
</template>
