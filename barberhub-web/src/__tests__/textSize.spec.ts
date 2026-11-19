import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

import { TEXT_SIZES, TEXT_SIZE_PERCENT } from '@/stores/preferences'

const css = readFileSync(join(process.cwd(), 'src', 'assets', 'styles', 'main.css'), 'utf8')

/** The font-size that main.css gives the root for a data-text-size value. */
function rootFontSize(size: string): string | undefined {
  const rule = new RegExp(String.raw`:root\[data-text-size='${size}'\]\s*\{([^}]*)\}`).exec(css)
  return rule?.[1]?.match(/font-size:\s*([^;]+);/)?.[1]
}

describe('text size (AC-03)', () => {
  it('offers the three levels of the rules: 100 %, 125 % and 150 %', () => {
    expect(TEXT_SIZES).toEqual(['normal', 'large', 'xlarge'])
    expect(TEXT_SIZE_PERCENT).toEqual({ normal: 100, large: 125, xlarge: 150 })
  })

  it('scales the root, so every rem grows, with the percentages of the rules', () => {
    expect(rootFontSize('large')).toBe('125%')
    expect(rootFontSize('xlarge')).toBe('150%')
  })

  it('leaves the normal size alone: it is the size of the browser', () => {
    expect(rootFontSize('normal')).toBeUndefined()
    expect(css).not.toMatch(/html\s*\{[^}]*font-size/)
  })

  it('uses percentages, never pixels, so the size the person chose in the browser still counts', () => {
    expect(css).not.toMatch(/:root\[data-text-size[^}]*font-size:\s*\d+px/)
  })

  it('keeps the banner notch in rem, so it follows the logo that it surrounds', () => {
    const notch = /@utility banner-notch\s*\{[\s\S]*?\n\}/.exec(css)?.[0] ?? ''

    expect(notch).toContain('--notch-radius: 2.625rem')
    expect(notch).not.toMatch(/--notch-(?:radius|x):\s*\d+px/)
  })
})
