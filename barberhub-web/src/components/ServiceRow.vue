<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  name: string
  durationMinutes: number
  /** Colones with the 13 % VAT included. */
  price: number
  /** Price without VAT. */
  base: number
  vat: number
  /** Content only, with no `li`, no border and no height: for a selectable row (OptionCard). */
  bare?: boolean
}

const { durationMinutes, price, base, vat, bare = false } = defineProps<Props>()

/** ₡10.000: dot as thousands separator. */
function colones(amount: number): string {
  return `₡${String(Math.round(amount)).replace(/\B(?=(\d{3})+(?!\d))/g, '.')}`
}

const duration = computed(() => {
  const hours = Math.floor(durationMinutes / 60)
  const minutes = durationMinutes % 60
  if (hours === 0) return `${minutes} min`
  return minutes === 0 ? `${hours} h` : `${hours} h ${minutes} min`
})
</script>

<template>
  <component
    :is="bare ? 'div' : 'li'"
    class="flex items-center gap-4"
    :class="bare ? 'w-full' : 'min-h-16 border-b border-line py-2'"
  >
    <div class="flex min-w-0 flex-1 flex-col">
      <span class="text-base">{{ name }}</span>
      <span class="text-sm font-normal text-fg-muted tabular-nums">{{ duration }}</span>
    </div>
    <div class="flex flex-none flex-col items-end text-sm font-normal tabular-nums">
      <span>{{ colones(price) }}</span>
      <span class="flex gap-x-3 text-xs text-fg-muted">
        <span>IVA incluido: {{ colones(vat) }}</span>
        <span>Base {{ colones(base) }}</span>
      </span>
    </div>
  </component>
</template>
