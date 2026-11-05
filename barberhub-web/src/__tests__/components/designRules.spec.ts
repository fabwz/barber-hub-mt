import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, it, expect } from 'vitest'

const COMPONENTS_DIR = join(process.cwd(), 'src', 'components')

// Rules from DESIGN.md. Font weight 500 (`font-medium`) and 400 (`font-normal`) are the only
// weights the system allows, so every other `font-<weight>` utility is forbidden.
const RULES: Array<[string, RegExp]> = [
  ['shadow', /shadow/],
  ['gradient', /gradient|bg-(?:linear|radial|conic)|(?<=[\s"'`])(?:from|via|to)-[a-z]/],
  ['hexadecimal color', /#[0-9a-fA-F]{3,8}\b/],
  ['border wider than 1 px', /\bborder(?:-[xytblrse])?-(?:2|4|8)\b|\bborder-\[/],
  [
    'font weight other than 400 and 500',
    /\bfont-(?:thin|extralight|light|semibold|bold|extrabold|black|\[)/,
  ],
  [
    'radius outside pill, card, field and circle',
    /\brounded(?:-[trbl]{1,2})?-(?:none|xs|sm|md|lg|xl|2xl|3xl|4xl|\[)/,
  ],
  ['arbitrary value', /\b[a-z-]+-\[[^\]]+\]/],
  [
    'palette color outside the tokens',
    /\b(?:bg|text|border|ring|fill|stroke|outline|divide)-(?:white|black|(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3})\b/,
  ],
]

function sourceFiles(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) =>
    entry.isDirectory()
      ? sourceFiles(join(dir, entry.name))
      : /\.(vue|ts)$/.test(entry.name)
        ? [join(dir, entry.name)]
        : [],
  )
}

describe('design rules (DESIGN.md)', () => {
  const files = sourceFiles(COMPONENTS_DIR)

  it('finds the components to check', () => {
    expect(files.length).toBeGreaterThanOrEqual(11)
  })

  it.each(RULES)('no component has %s', (_name, pattern) => {
    const offenders = files
      .filter((file) => pattern.test(readFileSync(file, 'utf8')))
      .map((file) => file.slice(COMPONENTS_DIR.length + 1))

    expect(offenders).toEqual([])
  })

  it('catches what it should (self-check of the patterns)', () => {
    const bad = [
      'shadow-md',
      'bg-linear-to-r',
      'text-[#fff]',
      'border-2',
      'font-bold',
      'font-semibold',
      'rounded-lg',
      'bg-red-500',
    ]
    for (const sample of bad) {
      expect(
        RULES.some(([, pattern]) => pattern.test(sample)),
        sample,
      ).toBe(true)
    }
    for (const ok of [
      'font-medium',
      'font-normal',
      'rounded-pill',
      'rounded-card',
      'rounded-full',
      'border',
    ]) {
      expect(
        RULES.some(([, pattern]) => pattern.test(ok)),
        ok,
      ).toBe(false)
    }
  })
})
