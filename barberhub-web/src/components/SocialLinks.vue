<script setup lang="ts">
import { computed } from 'vue'
import { Facebook, Instagram } from 'lucide-vue-next'

import { validSocialUrl } from '@/composables/socialUrl'

interface Props {
  /** Shop name, used in the accessible labels. */
  shopName: string
  whatsapp?: string
  instagram?: string
  facebook?: string
  tiktok?: string
}

const { shopName, whatsapp, instagram, facebook, tiktok } = defineProps<Props>()

type Network = 'whatsapp' | 'instagram' | 'facebook' | 'tiktok'

const links = computed(() => {
  const all: Array<{ network: Network; name: string; value: string | undefined }> = [
    { network: 'whatsapp', name: 'WhatsApp', value: whatsapp },
    { network: 'instagram', name: 'Instagram', value: instagram },
    { network: 'facebook', name: 'Facebook', value: facebook },
    { network: 'tiktok', name: 'TikTok', value: tiktok },
  ]
  return all.flatMap(({ network, name, value }) => {
    const href = validSocialUrl(value)
    return href ? [{ network, href, label: `${name} de ${shopName}` }] : []
  })
})
</script>

<template>
  <ul v-if="links.length > 0" class="flex items-center">
    <li v-for="link in links" :key="link.network">
      <a
        :href="link.href"
        target="_blank"
        rel="noopener noreferrer"
        :aria-label="link.label"
        class="grid size-11 place-items-center rounded-full transition-colors duration-150 ease-out hover:bg-surface"
      >
        <Instagram
          v-if="link.network === 'instagram'"
          :size="20"
          :stroke-width="1.5"
          aria-hidden="true"
        />
        <Facebook
          v-else-if="link.network === 'facebook'"
          :size="20"
          :stroke-width="1.5"
          aria-hidden="true"
        />
        <!-- WhatsApp and TikTok are not in lucide: own icons, 24 px grid, 1.5 stroke. -->
        <svg
          v-else-if="link.network === 'whatsapp'"
          width="20"
          height="20"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.5"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path d="M3 21l1.65-4.9A9 9 0 1 1 8 19.4L3 21z" />
          <path
            d="M9 10c0 3 2 5 5 5l1.2-1.4a.6.6 0 0 0-.1-.8l-1.4-.8a.6.6 0 0 0-.7.1l-.5.5c-.9-.4-1.7-1.2-2.1-2.1l.5-.5a.6.6 0 0 0 .1-.7l-.8-1.4a.6.6 0 0 0-.8-.1L9 10z"
          />
        </svg>
        <svg
          v-else
          width="20"
          height="20"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.5"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path d="M9 12a4 4 0 1 0 4 4V4a5 5 0 0 0 5 5" />
        </svg>
      </a>
    </li>
  </ul>
</template>
