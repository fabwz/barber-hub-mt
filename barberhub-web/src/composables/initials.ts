/** Initials of the first two words longer than two letters; falls back to the first letter. */
export function shopInitials(name: string): string {
  const words = name
    .split(/\s+/)
    .filter((word) => Array.from(word).length > 2)
    .slice(0, 2)
  const letters = words.map((word) => Array.from(word)[0]!.toUpperCase()).join('')
  return letters || (Array.from(name.trim())[0] ?? '').toUpperCase()
}

/**
 * Initials for the account menu. The session only knows the email, so the part before the «@»
 * is split at dots, dashes and underscores: «laura.brenes@correo.cr» gives «LB».
 */
export function accountInitials(label: string): string {
  const local = label.split('@')[0] ?? ''
  const letters = local
    .split(/[\s._-]+/)
    .filter((part) => part !== '')
    .slice(0, 2)
    .map((part) => Array.from(part)[0]!.toUpperCase())
  return letters.join('')
}
