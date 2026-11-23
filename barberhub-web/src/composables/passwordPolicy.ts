export interface PasswordRule {
  id: string
  text: string
  test: (password: string) => boolean
}

/** SE-07: at least 8 characters with an uppercase letter, a lowercase letter, a digit and a symbol. */
export const PASSWORD_RULES: readonly PasswordRule[] = [
  { id: 'length', text: 'Mínimo 8 caracteres', test: (p) => p.length >= 8 },
  { id: 'upper', text: 'Una letra mayúscula', test: (p) => /\p{Lu}/u.test(p) },
  { id: 'lower', text: 'Una letra minúscula', test: (p) => /\p{Ll}/u.test(p) },
  { id: 'digit', text: 'Un número', test: (p) => /\d/.test(p) },
  { id: 'symbol', text: 'Un signo especial', test: (p) => /[^\p{L}\p{N}]/u.test(p) },
]

export function meetsPasswordPolicy(password: string): boolean {
  return PASSWORD_RULES.every((rule) => rule.test(password))
}
