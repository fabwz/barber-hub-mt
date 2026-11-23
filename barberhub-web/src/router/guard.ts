import type { RouteLocationNormalized, RouteLocationRaw, Router } from 'vue-router'

import { useAccountStore } from '@/stores/account'
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
    /**
     * Needs an active owner/collaborator shop; without one the router sends the person to /negocios.
     * With a `:barberia` parameter the shop of the route must be one of theirs, and it becomes the active one.
     */
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

/** Where the router is going while a guard is still running; a 401 in that window returns there. */
let navigatingTo: { fullPath: string; isPublic: boolean } | null = null

export function pendingNavigation(): { fullPath: string; isPublic: boolean } | null {
  return navigatingTo
}

export function installGuards(router: Router): void {
  router.beforeEach(async (to) => {
    navigatingTo = { fullPath: to.fullPath, isPublic: to.meta.public === true }
    try {
      return await check(to)
    } finally {
      navigatingTo = null
    }
  })
}

async function check(to: RouteLocationNormalized): Promise<RouteLocationRaw | true> {
  const session = useSessionStore()
  if (to.meta.requiresAuth && !session.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (to.meta.guestOnly && session.isAuthenticated) return { path: '/' }
  if (session.isAuthenticated) {
    // A temporary password (UP-04) leaves one screen: the mandatory change. The account is read once
    // per session, on the first navigation (a public page that finds the session expired just closes it).
    const account = useAccountStore()
    await account.ensureLoaded()
    // The read may have ended in a 401, which closes the session.
    if (to.meta.requiresAuth && !session.isAuthenticated) {
      return { name: 'login', query: { redirect: to.fullPath } }
    }
    if (account.temporaryPassword === true && to.name !== 'password-change') {
      return {
        name: 'password-change',
        query: to.fullPath === '/' ? {} : { redirect: to.fullPath },
      }
    }
    if (account.temporaryPassword === false && to.name === 'password-change') return { path: '/' }
  }
  if (to.meta.requiresStaffShop) {
    const shops = useActiveShopStore()
    // After a reload only the stored id is known: the list confirms it.
    if (shops.status !== 'ready') await shops.loadStaffShops()
    // A route of one shop (`/barberias/:barberia/...`) opens only for a shop where the person is
    // owner or collaborator; any other id is the same as having none, and the selector decides.
    const requested = to.params.barberia
    if (typeof requested === 'string') {
      if (!shops.shops.some((shop) => shop.id === requested)) return { path: '/negocios' }
      shops.select(requested)
    }
    if (shops.active === null) return { path: '/negocios' }
  }
  return true
}
