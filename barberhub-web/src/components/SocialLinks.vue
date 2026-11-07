<script setup lang="ts">
// The 44 px targets overlap by 8 px (`-space-x-2` in the template) so the icons sit closer together.
import { computed } from 'vue'
import { Mail } from 'lucide-vue-next'

import { BRAND_ICON_PATHS, type BrandNetwork } from '@/composables/brandIcons'
import { validEmail, validSocialUrl } from '@/composables/socialUrl'

interface Props {
  /** Shop name, used in the accessible labels. */
  shopName: string
  whatsapp?: string
  instagram?: string
  facebook?: string
  tiktok?: string
  /** Contact email of the business; shown only when it is a valid address. */
  email?: string
}

const { shopName, whatsapp, instagram, facebook, tiktok, email } = defineProps<Props>()

type Network = BrandNetwork | 'email'

function mailto(value: string | undefined): string | null {
  const address = validEmail(value)
  return address ? `mailto:${address}` : null
}

const links = computed(() => {
  const all: Array<{ network: Network; name: string; value: string | undefined }> = [
    { network: 'email', name: 'Correo', value: email },
    { network: 'whatsapp', name: 'WhatsApp', value: whatsapp },
    { network: 'instagram', name: 'Instagram', value: instagram },
    { network: 'facebook', name: 'Facebook', value: facebook },
    { network: 'tiktok', name: 'TikTok', value: tiktok },
  ]
  return all.flatMap(({ network, name, value }) => {
    const href = network === 'email' ? mailto(value) : validSocialUrl(value)
    return href ? [{ network, href, label: `${name} de ${shopName}` }] : []
  })
})
</script>

<template>
  <ul v-if="links.length > 0" class="flex items-center -space-x-2">
    <li v-for="link in links" :key="link.network">
      <a
        :href="link.href"
        :target="link.network === 'email' ? undefined : '_blank'"
        :rel="link.network === 'email' ? undefined : 'noopener noreferrer'"
        :aria-label="link.label"
        class="grid size-11 place-items-center rounded-full transition-colors duration-150 ease-out hover:bg-surface"
      >
        <Mail v-if="link.network === 'email'" :size="18" :stroke-width="1.5" aria-hidden="true" />
        <!-- Official brand glyphs, filled, in the text color. -->
        <svg
          v-else
          width="18"
          height="18"
          viewBox="0 0 24 24"
          fill="currentColor"
          aria-hidden="true"
        >
          <path :d="BRAND_ICON_PATHS[link.network]" />
        </svg>
      </a>
    </li>
  </ul>
</template>
