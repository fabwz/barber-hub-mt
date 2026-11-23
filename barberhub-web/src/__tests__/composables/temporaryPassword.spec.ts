import { afterEach, describe, expect, it, vi } from 'vitest'

import { meetsPasswordPolicy } from '@/composables/passwordPolicy'
import {
  TEMPORARY_PASSWORD_LENGTH,
  generateTemporaryPassword,
} from '@/composables/temporaryPassword'

afterEach(() => vi.restoreAllMocks())

describe('generateTemporaryPassword', () => {
  it('always meets the password policy (SE-07)', () => {
    for (let run = 0; run < 500; run++) {
      const password = generateTemporaryPassword()
      expect(password).toHaveLength(TEMPORARY_PASSWORD_LENGTH)
      expect(meetsPasswordPolicy(password), password).toBe(true)
    }
  })

  it('has an uppercase letter, a lowercase letter, a digit and a symbol, even at the minimum length', () => {
    for (let run = 0; run < 200; run++) {
      const password = generateTemporaryPassword(8)
      expect(password).toHaveLength(8)
      expect(password).toMatch(/[A-Z]/)
      expect(password).toMatch(/[a-z]/)
      expect(password).toMatch(/\d/)
      expect(password).toMatch(/[^A-Za-z0-9]/)
    }
  })

  it('avoids the characters that look alike', () => {
    for (let run = 0; run < 200; run++) {
      expect(generateTemporaryPassword()).not.toMatch(/[Il1O0]/)
    }
  })

  it('draws from crypto.getRandomValues and never from Math.random', () => {
    const random = vi.spyOn(Math, 'random')
    const secure = vi.spyOn(crypto, 'getRandomValues')
    generateTemporaryPassword()
    expect(secure).toHaveBeenCalled()
    expect(random).not.toHaveBeenCalled()
  })

  it('makes a different one each time', () => {
    const seen = new Set(Array.from({ length: 50 }, () => generateTemporaryPassword()))
    expect(seen.size).toBe(50)
  })
})
