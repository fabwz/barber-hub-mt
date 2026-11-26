import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api } from '@/api'
import { useSessionStore } from '@/stores/session'

export type AccountStatus = 'idle' | 'loading' | 'ready' | 'error'

/** Personal data of the person who has the session (RF-45). */
export interface AccountProfile {
  name: string
  phone: string
  email: string
}

export const useAccountStore = defineStore('account', () => {
  const profile = ref<AccountProfile | null>(null)
  const status = ref<AccountStatus>('idle')
  /**
   * `true` while the password is the temporary one (UP-04); `null` while it is not known yet.
   * The router reads it to send the person to the mandatory change.
   */
  const temporaryPassword = ref<boolean | null>(null)
  /** Whether the person wants the reminders by email (RF-47); `null` until `GET /cuenta/avisos` answers. */
  const remindersEnabled = ref<boolean | null>(null)
  const noticesStatus = ref<AccountStatus>('idle')

  // `clear()` bumps the generation so an answer that arrives after it (a 401, a logout) is dropped.
  let generation = 0
  let pending: Promise<void> | null = null
  let pendingNotices: Promise<void> | null = null

  async function fetchAccount(current: number): Promise<void> {
    try {
      const { data } = await api.GET('/cuenta')
      if (current !== generation) return
      const account = data!.data
      profile.value = {
        name: account.nombre,
        phone: account.telefono ?? '',
        email: account.correo,
      }
      temporaryPassword.value = account.contrasena_temporal
      useSessionStore().setUserId(typeof account.id === 'string' ? account.id : null)
      status.value = 'ready'
    } catch {
      if (current !== generation) return
      status.value = 'error'
    }
  }

  /** Reads the account (GET /cuenta). Never throws: the outcome is `status`. */
  function load(): Promise<void> {
    if (status.value === 'loading' && pending) return pending
    status.value = 'loading'
    const request = fetchAccount(generation).finally(() => {
      if (pending === request) pending = null
    })
    pending = request
    return request
  }

  /** Loads the account only if it has not been read yet; a failed read is tried again. */
  function ensureLoaded(): Promise<void> {
    return status.value === 'ready' ? Promise.resolve() : load()
  }

  /** Corrects name, phone and email (PUT /cuenta). Throws an ApiError, e.g. a 422 on `correo`. */
  async function save(input: AccountProfile): Promise<void> {
    const { data } = await api.PUT('/cuenta', {
      body: { nombre: input.name, telefono: input.phone, correo: input.email },
    })
    const account = data!.data
    profile.value = { name: account.nombre, phone: account.telefono ?? '', email: account.correo }
    temporaryPassword.value = account.contrasena_temporal
    status.value = 'ready'
  }

  /**
   * Changes the password (PUT /cuenta/contrasena, 204). The API keeps this session open and closes
   * the others, so nothing is cleared here; the password is no longer temporary.
   */
  async function changePassword(current: string, next: string): Promise<void> {
    await api.PUT('/cuenta/contrasena', {
      body: { contrasena_actual: current, contrasena_nueva: next },
    })
    temporaryPassword.value = false
  }

  /** The API answered 403 `contrasena-temporal`: the password is still the temporary one. */
  function markTemporary(): void {
    temporaryPassword.value = true
  }

  async function fetchNotices(current: number): Promise<void> {
    try {
      const { data } = await api.GET('/cuenta/avisos')
      if (current !== generation) return
      remindersEnabled.value = data!.data.recordatorios_correo
      noticesStatus.value = 'ready'
    } catch {
      if (current !== generation) return
      noticesStatus.value = 'error'
    }
  }

  /** Reads the notice preferences (GET /cuenta/avisos). Never throws: the outcome is `noticesStatus`. */
  function loadNotices(): Promise<void> {
    if (noticesStatus.value === 'loading' && pendingNotices) return pendingNotices
    noticesStatus.value = 'loading'
    const request = fetchNotices(generation).finally(() => {
      if (pendingNotices === request) pendingNotices = null
    })
    pendingNotices = request
    return request
  }

  /**
   * Turns the reminders on or off (PUT /cuenta/avisos). Nothing changes until the API answers: the value
   * shown is the one it confirms. Throws an ApiError, leaving the previous value in place.
   */
  async function saveReminders(enabled: boolean): Promise<void> {
    const current = generation
    const { data } = await api.PUT('/cuenta/avisos', { body: { recordatorios_correo: enabled } })
    if (current !== generation) return
    remindersEnabled.value = data!.data.recordatorios_correo
    noticesStatus.value = 'ready'
  }

  /** Forgets everything; called when the session closes or the API answers 401. */
  function clear(): void {
    generation += 1
    pending = null
    pendingNotices = null
    profile.value = null
    status.value = 'idle'
    temporaryPassword.value = null
    remindersEnabled.value = null
    noticesStatus.value = 'idle'
  }

  return {
    profile,
    status,
    temporaryPassword,
    remindersEnabled,
    noticesStatus,
    load,
    ensureLoaded,
    save,
    changePassword,
    loadNotices,
    saveReminders,
    markTemporary,
    clear,
  }
})
