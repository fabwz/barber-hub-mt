<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue'

// Only to look at: the box is inert, so nothing in it takes a click, focus or the keyboard, and screen readers skip it.
// The header of the shop as the customer sees it on a desktop screen. The header lays itself out by the width
// of the window, so inside a narrower card it would not fit: it is drawn at the width of the real page and
// shrunk to the card. On a phone the header already uses its own narrow layout and is shown as it is.
const PAGE_WIDTH = 1000
const DESKTOP_FROM = 768

const outer = useTemplateRef<HTMLElement>('outer')
const scale = ref(1)

function measure(): void {
  const width = outer.value?.clientWidth ?? 0
  scale.value =
    width > 0 && window.innerWidth >= DESKTOP_FROM && width < PAGE_WIDTH ? width / PAGE_WIDTH : 1
}

let observer: ResizeObserver | null = null
onMounted(() => {
  measure()
  window.addEventListener('resize', measure)
  if (typeof ResizeObserver === 'function' && outer.value !== null) {
    observer = new ResizeObserver(measure)
    observer.observe(outer.value)
  }
})
onBeforeUnmount(() => {
  window.removeEventListener('resize', measure)
  observer?.disconnect()
})

const innerStyle = computed((): Record<string, string> =>
  scale.value < 1 ? { zoom: String(scale.value), width: `${PAGE_WIDTH}px` } : {},
)
</script>

<template>
  <div
    ref="outer"
    inert
    aria-hidden="true"
    class="overflow-hidden rounded-card border border-line select-none"
  >
    <div class="px-5 md:px-8" :style="innerStyle">
      <slot />
    </div>
  </div>
</template>
