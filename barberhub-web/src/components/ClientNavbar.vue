<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'

import AccountMenu, { type AccountLink } from '@/components/AccountMenu.vue'
import BrandLogo from '@/components/BrandLogo.vue'
import NavbarLink from '@/components/NavbarLink.vue'
import { useSessionStore } from '@/stores/session'

const router = useRouter()
const session = useSessionStore()

/**
 * Screens that arrive with other issues (profile and preferences). A link shows only when its route
 * exists, so the bar never points to a screen that is not there.
 */
const FUTURE_LINKS = [
  { label: 'Mi perfil', name: 'profile' },
  { label: 'Preferencias', name: 'preferences' },
]

const links = computed<AccountLink[]>(() => [
  { label: 'Barberías', to: { name: 'directory' } },
  ...FUTURE_LINKS.filter((link) => router.hasRoute(link.name)).map((link) => ({
    label: link.label,
    to: { name: link.name },
  })),
])

const signingOut = ref(false)

/** Revokes the token (the session store clears it whatever the API answers) and goes home. */
async function logout(): Promise<void> {
  if (signingOut.value) return
  signingOut.value = true
  try {
    await session.logout()
    await router.replace('/')
  } finally {
    signingOut.value = false
  }
}
</script>

<template>
  <header
    class="relative flex h-16 flex-none items-center justify-between gap-4 border-b border-line px-5 md:px-8"
  >
    <div class="flex min-w-0 items-center md:gap-10">
      <RouterLink
        :to="{ name: 'directory' }"
        aria-label="BarberHub, ir a Barberías"
        class="block rounded-field"
      >
        <BrandLogo :size="45" />
      </RouterLink>
      <nav aria-label="Principal" class="hidden gap-1 md:flex">
        <NavbarLink v-for="link in links" :key="link.label" :to="link.to">{{
          link.label
        }}</NavbarLink>
      </nav>
    </div>
    <AccountMenu
      :name="session.person?.email ?? ''"
      :links="links"
      :signing-out="signingOut"
      @logout="logout"
    />
  </header>
</template>
