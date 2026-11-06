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
