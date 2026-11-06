<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import LocationMenu from '@/components/LocationMenu.vue'
import SocialLinks from '@/components/SocialLinks.vue'
import { validSocialUrl } from '@/composables/socialUrl'

interface Props {
  name: string
  city: string
  phone: string
  /** Street address; hidden while the API does not deliver it. */
  address?: string
  logoUrl?: string
  bannerUrl?: string
  whatsapp?: string
  instagram?: string
  facebook?: string
  tiktok?: string
}

const {
  name,
  city,
  phone,
  address = '',
  logoUrl,
  bannerUrl,
  whatsapp,
  instagram,
  facebook,
  tiktok,
} = defineProps<Props>()

// An image that fails to load falls back to the monogram / the compact header.
const logoFailed = ref(false)
const bannerFailed = ref(false)
watch(
  () => logoUrl,
  () => (logoFailed.value = false),
)
watch(
  () => bannerUrl,
  () => (bannerFailed.value = false),
)

const showLogo = computed(() => !!logoUrl && !logoFailed.value)
const showBanner = computed(() => !!bannerUrl && !bannerFailed.value)

/** Initials of the first two words longer than two letters; falls back to the first letter. */
const initials = computed(() => {
  const words = name
    .split(/\s+/)
    .filter((word) => Array.from(word).length > 2)
    .slice(0, 2)
  const letters = words.map((word) => Array.from(word)[0]!.toUpperCase()).join('')
  return letters || (Array.from(name.trim())[0] ?? '').toUpperCase()
})

const hasAddress = computed(() => address.trim() !== '')
// Without an address the city goes in the same row as the phone.
const showCity = computed(() => !hasAddress.value && city.trim() !== '')
const hasPhone = computed(() => phone.trim() !== '')
const hasSocials = computed(
  () => [whatsapp, instagram, facebook, tiktok].filter((url) => validSocialUrl(url)).length > 0,
)

const telHref = computed(() => `tel:${phone.replace(/[^+\d]/g, '')}`)
const logoSize = computed(() => (showBanner.value ? 'size-18 md:size-22' : 'size-14'))
</script>

<template>
  <header class="border-b border-line">
    <div
      v-if="showBanner"
      class="aspect-2/1 w-full overflow-hidden bg-line md:aspect-3/1"
      aria-hidden="true"
    >
      <img
        :src="bannerUrl"
        alt=""
        width="1200"
        height="600"
        loading="lazy"
        class="size-full object-cover"
        @error="bannerFailed = true"
      />
    </div>
    <div :class="showBanner ? 'pb-2' : 'py-4'">
      <div :class="showBanner ? 'flex flex-col gap-1' : 'flex items-start gap-4'">
        <div
          class="relative z-10 grid flex-none place-items-center overflow-hidden rounded-full border border-line-strong text-fg"
          :class="[
            logoSize,
            showBanner ? '-mt-9 md:-mt-11' : '',
            showLogo ? 'bg-surface' : 'bg-elevated font-medium',
            showBanner ? 'text-2xl' : 'text-lg',
          ]"
        >
          <img
            v-if="showLogo"
            :src="logoUrl"
            :alt="`Logo de ${name}`"
            width="72"
            height="72"
            class="size-full object-cover"
            @error="logoFailed = true"
          />
          <span v-else role="img" :aria-label="`Monograma de ${name}`">{{ initials }}</span>
        </div>
        <div class="flex w-full min-w-0 flex-col">
          <h1 class="text-4xl text-balance">{{ name }}</h1>
          <LocationMenu v-if="hasAddress" :address="address" :city="city" />
          <div
            v-if="showCity || hasPhone || hasSocials"
            class="flex flex-wrap items-center justify-between gap-x-3"
          >
            <div v-if="showCity || hasPhone" class="flex flex-wrap items-center gap-x-4">
              <span
                v-if="showCity"
                class="inline-flex min-h-11 items-center text-sm text-fg-muted"
                >{{ city }}</span
              >
              <a
                v-if="hasPhone"
                :href="telHref"
                class="inline-flex min-h-11 items-center text-sm tabular-nums no-underline"
                >{{ phone }}</a
              >
            </div>
            <SocialLinks
              v-if="hasSocials"
              :shop-name="name"
              :whatsapp="whatsapp"
              :instagram="instagram"
              :facebook="facebook"
              :tiktok="tiktok"
            />
          </div>
        </div>
      </div>
    </div>
  </header>
</template>
