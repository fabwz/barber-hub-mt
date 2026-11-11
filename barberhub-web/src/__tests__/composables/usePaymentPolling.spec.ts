import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'

import type { Payment } from '@/composables/usePayment'
import { usePaymentPolling } from '@/composables/usePaymentPolling'
import { ApiError } from '@/api'
import { payment } from '../helpers/payment'

const pending = payment() as Payment
const approved = payment({ estado: 'aprobado' }) as Payment

beforeEach(() => vi.useFakeTimers())
afterEach(() => vi.useRealTimers())

function host(options: Parameters<typeof usePaymentPolling>[0]) {
  let exposed!: ReturnType<typeof usePaymentPolling>
  const wrapper = mount(
    defineComponent({
      setup() {
        exposed = usePaymentPolling(options)
        return () => h('div')
      },
    }),
  )
  return { wrapper, polling: exposed }
}

describe('usePaymentPolling', () => {
  it('asks every 3 seconds while the payment is pending', async () => {
    const check = vi.fn().mockResolvedValue(pending)
    host({ active: () => true, check })

    await vi.advanceTimersByTimeAsync(2999)
    expect(check).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1)
    expect(check).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(6000)
    expect(check).toHaveBeenCalledTimes(3)
  })

  it('stops on a final state', async () => {
    const active = ref(true)
    const check = vi.fn(async () => {
      active.value = false
      return approved
    })
    host({ active: () => active.value, check })

    await vi.advanceTimersByTimeAsync(3000)
    await vi.advanceTimersByTimeAsync(30000)

    expect(check).toHaveBeenCalledTimes(1)
  })

  it('stops by itself when the answer is final, even if the owner keeps it active', async () => {
    const check = vi.fn().mockResolvedValue(approved)
    host({ active: () => true, check })

    await vi.advanceTimersByTimeAsync(3000)
    await vi.advanceTimersByTimeAsync(30000)

    expect(check).toHaveBeenCalledTimes(1)
  })

  it('does not ask after the reservation expired', async () => {
    const active = ref(true)
    const check = vi.fn().mockResolvedValue(pending)
    host({ active: () => active.value, check })

    await vi.advanceTimersByTimeAsync(3000)
    expect(check).toHaveBeenCalledTimes(1)
    active.value = false
    await vi.advanceTimersByTimeAsync(30000)

    expect(check).toHaveBeenCalledTimes(1)
  })

  it('clears its timer when the owner unmounts', async () => {
    const check = vi.fn().mockResolvedValue(pending)
    const { wrapper } = host({ active: () => true, check })
    await vi.advanceTimersByTimeAsync(3000)
    expect(vi.getTimerCount()).toBe(1)

    wrapper.unmount()

    expect(vi.getTimerCount()).toBe(0)
    await vi.advanceTimersByTimeAsync(30000)
    expect(check).toHaveBeenCalledTimes(1)
  })

  it('never has two requests in flight', async () => {
    let release: (value: Payment) => void = () => {}
    const check = vi.fn(() => new Promise<Payment>((resolve) => (release = resolve)))
    const { polling } = host({ active: () => true, check })

    await vi.advanceTimersByTimeAsync(9000)
    expect(check).toHaveBeenCalledTimes(1)
    expect(polling.checking.value).toBe(true)
    release(pending)
    await vi.advanceTimersByTimeAsync(0)

    expect(polling.checking.value).toBe(false)
  })

  it('stops on an error and «Verificar ahora» asks again and waits again', async () => {
    const check = vi
      .fn()
      .mockRejectedValueOnce(new ApiError({ status: 503, type: 'servicio-no-disponible' }))
      .mockResolvedValue(pending)
    const { polling } = host({ active: () => true, check })

    await vi.advanceTimersByTimeAsync(3000)
    expect(polling.error.value?.status).toBe(503)
    await vi.advanceTimersByTimeAsync(30000)
    expect(check).toHaveBeenCalledTimes(1)

    await polling.checkNow()
    expect(check).toHaveBeenCalledTimes(2)
    expect(polling.error.value).toBeNull()
    await vi.advanceTimersByTimeAsync(3000)
    expect(check).toHaveBeenCalledTimes(3)
  })

  it('«Verificar ahora» asks even when the timer is not running', async () => {
    const check = vi.fn().mockResolvedValue(pending)
    const { polling } = host({ active: () => false, check })

    await polling.checkNow()

    expect(check).toHaveBeenCalledTimes(1)
  })

  it('gives up the wait after `maxChecks` automatic checks with the payment still pending', async () => {
    const check = vi.fn().mockResolvedValue(pending)
    const { polling } = host({ active: () => true, check, maxChecks: 3 })

    await vi.advanceTimersByTimeAsync(9000)
    expect(check).toHaveBeenCalledTimes(3)
    expect(polling.exhausted.value).toBe(true)
    expect(vi.getTimerCount()).toBe(0)

    await vi.advanceTimersByTimeAsync(30000)
    expect(check).toHaveBeenCalledTimes(3)
  })

  it('after giving up, «Verificar ahora» asks once more without waiting again', async () => {
    const check = vi.fn().mockResolvedValue(pending)
    const { polling } = host({ active: () => true, check, maxChecks: 1 })
    await vi.advanceTimersByTimeAsync(3000)
    expect(polling.exhausted.value).toBe(true)

    await polling.checkNow()

    expect(check).toHaveBeenCalledTimes(2)
    expect(polling.exhausted.value).toBe(true)
    await vi.advanceTimersByTimeAsync(30000)
    expect(check).toHaveBeenCalledTimes(2)
  })

  it('never gives up without `maxChecks`', async () => {
    const check = vi.fn().mockResolvedValue(pending)
    const { polling } = host({ active: () => true, check })

    await vi.advanceTimersByTimeAsync(300_000)

    expect(check).toHaveBeenCalledTimes(100)
    expect(polling.exhausted.value).toBe(false)
  })
})
