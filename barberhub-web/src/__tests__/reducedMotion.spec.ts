import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const SRC = join(process.cwd(), 'src')
const css = readFileSync(join(SRC, 'assets', 'styles', 'main.css'), 'utf8')

function sourceFiles(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const path = join(dir, entry.name)
    if (entry.isDirectory()) return entry.name === '__tests__' ? [] : sourceFiles(path)
    return /\.(vue|ts)$/.test(entry.name) ? [path] : []
  })
}

/** The body of the first rule whose selector list contains `selector`. */
function declarations(selector: string): string {
  const start = css.indexOf(selector)
  expect(start, `${selector} not found`).toBeGreaterThan(-1)
  return css.slice(css.indexOf('{', start) + 1, css.indexOf('}', start))
}

describe('reduced motion (AC-04)', () => {
  it('follows the device by default, but not when the person chose «full»', () => {
    const media = css.slice(css.indexOf('@media (prefers-reduced-motion: reduce)'))

    expect(media).toContain(":root:not([data-motion='full'])")
    expect(declarations(":root:not([data-motion='full']) *::after")).toContain('animation: none')
  })

  it('the person can turn it on over a device that does not ask for it', () => {
    const rule = declarations(":root[data-motion='reduce'] *::after")

    expect(rule).toContain('animation: none !important')
    expect(rule).toContain('transition-duration: 0.01ms !important')
    expect(rule).toContain('scroll-behavior: auto !important')
  })

  it('the «reduce» rule is not inside the media query (it applies on any device)', () => {
    const mediaStart = css.indexOf('@media (prefers-reduced-motion: reduce)')
    const mediaEnd = css.indexOf("  :root[data-motion='reduce']", mediaStart)

    expect(mediaEnd).toBeGreaterThan(mediaStart)
    expect(css.slice(mediaStart, mediaEnd).trimEnd().endsWith('}')).toBe(true)
  })

  it('covers the pseudo-elements and the root, not just the elements', () => {
    expect(css).toContain(":root[data-motion='reduce'] *::before")
    expect(css).toContain(":root[data-motion='reduce'],")
  })

  it('no component decides by the device alone with motion-reduce:, which would ignore the choice', () => {
    const offenders = sourceFiles(SRC).filter((file) =>
      /motion-(?:reduce|safe):/.test(readFileSync(file, 'utf8')),
    )

    expect(offenders.map((file) => file.slice(SRC.length + 1))).toEqual([])
  })

  it('the pulse of the skeleton is an animation that the rule above removes', () => {
    const skeleton = readFileSync(join(SRC, 'components', 'SkeletonBlock.vue'), 'utf8')

    expect(skeleton).toContain('animate-pulse')
  })
})
