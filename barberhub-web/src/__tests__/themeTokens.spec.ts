import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const css = readFileSync(join(process.cwd(), 'src', 'assets', 'styles', 'main.css'), 'utf8')

type Tokens = Record<string, string>

/** The `--color-*: #hex;` lines inside the block that starts at `opening`. */
function tokensOf(opening: string): Tokens {
  const start = css.indexOf(opening)
  expect(start, `${opening} not found in main.css`).toBeGreaterThan(-1)
  const block = css.slice(start, css.indexOf('}', start))
  const tokens: Tokens = {}
  for (const match of block.matchAll(/--color-([a-z-]+):\s*(#[0-9a-f]{6});/g)) {
    tokens[match[1]!] = match[2]!
  }
  return tokens
}

const dark = tokensOf('@theme {')
const light = tokensOf(":root[data-theme='light'] {")

function luminance(hex: string): number {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
  const linear = (c: number) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4)
  return 0.2126 * linear(r!) + 0.7152 * linear(g!) + 0.0722 * linear(b!)
}

/** WCAG 2.2 contrast ratio. */
function contrast(a: string, b: string): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (hi! + 0.05) / (lo! + 0.05)
}

// The pairs of DESIGN.md, «Contraste verificado». Text needs 4.5:1; components, focus and field
// borders need 3:1 (WCAG 2.2 AA).
const TEXT = 4.5
const COMPONENT = 3
const PAIRS: Array<[string, string, number]> = [
  ['fg', 'canvas', TEXT],
  ['fg', 'surface', TEXT],
  ['fg', 'elevated', TEXT],
  ['fg-muted', 'canvas', TEXT],
  ['fg-muted', 'surface', TEXT],
  ['fg-muted', 'elevated', TEXT],
  ['fg-subtle', 'canvas', TEXT],
  ['fg-subtle', 'surface', TEXT],
  ['accent', 'canvas', COMPONENT],
  ['accent', 'surface', COMPONENT],
  ['accent', 'elevated', COMPONENT],
  ['accent-hover', 'canvas', TEXT],
  ['danger', 'danger-surface', TEXT],
  ['warning', 'warning-surface', TEXT],
  ['field', 'canvas', COMPONENT],
  ['field', 'surface', COMPONENT],
  ['danger-border', 'canvas', COMPONENT],
  ['danger-border', 'surface', COMPONENT],
  ['fg', 'btn-hover', TEXT],
  ['fg', 'btn-active', TEXT],
]

describe('theme tokens', () => {
  it('the light theme redefines exactly the tokens of the dark one (same names, same roles)', () => {
    expect(Object.keys(light).sort()).toEqual(Object.keys(dark).sort())
    expect(Object.keys(dark)).toHaveLength(19)
  })

  it('the light theme only changes colors, not the dark values', () => {
    expect(dark.canvas).toBe('#161819')
    expect(light.canvas).toBe('#f5f6f6')
    expect(light.accent).toBe('#000f2d')
  })

  it.each(['dark', 'light'] as const)('%s: every verified pair meets WCAG 2.2 AA', (theme) => {
    const tokens = theme === 'dark' ? dark : light
    const failing = PAIRS.filter(
      ([fg, bg, minimum]) => contrast(tokens[fg]!, tokens[bg]!) < minimum,
    ).map(
      ([fg, bg, minimum]) =>
        `${fg} on ${bg}: ${contrast(tokens[fg]!, tokens[bg]!).toFixed(2)} < ${minimum}`,
    )

    expect(failing).toEqual([])
  })

  it('the pairs marked «No usar» stay below the minimum in the dark theme (the rule is kept in both)', () => {
    expect(contrast(dark['fg-subtle']!, dark.elevated!)).toBeLessThan(TEXT)
    expect(contrast(dark.field!, dark.elevated!)).toBeLessThan(COMPONENT)
    expect(contrast(dark['fg-muted']!, dark['btn-hover']!)).toBeLessThan(TEXT)
  })

  it('matches the numbers written in DESIGN.md (one decimal)', () => {
    const round = (n: number) => Math.round(n * 10) / 10
    expect(round(contrast(dark.fg!, dark.canvas!))).toBe(15.6)
    expect(round(contrast(light.fg!, light.canvas!))).toBe(16.5)
    expect(round(contrast(light.accent!, light.canvas!))).toBe(17.5)
    expect(round(contrast(light['accent-hover']!, light.canvas!))).toBe(9.6)
    expect(round(contrast(light.danger!, light['danger-surface']!))).toBe(6.4)
    expect(round(contrast(light.warning!, light['warning-surface']!))).toBe(6)
  })

  it('the corner glow of the page is declared once and its color changes per theme', () => {
    expect(css.match(/radial-gradient\(120% 700px/g)).toHaveLength(1)
    expect(css).toContain('--page-glow: rgba(238, 240, 240, 0.04)')
    expect(css).toContain('--page-glow: rgba(0, 15, 45, 0.05)')
  })
})
