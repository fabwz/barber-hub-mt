import type { Ref } from 'vue'

// Filters and checks shared by the screens that ask for a name, an email or a phone (create account
// and «Mi cuenta»). Every field drops the keys that do not belong: the field shows the clean value.

/** Phone: 8 digits shown as 1234-5678, so the field holds at most 9 characters. */
export const PHONE_DIGITS = 8
export const MAX_PHONE_TEXT = 9

/** Limits of the contract (RegistroDeCuenta and CorreccionDeCuenta). */
export const MAX_NAME = 120
export const MAX_EMAIL = 255

/** Letters (with accents), spaces and the marks real names use: apostrophe, hyphen and period. */
export function cleanName(value: string): string {
  return value.replace(/[^\p{L}\p{M}\s'’.-]/gu, '')
}

export function phoneDigits(value: string): string {
  return value.replace(/\D/g, '')
}

/** At most 8 digits, with the hyphen added by itself when the 5th digit is typed: 1234-5678. */
export function cleanPhone(value: string): string {
  const digits = phoneDigits(value).slice(0, PHONE_DIGITS)
  return digits.length > 4 ? `${digits.slice(0, 4)}-${digits.slice(4)}` : digits
}

const EMAIL_EXAMPLE = 'Ejemplo: nombre@correo.cr.'

/** Says what the email is missing; the API still has the last word. Any ending is valid (.cr, .com, .co.cr…). */
export function emailProblem(value: string): string {
  const text = value.trim()
  if (text === '') return 'Escriba su correo electrónico.'
  if (/\s/.test(text)) return 'El correo no puede llevar espacios.'
  if (!text.includes('@')) return `Al correo le falta el @. ${EMAIL_EXAMPLE}`
  const [local = '', domain = ''] = text.split('@')
  if (local === '') return `Al correo le falta lo que va antes del @. ${EMAIL_EXAMPLE}`
  if (!/^[^\s@.]+(?:\.[^\s@.]+)*\.[^\s@.]{2,}$/.test(domain)) {
    return `Al correo le falta el dominio. ${EMAIL_EXAMPLE}`
  }
  return ''
}

/**
 * Keeps only the allowed characters while typing or pasting. It cleans the input itself,
 * because the model may not change (and so would not redraw) when a character is dropped.
 */
export function restrict(
  event: Event,
  clean: (value: string) => string,
  target: Ref<string>,
): void {
  const input = event.target as HTMLInputElement
  const cursor = input.selectionStart ?? input.value.length
  const before = input.value
  const after = clean(before)
  if (after !== before) {
    input.value = after
    const position = Math.max(0, cursor - (before.length - after.length))
    input.setSelectionRange(position, position)
  }
  target.value = after
}
