import type { Directive } from 'vue'

type Handler = (event: Event) => void

// One listener per element, so it can be removed when the element goes away.
const listeners = new WeakMap<HTMLElement, (event: Event) => void>()

/**
 * Custom directive: calls the handler when a click lands outside the element.
 * Usage: `<div v-click-outside="close">`. Register it locally with
 * `import { vClickOutside } from '@/composables/vClickOutside'` in `<script setup>`.
 */
export const vClickOutside: Directive<HTMLElement, Handler> = {
  mounted(el, binding) {
    const listener = (event: Event) => {
      const target = event.target
      if (target instanceof Node && el.contains(target)) return
      binding.value(event)
    }
    listeners.set(el, listener)
    document.addEventListener('click', listener)
  },
  beforeUnmount(el) {
    const listener = listeners.get(el)
    if (listener) document.removeEventListener('click', listener)
    listeners.delete(el)
  },
}
