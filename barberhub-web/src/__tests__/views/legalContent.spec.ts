import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { RouterLink } from 'vue-router'
import { describe, expect, it } from 'vitest'

import {
  LEGAL_UPDATED,
  parseInline,
  type InlinePart,
  type LegalDocument,
} from '@/views/legal/legalText'
import { privacyDocument } from '@/views/legal/privacyContent'
import { termsDocument } from '@/views/legal/termsContent'

const plain = (parts: InlinePart[]) => parts.map((part) => part.text).join('')

/** Markdown inline syntax removed, so it can be compared with the rendered text. */
function stripMarkdown(line: string): string {
  return line
    .replace(/\*\*([^*]+)\*\*/g, '$1')
    .replace(/\*([^*]+)\*/g, '$1')
    .replace(/`([^`]+)`/g, '$1')
    .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '$1')
    .trim()
}

/** The text lines of a docs/legal file: headings, paragraphs, bullets and table rows. */
function linesOfMarkdown(file: string): string[] {
  const text = readFileSync(join(process.cwd(), '..', 'docs', 'legal', file), 'utf8')
  const lines: string[] = []
  for (const raw of text.split(/\r?\n/)) {
    const line = raw.trim()
    if (line === '' || line === '---' || line.startsWith('# ')) continue
    if (line.startsWith('**Última actualización:**') || line.startsWith('**Versión:**')) continue
    if (/^\|[-| ]+\|$/.test(line)) continue
    if (line.startsWith('|')) {
      lines.push(
        line
          .slice(1, -1)
          .split('|')
          .map((cell) => stripMarkdown(cell))
          .join(' | '),
      )
      continue
    }
    lines.push(stripMarkdown(line.replace(/^## /, '').replace(/^- /, '')))
  }
  return lines
}

function linesOfDocument(doc: LegalDocument): string[] {
  const lines: string[] = []
  for (const section of doc.sections) {
    lines.push(section.heading)
    for (const block of section.blocks) {
      if (block.kind === 'p') lines.push(plain(block.parts))
      else if (block.kind === 'ul') for (const item of block.items) lines.push(plain(item))
      else {
        lines.push(block.head.map(plain).join(' | '))
        for (const row of block.rows) lines.push(row.map(plain).join(' | '))
      }
    }
  }
  return lines
}

describe('legal text matches docs/legal', () => {
  it('the terms show exactly the text of terms-and-conditions.md', () => {
    expect(linesOfDocument(termsDocument)).toEqual(linesOfMarkdown('terms-and-conditions.md'))
  })

  it('the privacy policy shows exactly the text of privacy-policy.md', () => {
    expect(linesOfDocument(privacyDocument)).toEqual(linesOfMarkdown('privacy-policy.md'))
  })

  it('both documents carry today’s date and version 1.0', () => {
    expect(LEGAL_UPDATED).toBe('5 de noviembre de 2026')
    for (const doc of [termsDocument, privacyDocument]) {
      expect(doc.updated).toBe('5 de noviembre de 2026')
      expect(doc.version).toBe('1.0')
    }
  })
})

describe('parseInline', () => {
  it('splits bold, italic, code and internal links', () => {
    const parts = parseInline(
      'Una **negrita**, un *tenant*, `sessionStorage` y la [Política](/privacidad).',
    )

    expect(parts.map((part) => [part.text, part.class ?? ''])).toEqual([
      ['Una ', ''],
      ['negrita', 'font-medium'],
      [', un ', ''],
      ['tenant', 'italic'],
      [', ', ''],
      ['sessionStorage', 'rounded-field bg-surface px-1.5 py-0.5 font-sans text-sm'],
      [' y la ', ''],
      ['Política', ''],
      ['.', ''],
    ])
    expect(parts[1]!.is).toBe('strong')
    expect(parts[3]!.is).toBe('em')
    expect(parts[5]!.is).toBe('code')
    expect(parts[7]!.is).toBe(RouterLink)
    expect(parts[7]!.to).toBe('/privacidad')
  })

  it('keeps bracketed placeholders as text, also inside bold', () => {
    const parts = parseInline('operado por **[razón social]** con [correo] y [dirección]')

    expect(plain(parts)).toBe('operado por [razón social] con [correo] y [dirección]')
    expect(parts.filter((part) => part.is === RouterLink)).toHaveLength(0)
  })

  it('never makes a link to another site: only internal paths become links', () => {
    for (const to of ['https://evil.com', '//evil.com', 'javascript:alert(1)']) {
      const parts = parseInline(`[clic](${to})`)

      expect(parts[0]!.is).toBe('span')
      expect(parts[0]!.text).toBe('clic')
      expect(parts.some((part) => part.to !== undefined || part.is === RouterLink)).toBe(false)
    }
  })

  it('treats HTML as plain text', () => {
    const parts = parseInline('<img src=x onerror=alert(1)> **ok**')

    expect(parts[0]!.is).toBe('span')
    expect(parts[0]!.text).toBe('<img src=x onerror=alert(1)> ')
  })
})
