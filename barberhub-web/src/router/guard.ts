import type { Router } from 'vue-router'

import { useActiveShopStore } from '@/stores/activeShop'
import { useSessionStore } from '@/stores/session'

declare module 'vue-router' {
  interface RouteMeta {
    /** Needs a session; without one the router sends the person to /ingresar. */
    requiresAuth?: boolean
    /** Reachable without a session. */
    public?: boolean
    /** Only for people without a session (login, register). */
    guestOnly?: boolean
    /** Needs an active owner/collaborator shop; without one the router sends the person to /negocios. */
    requiresStaffShop?: boolean
  }
}

/**
 * Accepts only an internal path that starts with a single «/». Rejects «//host»,
 * «/\host», schemes and domains, so the redirect can never leave the site.
 */
export function safeRedirect(value: unknown): string {
  const candidate = Array.isArray(value) ? value[0] : value
  if (typeof candidate !== 'string') return '/'
  if (!/^\/(?![/\\])/.test(candidate) || /[\u0000-\u001f]/.test(candidate)) return '/'
  return candidate
}

export function installGuards(router: Router): void {
  router.beforeEach(async (to) => {
    const session = useSessionStore()
    if (to.meta.requiresAuth && !session.isAuthenticated) {
      return { name: 'login', query: { redirect: to.fullPath } }
    }
    if (to.meta.guestOnly && session.isAuthenticated) return { path: '/' }
    if (to.meta.requiresStaffShop) {
      const shops = useActiveShopStore()
      // After a reload only the stored id is known: the list confirms it.
      if (shops.status !== 'ready') await shops.loadStaffShops()
      if (shops.active === null) return { path: '/negocios' }
    }
    return true
  })
}
