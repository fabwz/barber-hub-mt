<script setup lang="ts">
import { computed } from 'vue'

import boneLogo from '@/assets/images/bh-logo-bone.png'
import navyLogo from '@/assets/images/bh-logo-navy.png'
import { usePreferencesStore, type ResolvedTheme } from '@/stores/preferences'

interface Props {
  /** Width in px of the visible mark (the whole mark, without the empty margin of the PNG). */
  size?: number
}

const { size = 56 } = defineProps<Props>()

// The one place where a theme is tied to a file: bone on the dark canvas, navy (the light accent) on the
// light one. The files are not recolored: each is the logotype as delivered.
const LOGO_BY_THEME: Record<ResolvedTheme, string> = { dark: boneLogo, light: navyLogo }

const preferences = usePreferencesStore()
const logo = computed(() => LOGO_BY_THEME[preferences.resolvedTheme])

// Both PNGs are a 2000 px square with transparent margins. Their opaque pixels span the box
// x 489..1697, y 576..1317 (1209 x 742 px); the window below shows exactly that box,
// so the mark is complete, uncropped and flush with the edge of its container.
const SOURCE = 2000
const BOX = { x: 489, y: 576, width: 1209, height: 742 }

const scale = computed(() => size / BOX.width)
const imageSize = computed(() => SOURCE * scale.value)
const windowHeight = computed(() => Math.ceil(BOX.height * scale.value))
const imageStyle = computed(() => ({
  width: `${imageSize.value}px`,
  height: `${imageSize.value}px`,
  left: `${-BOX.x * scale.value}px`,
  top: `${-BOX.y * scale.value}px`,
  maxWidth: 'none',
}))
</script>

<template>
  <span
    class="relative block shrink-0 overflow-hidden"
    :style="{ width: `${size}px`, height: `${windowHeight}px` }"
  >
    <img
      :src="logo"
      alt="BarberHub"
      :width="Math.round(imageSize)"
      :height="Math.round(imageSize)"
      class="absolute"
      :style="imageStyle"
    />
  </span>
</template>
