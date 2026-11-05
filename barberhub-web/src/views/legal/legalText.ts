import type { Component } from 'vue'
import { RouterLink } from 'vue-router'

/**
 * The legal pages are static Spanish text written like the documents in docs/legal
 * (paragraphs, bullet lists and one table, with **bold**, *italic*, `code` and
 * [internal links](/ruta)). This turns that text into plain data the views render with
 * normal templates: no v-html, so the text can never inject markup.
 */

export interface InlinePart {
  /** Native tag or RouterLink, used with `<component :is>`. */
  is: string | Component
  text: string
  class?: string
  /** Only for links; always an internal path. */
  to?: string
}

export type RawBlock =
  | { kind: 'p'; text: string }
  | { kind: 'ul'; items: string[] }
  | { kind: 'table'; head: string[]; rows: string[][] }

export interface RawSection {
  heading: string
  blocks: RawBlock[]
}

export interface RawDocument {
  title: string
  updated: string
  version: string
  sections: RawSection[]
}

export type Block =
  | { kind: 'p'; parts: InlinePart[] }
  | { kind: 'ul'; items: InlinePart[][] }
  | { kind: 'table'; head: InlinePart[][]; rows: InlinePart[][][] }

export interface LegalDocument {
  title: string
  updated: string
  version: string
  sections: { heading: string; blocks: Block[] }[]
}

const TOKEN = /(\*\*[^*]+\*\*|\*[^*\s][^*]*\*|`[^`]+`|\[[^\]]+\]\([^)\s]+\))/
const LINK = /^\[([^\]]+)\]\(([^)\s]+)\)$/

export function parseInline(source: string): InlinePart[] {
  return source
    .split(TOKEN)
    .filter((piece) => piece !== '')
    .map((piece): InlinePart => {
      if (piece.length > 4 && piece.startsWith('**') && piece.endsWith('**')) {
        // Manrope is loaded only in 400 and 500: bold text is weight 500.
        return { is: 'strong', text: piece.slice(2, -2), class: 'font-medium' }
      }
      if (piece.length > 2 && piece.startsWith('*') && piece.endsWith('*')) {
        return { is: 'em', text: piece.slice(1, -1), class: 'italic' }
      }
      if (piece.length > 2 && piece.startsWith('`') && piece.endsWith('`')) {
        return {
          is: 'code',
          text: piece.slice(1, -1),
          // font-sans: no monospace fonts in the design (the browser default for <code>).
          class: 'rounded-field bg-surface px-1.5 py-0.5 font-sans text-sm',
        }
      }
      const link = LINK.exec(piece)
      if (link) {
        const [, text = '', to = ''] = link
        // Only internal paths become links; anything else stays plain text.
        if (to.startsWith('/') && !to.startsWith('//')) return { is: RouterLink, text, to }
        return { is: 'span', text }
      }
      return { is: 'span', text: piece }
    })
}

function buildBlock(block: RawBlock): Block {
  if (block.kind === 'p') return { kind: 'p', parts: parseInline(block.text) }
  if (block.kind === 'ul') return { kind: 'ul', items: block.items.map(parseInline) }
  return {
    kind: 'table',
    head: block.head.map(parseInline),
    rows: block.rows.map((row) => row.map(parseInline)),
  }
}

export function buildDocument(raw: RawDocument): LegalDocument {
  return {
    title: raw.title,
    updated: raw.updated,
    version: raw.version,
    sections: raw.sections.map((section) => ({
      heading: section.heading,
      blocks: section.blocks.map(buildBlock),
    })),
  }
}

/** Date of the current text of both documents. */
export const LEGAL_UPDATED = '5 de noviembre de 2026'
