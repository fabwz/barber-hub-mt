import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { Router } from 'vue-router'

import { api, setTemporaryPasswordHandler, setTokenProvider, setUnauthorizedHandler } from '@/api'
import { useAccountStore } from '@/stores/account'
import { pendingNavigation } from '@/router/guard'
import { useActiveShopStore } from '@/stores/activeShop'

/** The token lives in sessionStorage (never localStorage) under this key. */
export const SESSION_STORAGE_KEY = 'barberhub.session'

/** Name sent as `dispositivo` when asking for a token. */
const DEVICE_NAME = 'web'

export interface SessionPerson {
  email: string
}

export interface RegisterInput {
  name: string
  email: string
  phone: string
  password: string
}

interface StoredSession {
  token: string
  person: SessionPerson | null
}

function readStored(): StoredSession | null {
  try {
    const raw = globalThis.sessionStorage.getItem(SESSION_STORAGE_KEY)
    if (raw === null) return null
    const data: unknown = JSON.parse(raw)
    if (typeof data !== 'object' || data === null) return null
    const { token, person } = data as Record<string, unknown>
    if (typeof token !== 'string' || token === '') return null
    const email =
      typeof person === 'object' && person !== null ? (person as { email?: unknown }).email : null
    return { token, person: typeof email === 'string' ? { email } : null }
  } catch {
    // No storage (blocked, private window): the session lives only in memory.
    return null
  }
}

function writeStored(value: StoredSession | null): void {
  try {
    if (value === null) globalThis.sessionStorage.removeItem(SESSION_STORAGE_KEY)
    else globalThis.sessionStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify(value))
  } catch {
    // Same as above: ignore.
  }
}

export const useSessionStore = defineStore('session', () => {
  const restored = readStored()
  const token = ref<string | null>(restored?.token ?? null)
  const person = ref<SessionPerson | null>(restored?.person ?? null)

  const isAuthenticated = computed(() => token.value !== null)

  /** Creates the account. The contract answers 201 with the account and no token, so no session starts. */
  async function register(input: RegisterInput): Promise<void> {
    await api.POST('/cuentas', {
      body: {
        nombre: input.name,
        correo: input.email,
        telefono: input.phone,
        contrasena: input.password,
        acepta_terminos: true,
        acepta_privacidad: true,
      },
    })
  }

  async function login(email: string, password: string): Promise<void> {
    const { data } = await api.POST('/tokens', {
      body: { correo: email, contrasena: password, dispositivo: DEVICE_NAME },
    })
    if (!data) return
    token.value = data.data.token
    // The token response carries no profile; the email typed is all that is known.
    person.value = { email }
    writeStored({ token: token.value, person: person.value })
  }

  /** Clears the local session only. */
  function clear(): void {
    token.value = null
    person.value = null
    writeStored(null)
    // The chosen shop and the account data belong to the person who is leaving (also on a 401).
    useActiveShopStore().clear()
    useAccountStore().clear()
  }

  /** The email is the login: after the person corrects it, the menu shows the new one. */
  function setEmail(email: string): void {
    if (token.value === null) return
    person.value = { email }
    writeStored({ token: token.value, person: person.value })
  }

  /** Revokes the token in the API when possible; the local session is cleared whatever happens. */
  async function logout(): Promise<void> {
    try {
      if (token.value !== null) await api.DELETE('/tokens/actual')
    } catch {
      // The token may already be invalid or the network down: leaving is still the goal.
    } finally {
      clear()
    }
  }

  return { token, person, isAuthenticated, register, login, logout, clear, setEmail }
})

/** Connects the HTTP client (#74) to the session: bearer token and the 401 handler. */
export function connectSession(router: Router): void {
  const session = useSessionStore()
  setTokenProvider(() => session.token)
  setUnauthorizedHandler(() => {
    session.clear()
    // While the router is still deciding (the guard reads the account), the screen it was going to is the one that counts.
    const target = pendingNavigation()
    const current = router.currentRoute.value
    // Public pages (login, register, legal) need no redirect; this also avoids a loop on login.
    if (target?.isPublic ?? current.meta.public) return
    void router.replace({
      name: 'login',
      query: { redirect: target?.fullPath ?? current.fullPath },
    })
  })
  // A 403 `contrasena-temporal` (UP-04): the only thing left to do is the mandatory change.
  setTemporaryPasswordHandler(() => {
    useAccountStore().markTemporary()
    if (router.currentRoute.value.name !== 'password-change') {
      void router.replace({ name: 'password-change' })
    }
  })
}
