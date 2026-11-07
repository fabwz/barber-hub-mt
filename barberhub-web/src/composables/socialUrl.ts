/** A social link is kept only if it is a well-formed URL that starts with https://. */
export function validSocialUrl(value: string | undefined): string | null {
  if (typeof value !== 'string') return null
  const url = value.trim()
  if (!url.startsWith('https://')) return null
  try {
    return new URL(url).protocol === 'https:' ? url : null
  } catch {
    return null
  }
}

/** A contact email is kept only if it has text before and after the «@» and a dot in the domain. */
export function validEmail(value: string | undefined): string | null {
  if (typeof value !== 'string') return null
  const email = value.trim()
  return /^[^\s@]+@[^\s@.]+(?:\.[^\s@.]+)+$/.test(email) ? email : null
}
