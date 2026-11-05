<script setup lang="ts">
interface Props {
  href: string
  /** Opens in a new tab with a safe `rel`. */
  external?: boolean
  /** Marks the link as the current page (`aria-current="page"`). */
  current?: boolean
}

const { href, external = false, current = false } = defineProps<Props>()
</script>

<template>
  <a
    :href="href"
    :target="external ? '_blank' : undefined"
    :rel="external ? 'noopener noreferrer' : undefined"
    :aria-current="current ? 'page' : undefined"
    class="inline-flex min-h-11 items-center text-fg underline transition-colors duration-150 ease-out hover:text-accent-hover"
  >
    <slot />
  </a>
</template>
