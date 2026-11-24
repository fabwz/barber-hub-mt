/**
 * The social links of a barbershop (RF-01, MT-09). The check mirrors the API: an `https://` link of at most
 * 300 characters, with no spaces, user, password or port, on the domain of its network. It only spares a trip:
 * the API decides, and its message is the fallback.
 */
export type SocialNetwork = 'whatsapp' | 'instagram' | 'facebook' | 'tiktok'

export const MAX_SOCIAL_LENGTH = 300

interface NetworkRule {
  /** Name shown to the person. */
  name: string
  domains: readonly string[]
  /** An example that fits the network, shown in the message and as the placeholder. */
  example: string
  placeholder: string
}

export const SOCIAL_NETWORKS: Record<SocialNetwork, NetworkRule> = {
  whatsapp: {
    name: 'WhatsApp',
    domains: ['wa.me', 'api.whatsapp.com'],
    example: 'https://wa.me/50688123456',
    placeholder: 'https://wa.me/…',
  },
  instagram: {
    name: 'Instagram',
    domains: ['instagram.com'],
    example: 'https://instagram.com/su_barberia',
    placeholder: 'https://instagram.com/…',
  },
  facebook: {
    name: 'Facebook',
    domains: ['facebook.com', 'fb.com'],
    example: 'https://facebook.com/su.barberia',
    placeholder: 'https://facebook.com/…',
  },
  tiktok: {
    name: 'TikTok',
    domains: ['tiktok.com'],
    example: 'https://tiktok.com/@su_barberia',
    placeholder: 'https://tiktok.com/@…',
  },
}

export const NETWORK_ORDER: readonly SocialNetwork[] = [
  'whatsapp',
  'instagram',
  'facebook',
  'tiktok',
]

/** «Escriba un enlace de Instagram válido, como https://instagram.com/su_barberia.» */
export function socialMessage(network: SocialNetwork): string {
  const rule = SOCIAL_NETWORKS[network]
  return `Escriba un enlace de ${rule.name} válido, como ${rule.example}.`
}

/** The link is fine for its network. */
export function isValidSocialLink(network: SocialNetwork, value: string): boolean {
  const link = value.trim()
  if (link.length > MAX_SOCIAL_LENGTH) return false
  if (!link.startsWith('https://') || /[\s\x00-\x1F\x7F\\]/.test(link)) return false
  let url: URL
  try {
    url = new URL(link)
  } catch {
    return false
  }
  if (url.username !== '' || url.password !== '' || url.port !== '') return false
  const host = url.hostname.toLowerCase().replace(/^www\./, '')
  return SOCIAL_NETWORKS[network].domains.includes(host)
}

/** The message of a link that is not fine, or an empty text. An empty link is fine: the network is optional. */
export function socialProblem(network: SocialNetwork, value: string): string {
  if (value.trim() === '') return ''
  return isValidSocialLink(network, value) ? '' : socialMessage(network)
}
