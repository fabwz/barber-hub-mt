import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, ref } from 'vue'

import { useDeadline } from '@/composables/useDeadline'

const NOW = new Date('2030-03-05T10:00:00-06:00')

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
  vi.setSystemTime(NOW)
})
afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

function inScope<T>(make: () => T): { value: T; stop: () => void } {
  const scope = effectScope()
  return { value: scope.run(make)!, stop: () => scope.stop() }
}

describe('useDeadline', () => {
  it('counts the time left from the clock, as m:ss', async () => {
    const { value } = inScope(() => useDeadline(() => '2030-03-05T10:15:00-06:00'))

    expect(value.formatted.value).toBe('15:00')
    await vi.advanceTimersByTimeAsync(61_000)
    expect(value.formatted.value).toBe('13:59')
    expect(value.expired.value).toBe(false)
  })

  it('warns in the last two minutes and expires at zero', async () => {
    const { value } = inScope(() => useDeadline(() => '2030-03-05T10:03:00-06:00'))
    expect(value.urgent.value).toBe(false)

    await vi.advanceTimersByTimeAsync(61_000)
    expect(value.urgent.value).toBe(true)

    await vi.advanceTimersByTimeAsync(130_000)
    expect(value.remaining.value).toBe(0)
    expect(value.expired.value).toBe(true)
    expect(value.urgent.value).toBe(false)
  })

  it('follows another deadline, and no deadline is no time', () => {
    const moment = ref<string | null>('2030-03-05T10:10:00-06:00')
    const { value } = inScope(() => useDeadline(() => moment.value))
    expect(value.formatted.value).toBe('10:00')

    moment.value = null
    expect(value.remaining.value).toBe(0)
    expect(value.expired.value).toBe(true)
  })

  it('is right again as soon as the tab is visible, even if its timers slept', () => {
    const { value } = inScope(() => useDeadline(() => '2030-03-05T10:15:00-06:00'))

    vi.setSystemTime(new Date('2030-03-05T10:10:00-06:00'))
    expect(value.formatted.value).toBe('15:00')
    document.dispatchEvent(new Event('visibilitychange'))

    expect(value.formatted.value).toBe('5:00')
  })

  it('clears its timer and its listener with its owner', async () => {
    const remove = vi.spyOn(document, 'removeEventListener')
    const { stop } = inScope(() => useDeadline(() => '2030-03-05T10:15:00-06:00'))

    stop()

    expect(vi.getTimerCount()).toBe(0)
    expect(remove).toHaveBeenCalledWith('visibilitychange', expect.any(Function))
  })
})
