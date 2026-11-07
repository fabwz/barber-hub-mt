<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import LocationMenu from '@/components/LocationMenu.vue'
import SocialLinks from '@/components/SocialLinks.vue'
import { validEmail, validSocialUrl } from '@/composables/socialUrl'

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
  email?: string
  /** Shows neutral blocks for the banner and the logo while the API brings no images (example only). */
  preview?: boolean
  /** Wide page (1280 px): the banner is 4:1 on desktop. */
  wide?: boolean
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
  email,
  preview = false,
  wide = false,
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
const showBanner = computed(() => preview || (!!bannerUrl && !bannerFailed.value))

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
  () =>
    [whatsapp, instagram, facebook, tiktok].some((url) => validSocialUrl(url)) ||
    validEmail(email) !== null,
)

const telHref = computed(() => `tel:${phone.replace(/[^+\d]/g, '')}`)
const logoSize = computed(() => (showBanner.value ? 'size-18 md:size-22' : 'size-14'))
</script>

<template>
  <header class="border-b border-line">
    <div
      v-if="showBanner"
      class="banner-notch mt-5 aspect-2/1 w-full overflow-hidden rounded-card bg-line md:mt-8"
      :class="wide ? 'md:aspect-4/1' : 'md:aspect-3/1'"
      aria-hidden="true"
    >
      <img
        v-if="bannerUrl"
        :src="bannerUrl"
        alt=""
        width="1200"
        height="600"
        loading="lazy"
        class="size-full object-cover"
        @error="bannerFailed = true"
      />
    </div>
    <div :class="showBanner ? 'pb-2 md:pb-6' : 'py-4 md:pt-8 md:pb-6'">
      <div
        :class="
          showBanner
            ? 'flex flex-col gap-1 md:items-center'
            : 'flex items-start gap-4 md:flex-col md:items-center md:gap-1'
        "
      >
        <div
          class="relative z-10 grid flex-none place-items-center overflow-hidden rounded-full border text-fg"
          :class="[
            logoSize,
            'border-line-strong',
            showBanner ? '-mt-9 md:-mt-11' : '',
            showLogo || (preview && !logoUrl) ? 'bg-surface' : 'bg-elevated font-medium',
            showBanner ? 'text-2xl' : 'text-lg',
          ]"
        >
          <img
            v-if="showLogo"
            :src="logoUrl"
            :alt="`Logo de ${name}`"
            width="72"
            height="72"
            class="size-full rounded-full object-cover"
            @error="logoFailed = true"
          />
          <span v-else-if="preview && !logoUrl" role="img" :aria-label="`Logo de ${name}`">
            <!-- The picture placeholder of the design: a rounded frame with a hill line. -->
            <svg
              width="24"
              height="24"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.5"
              stroke-linecap="round"
              stroke-linejoin="round"
              class="text-fg-subtle"
              aria-hidden="true"
            >
              <path d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z" />
              <path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21" />
            </svg>
          </span>
          <span v-else role="img" :aria-label="`Monograma de ${name}`">{{ initials }}</span>
        </div>
        <div class="flex w-full min-w-0 flex-col md:items-center">
          <h1 class="text-4xl text-balance md:text-center">{{ name }}</h1>
          <!-- Desktop: phone, address and social links in one centered row (phone and links share the free space). -->
          <div
            v-if="hasAddress || showCity || hasPhone || hasSocials"
            class="flex w-full flex-col md:mt-4 md:max-w-230 md:flex-row md:items-center md:justify-center"
          >
            <LocationMenu
              v-if="hasAddress"
              :address="address"
              :city="city"
              class="md:order-2 md:flex-none"
            />
            <div
              v-if="showCity || hasPhone || hasSocials"
              class="flex flex-wrap items-center justify-between gap-x-3 md:contents"
            >
              <div
                v-if="showCity || hasPhone"
                class="flex flex-wrap items-center gap-x-4 md:order-1 md:justify-center"
                :class="hasAddress ? 'md:flex-1 md:justify-end md:pr-8' : 'md:px-4'"
              >
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
                :email="email"
                class="md:order-3"
                :class="hasAddress ? 'md:flex-1 md:pr-4 md:pl-3.5' : 'md:px-4'"
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  </header>
</template>
