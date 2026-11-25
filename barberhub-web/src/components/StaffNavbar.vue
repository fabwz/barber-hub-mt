<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'

import AccountMenu, { type AccountLink } from '@/components/AccountMenu.vue'
import BrandLogo from '@/components/BrandLogo.vue'
import NavbarLink from '@/components/NavbarLink.vue'
import { ROLE_LABELS } from '@/composables/roleLabels'
import { useActiveShopStore } from '@/stores/activeShop'
import { useSessionStore } from '@/stores/session'
import { useAccountStore } from '@/stores/account'

// The bar of the owner and the collaborator (lámina 26 of the design). Every link points to a
// screen of the active shop, and shows only when its route exists (`router.hasRoute`), so the bar
// never leads to a screen that is not there yet. Whoever adds a screen adds its route name here.
const OWNER_ONLY = ['Servicios', 'Equipo', 'Bonos', 'Ingresos', 'Configuración']
const LINKS = [
  { label: 'Agenda', name: 'staff-agenda', match: ['staff-agenda', 'staff-reservation'] },
  { label: 'Servicios', name: 'staff-services', match: ['staff-services'] },
  {
    label: 'Equipo',
    name: 'staff-team',
    match: [
      'staff-team',
      'staff-team-members',
      'staff-team-member-new',
      'staff-team-own-profile',
      'staff-team-member-edit',
    ],
  },
  { label: 'Bonos', name: 'staff-vouchers', match: ['staff-vouchers'] },
  { label: 'Ingresos', name: 'staff-income', match: ['staff-income'] },
  {
    label: 'Configuración',
    name: 'staff-settings',
    match: ['staff-settings', 'staff-settings-billing'],
  },
]

const router = useRouter()
const session = useSessionStore()
const account = useAccountStore()
const shops = useActiveShopStore()

const links = computed<AccountLink[]>(() => {
  const shop = shops.active
  if (shop === null) return []
  return LINKS.filter(
    (link) =>
      router.hasRoute(link.name) && (shop.role === 'dueno' || !OWNER_ONLY.includes(link.label)),
  ).map((link) => ({
    label: link.label,
    to: { name: link.name, params: { barberia: shop.id } },
    match: link.match,
  }))
})

/** The account name when it is known; the session only knows the email. */
const personName = computed(() => account.profile?.name ?? session.person?.email ?? '')

const signingOut = ref(false)

async function logout(): Promise<void> {
  if (signingOut.value) return
  signingOut.value = true
  try {
    // Leave the staff screen first: with the state already clear its links would have no shop to point to.
    await router.replace('/')
    await session.logout()
  } finally {
    signingOut.value = false
  }
}
</script>

<template>
  <header
    class="relative flex min-h-16 flex-none items-center justify-between gap-3 border-b border-line px-5 py-2 md:px-8"
  >
    <div class="flex min-w-0 items-center gap-3 md:gap-10">
      <RouterLink
        v-if="shops.active"
        :to="{ name: 'staff-agenda', params: { barberia: shops.active.id } }"
        aria-label="BarberHub, ir a la agenda"
        class="block flex-none rounded-field"
      >
        <BrandLogo :size="45" />
      </RouterLink>
      <BrandLogo v-else :size="45" />
      <nav aria-label="Principal" class="hidden gap-1 md:flex">
        <NavbarLink v-for="link in links" :key="link.label" :to="link.to" :match="link.match">{{
          link.label
        }}</NavbarLink>
      </nav>
    </div>
    <div class="flex min-w-0 items-center gap-3">
      <!-- The active shop with the role (issue 186); the link shows only when there is more than one. -->
      <div v-if="shops.active" class="flex min-w-0 flex-col items-end text-right">
        <p class="max-w-40 truncate text-sm font-medium md:max-w-64" data-testid="active-shop">
          {{ shops.active.name }}
        </p>
        <p class="text-xs text-fg-muted">
          {{ ROLE_LABELS[shops.active.role] }}
          <template v-if="shops.shops.length > 1">
            ·
            <RouterLink to="/negocios" class="inline-block min-h-6 text-fg underline"
              >Cambiar de barbería</RouterLink
            >
          </template>
        </p>
      </div>
      <AccountMenu
        :name="personName"
        :links="links"
        :signing-out="signingOut"
        :account-link="{ name: 'account-data' }"
        @logout="logout"
      />
    </div>
  </header>
</template>
