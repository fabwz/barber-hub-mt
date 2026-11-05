import type { Router } from 'vue-router'

import { useSessionStore } from '@/stores/session'

declare module 'vue-router' {
  interface RouteMeta {
    /** Needs a session; without one the router sends the person to /ingresar. */
    requiresAuth?: boolean
    /** Reachable without a session. */
    public?: boolean
    /** Only for people without a session (login, register). */
    guestOnly?: boolean
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
  router.beforeEach((to) => {
    const session = useSessionStore()
    if (to.meta.requiresAuth && !session.isAuthenticated) {
      return { name: 'login', query: { redirect: to.fullPath } }
    }
    if (to.meta.guestOnly && session.isAuthenticated) return { path: '/' }
    return true
  })
}
