import { describe, expect, it } from 'vitest'

import { isValidSocialLink, socialMessage, socialProblem } from '@/composables/socialLinks'

describe('social links by network', () => {
  it.each([
    ['whatsapp', 'https://wa.me/50688123456'],
    ['whatsapp', 'https://api.whatsapp.com/send?phone=50688123456'],
    ['whatsapp', 'https://www.wa.me/50688123456'],
    ['instagram', 'https://instagram.com/su_barberia'],
    ['instagram', 'https://www.instagram.com/su_barberia'],
    ['facebook', 'https://facebook.com/su.barberia'],
    ['facebook', 'https://fb.com/su.barberia'],
    ['facebook', 'https://www.facebook.com/su.barberia'],
    ['tiktok', 'https://tiktok.com/@su_barberia'],
    ['tiktok', 'https://www.tiktok.com/@su_barberia'],
  ] as const)('accepts %s: %s', (network, link) => {
    expect(isValidSocialLink(network, link)).toBe(true)
  })

  it.each([
    ['instagram', 'http://instagram.com/x', 'not https'],
    ['instagram', 'instagram.com/x', 'no scheme'],
    ['instagram', 'javascript:alert(1)', 'another scheme'],
    ['instagram', 'https://facebook.com/x', 'another network'],
    ['instagram', 'https://instagram.com.evil.test/x', 'a look-alike domain'],
    ['instagram', 'https://evil.test/instagram.com', 'the domain in the path'],
    ['instagram', 'https://user@instagram.com/x', 'a user'],
    ['instagram', 'https://user:pass@instagram.com/x', 'a password'],
    ['instagram', 'https://instagram.com:8080/x', 'a port'],
    ['instagram', 'https://instagram.com/su barberia', 'a space'],
    ['instagram', 'https://instagram.com/x\\y', 'a backslash'],
    ['whatsapp', 'https://wa.me.evil.test/1', 'a look-alike domain'],
    ['tiktok', `https://tiktok.com/${'a'.repeat(300)}`, 'more than 300 characters'],
  ] as const)('rejects %s: %s (%s)', (network, link) => {
    expect(isValidSocialLink(network, link)).toBe(false)
  })

  it('accepts exactly 300 characters', () => {
    const link = `https://tiktok.com/${'a'.repeat(300 - 'https://tiktok.com/'.length)}`
    expect(link).toHaveLength(300)
    expect(isValidSocialLink('tiktok', link)).toBe(true)
  })

  it('an empty link is fine, and a bad one gets the message of the design', () => {
    expect(socialProblem('instagram', '  ')).toBe('')
    expect(socialProblem('instagram', 'wa.me/1')).toBe(
      'Escriba un enlace de Instagram válido, como https://instagram.com/su_barberia.',
    )
    expect(socialMessage('whatsapp')).toBe(
      'Escriba un enlace de WhatsApp válido, como https://wa.me/50688123456.',
    )
    expect(socialMessage('tiktok')).toBe(
      'Escriba un enlace de TikTok válido, como https://tiktok.com/@su_barberia.',
    )
  })
})
