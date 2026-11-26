import { computed, onScopeDispose, ref } from 'vue'

import { secondsUntil } from '@/composables/format'

/**
 * The time left until a moment (`vence_en`), in whole seconds, read from the clock on every tick
 * instead of counting down: a tab in the background slows its timers, and the figure must still be
 * right when the person comes back. The timer is cleared with the owner, so it never leaks.
 */
export function useDeadline(deadline: () => string | null) {
  const now = ref(Date.now())
  const timer = setInterval(() => {
    now.value = Date.now()
  }, 1000)
  const onVisible = (): void => {
    if (document.visibilityState === 'visible') now.value = Date.now()
  }
  document.addEventListener('visibilitychange', onVisible)
  onScopeDispose(() => {
    clearInterval(timer)
    document.removeEventListener('visibilitychange', onVisible)
  })

  const remaining = computed(() => {
    const value = deadline()
    return value === null ? 0 : secondsUntil(value, now.value)
  })
  const expired = computed(() => remaining.value === 0)
  /** `m:ss`, for example 14:05. */
  const formatted = computed(() => {
    const minutes = Math.floor(remaining.value / 60)
    return `${minutes}:${String(remaining.value % 60).padStart(2, '0')}`
  })
  /** The last two minutes: below this the screen warns once. */
  const urgent = computed(() => remaining.value > 0 && remaining.value <= 120)

  return { remaining, expired, formatted, urgent }
}
