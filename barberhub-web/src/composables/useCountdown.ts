import { computed, onScopeDispose, ref } from 'vue'

/**
 * Reactive countdown in whole seconds. The timer is cleared when the owning
 * component (or effect scope) is disposed, so it never leaks.
 */
export function useCountdown(initialSeconds = 0) {
  const remaining = ref(0)
  let timer: ReturnType<typeof setInterval> | null = null

  function stop(): void {
    if (timer !== null) clearInterval(timer)
    timer = null
  }

  function start(seconds: number): void {
    stop()
    remaining.value = Math.max(0, Math.ceil(seconds))
    if (remaining.value === 0) return
    timer = setInterval(() => {
      remaining.value -= 1
      if (remaining.value <= 0) {
        remaining.value = 0
        stop()
      }
    }, 1000)
  }

  const active = computed(() => remaining.value > 0)
  /** `m:ss`, for example 14:05. */
  const formatted = computed(() => {
    const minutes = Math.floor(remaining.value / 60)
    const seconds = String(remaining.value % 60).padStart(2, '0')
    return `${minutes}:${seconds}`
  })

  if (initialSeconds > 0) start(initialSeconds)
  onScopeDispose(stop)

  return { remaining, active, formatted, start, stop }
}
