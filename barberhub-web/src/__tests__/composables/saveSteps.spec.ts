import { describe, expect, it, vi } from 'vitest'

import { listLabels, partialMessage, runSteps } from '@/composables/saveSteps'

describe('runSteps', () => {
  it('runs the steps in order and reports all saved', async () => {
    const order: string[] = []
    const outcome = await runSteps([
      { label: 'a', run: async () => void order.push('a') },
      { label: 'b', run: async () => void order.push('b') },
    ])
    expect(order).toEqual(['a', 'b'])
    expect(outcome).toEqual({ saved: ['a', 'b'], failed: null, pending: [] })
  })

  it('stops at the first failure and lists what was saved, what failed and what was not tried', async () => {
    const error = new Error('boom')
    const last = vi.fn()
    const outcome = await runSteps([
      { label: 'a', run: async () => {} },
      {
        label: 'b',
        run: async () => {
          throw error
        },
      },
      { label: 'c', run: last },
    ])
    expect(outcome.saved).toEqual(['a'])
    expect(outcome.failed).toEqual({ label: 'b', error })
    expect(outcome.pending).toEqual(['c'])
    expect(last).not.toHaveBeenCalled()
  })

  it('has nothing to do with no steps', async () => {
    expect(await runSteps([])).toEqual({ saved: [], failed: null, pending: [] })
  })
})

describe('partialMessage', () => {
  it('says nothing changed when the first step failed', () => {
    const message = partialMessage({ saved: [], failed: { label: 'a', error: 1 }, pending: ['b'] })
    expect(message).toBe('No se cambió nada. Inténtelo de nuevo.')
  })

  it('names what was saved and what is left', () => {
    const message = partialMessage({
      saved: ['los datos', 'el logo'],
      failed: { label: 'el banner', error: 1 },
      pending: [],
    })
    expect(message).toBe(
      'Se guardó los datos y el logo. Falta guardar el banner. Inténtelo de nuevo.',
    )
  })

  it('is empty when nothing failed and lists labels with commas and «y»', () => {
    expect(partialMessage({ saved: ['a'], failed: null, pending: [] })).toBe('')
    expect(listLabels(['a', 'b', 'c'])).toBe('a, b y c')
    expect(listLabels(['a'])).toBe('a')
  })
})
