import { computed, onScopeDispose, ref, watch } from 'vue'

import { isApiError, type ApiError } from '@/api'
import { networkError } from '@/api/errors'
import type { Payment } from '@/composables/usePayment'

export const POLLING_INTERVAL_MS = 3000

interface Options {
  /** True while there is a payment to wait for: pending and the reservation not expired. */
  active: () => boolean
  /** Reads the payment status once. */
  check: () => Promise<Payment | null>
  intervalMs?: number
  /** Automatic checks to make before giving up the wait; without it the wait has no limit. */
  maxChecks?: number
}

/**
 * Asks for the payment status every 3 seconds while `active()` holds. It stops by itself on a final
 * state, on an error (the person asks again), when `maxChecks` automatic checks found it still
 * pending (`exhausted`) and when the owner is disposed, so no timer leaks. Requests never overlap,
 * and a timer tick never asks once `active()` turns false.
 */
export function usePaymentPolling({
  active,
  check,
  intervalMs = POLLING_INTERVAL_MS,
  maxChecks,
}: Options) {
  const checking = ref(false)
  const error = ref<ApiError | null>(null)
  /** The wait ran out and the payment is still pending: it stays under verification. */
  const exhausted = ref(false)
  let timer: ReturnType<typeof setInterval> | null = null
  let inFlight = false
  let automatic = 0

  function stop(): void {
    if (timer !== null) clearInterval(timer)
    timer = null
  }

  async function run(manual: boolean): Promise<void> {
    if (inFlight) return
    if (!manual && !active()) {
      stop()
      return
    }
    inFlight = true
    checking.value = true
    try {
      const result = await check()
      error.value = null
      if (result !== null && result.estado !== 'pendiente') stop()
      else if (!manual) {
        automatic += 1
        if (maxChecks !== undefined && automatic >= maxChecks) exhausted.value = true
      }
    } catch (caught) {
      error.value = isApiError(caught) ? caught : networkError(caught)
      stop()
    } finally {
      inFlight = false
      checking.value = false
    }
  }

  const running = computed(() => active() && error.value === null && !exhausted.value)

  // Another payment starts a new wait.
  watch(active, (on) => {
    if (!on) {
      automatic = 0
      exhausted.value = false
    }
  })

  watch(
    running,
    (on) => {
      if (!on) {
        stop()
      } else if (timer === null) {
        timer = setInterval(() => void run(false), intervalMs)
      }
    },
    { immediate: true },
  )

  /** «Verificar ahora»: asks right away and, if it failed before, starts waiting again. */
  async function checkNow(): Promise<void> {
    error.value = null
    await run(true)
  }

  onScopeDispose(stop)

  return { checking, error, exhausted, checkNow, stop }
}
