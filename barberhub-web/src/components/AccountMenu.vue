<script setup lang="ts">
import { computed, ref, useId } from 'vue'
import { ChevronDown, CircleUser, LogOut, Menu, User, X } from 'lucide-vue-next'

import NavbarLink from '@/components/NavbarLink.vue'
import { accountInitials } from '@/composables/initials'
import { vClickOutside } from '@/composables/vClickOutside'
import type { RouteLocationRaw } from 'vue-router'

export interface AccountLink {
  label: string
  to: RouteLocationRaw
  /** Other routes that also mark this link as the current page. */
  match?: string[]
}

interface Props {
  /** What identifies the person (the session only knows the email). Empty: no data yet. */
  name?: string
  /** The navbar links. In the mobile panel they come first; on desktop they live in the bar. */
  links?: AccountLink[]
  /** The session is being closed. */
  signingOut?: boolean
  /** Where «Mi cuenta» leads. Without it the panel has no such row (the customer bar links it in the bar). */
  accountLink?: RouteLocationRaw
}

const { name = '', links = [], signingOut = false, accountLink } = defineProps<Props>()

const emit = defineEmits<{
  logout: []
}>()

const open = ref(false)
const desktopButton = ref<HTMLButtonElement | null>(null)
const mobileButton = ref<HTMLButtonElement | null>(null)
// The trigger the person used; Escape gives the focus back to it.
let lastTrigger: HTMLButtonElement | null = null
const panelId = useId()

const initials = computed(() => accountInitials(name))
const label = computed(() => (name === '' ? 'Su cuenta' : name))

function toggle(trigger: HTMLButtonElement | null): void {
  lastTrigger = trigger
  open.value = !open.value
}

function close(): void {
  open.value = false
}

function closeAndFocus(): void {
  if (!open.value) return
  close()
  const visible = lastTrigger ?? desktopButton.value
  visible?.focus()
}

const triggerClasses = computed(() => [
  'items-center rounded-pill border text-fg transition-colors duration-150 ease-out hover:border-line-hover hover:bg-surface',
  open.value ? 'border-line-hover bg-elevated' : 'border-line-strong bg-transparent',
])
</script>

<template>
  <!-- `contents`: the root has no box, so the panel is placed against the navbar. -->
  <div v-click-outside="close" class="contents" @keydown.esc="closeAndFocus">
    <button
      ref="desktopButton"
      type="button"
      :aria-expanded="open"
      :aria-controls="open ? panelId : undefined"
      class="hidden min-h-11 gap-2.5 py-0 pr-3 pl-1.25 text-sm font-medium md:flex"
      :class="triggerClasses"
      @click="toggle(desktopButton)"
    >
      <span
        class="grid size-8 flex-none place-items-center rounded-full border border-line-strong bg-elevated text-xs font-medium"
        aria-hidden="true"
      >
        <template v-if="initials !== ''">{{ initials }}</template>
        <User v-else :size="16" :stroke-width="1.5" />
      </span>
      <span class="max-w-40 truncate">{{ label }}</span>
      <ChevronDown
        :size="18"
        :stroke-width="1.5"
        class="flex-none transition-transform duration-150 ease-out"
        :class="open ? 'rotate-180' : ''"
        aria-hidden="true"
      />
    </button>

    <button
      ref="mobileButton"
      type="button"
      aria-label="Menú"
      :aria-expanded="open"
      :aria-controls="open ? panelId : undefined"
      class="grid size-11 place-items-center md:hidden"
      :class="triggerClasses"
      @click="toggle(mobileButton)"
    >
      <X v-if="open" :size="20" :stroke-width="1.5" aria-hidden="true" />
      <Menu v-else :size="20" :stroke-width="1.5" aria-hidden="true" />
    </button>

    <div
      v-if="open"
      :id="panelId"
      class="absolute top-14.5 right-3 left-3 z-10 box-border rounded-card border border-line bg-surface p-2 md:right-8 md:left-auto md:w-62"
    >
      <!-- On desktop the links are in the bar; here they are only for the mobile panel. -->
      <ul class="md:hidden" @click="close">
        <li v-for="link in links" :key="link.label">
          <NavbarLink :to="link.to" :match="link.match" variant="panel">{{
            link.label
          }}</NavbarLink>
        </li>
      </ul>
      <div
        class="mb-2 border-b border-line px-3 pt-3 pb-3 max-md:mt-2 max-md:border-t md:pt-2"
        data-testid="account-identity"
      >
        <p class="text-xs text-fg-muted">Sesión iniciada</p>
        <p v-if="name !== ''" class="mt-0.5 text-sm font-medium break-words">{{ name }}</p>
      </div>
      <RouterLink
        v-if="accountLink"
        :to="accountLink"
        class="flex min-h-12 w-full items-center gap-3 rounded-field px-3 text-base text-fg no-underline transition-colors duration-150 ease-out hover:bg-elevated md:min-h-11 md:text-sm"
        @click="close"
      >
        <CircleUser :size="18" :stroke-width="1.5" class="text-fg-muted" aria-hidden="true" />
        Mi cuenta
      </RouterLink>
      <button
        type="button"
        :disabled="signingOut"
        class="flex min-h-12 w-full items-center gap-3 rounded-field px-3 text-left text-base text-fg transition-colors duration-150 ease-out hover:bg-elevated disabled:cursor-not-allowed disabled:opacity-60 md:min-h-11 md:text-sm"
        @click="emit('logout')"
      >
        <LogOut :size="18" :stroke-width="1.5" class="text-fg-muted" aria-hidden="true" />
        Cerrar sesión
      </button>
    </div>
  </div>
</template>
