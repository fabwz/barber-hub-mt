import { afterEach, describe, expect, it, vi } from 'vitest'

import { pixels, readImageSize, sizeLabel, smallImageProblem } from '@/composables/imageUpload'

afterEach(() => vi.unstubAllGlobals())

describe('image size', () => {
  it('writes the pixels with a dot for the thousands', () => {
    expect(pixels(1200)).toBe('1.200')
    expect(pixels(533)).toBe('533')
    expect(sizeLabel({ width: 1600, height: 533 })).toBe('1.600 × 533 px')
  })

  it('accepts a banner at the minimum and rejects one below it with the message of the design', () => {
    const minimum = { width: 1200, height: 400 }
    expect(smallImageProblem({ width: 1200, height: 400 }, minimum, 'El banner')).toBe('')
    expect(smallImageProblem({ width: 900, height: 300 }, minimum, 'El banner')).toBe(
      'El banner mide 900 × 300 px. Debe medir al menos 1.200 × 400 px.',
    )
    expect(smallImageProblem({ width: 1600, height: 399 }, minimum, 'El banner')).not.toBe('')
    expect(smallImageProblem({ width: 1199, height: 800 }, minimum)).toMatch(/^La imagen mide/)
  })

  it('reads the size with createImageBitmap and releases it', async () => {
    const close = vi.fn()
    vi.stubGlobal('createImageBitmap', async () => ({ width: 1600, height: 533, close }))
    expect(await readImageSize(new Blob(['x']))).toEqual({ width: 1600, height: 533 })
    expect(close).toHaveBeenCalled()
  })

  it('gives null when the browser cannot read it: the API decides', async () => {
    vi.stubGlobal('createImageBitmap', async () => {
      throw new Error('not an image')
    })
    expect(await readImageSize(new Blob(['x']))).toBeNull()
  })
})
