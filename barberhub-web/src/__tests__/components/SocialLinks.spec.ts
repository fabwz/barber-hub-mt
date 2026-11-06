import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import SocialLinks from '@/components/SocialLinks.vue'

const ALL = {
  shopName: 'Pura Navaja',
  whatsapp: 'https://wa.me/50622223333',
  instagram: 'https://instagram.com/puranavaja',
  facebook: 'https://facebook.com/puranavaja',
  tiktok: 'https://tiktok.com/@puranavaja',
}

describe('SocialLinks', () => {
  it('shows the four networks with label, new tab and safe rel', () => {
    const links = mount(SocialLinks, { props: ALL }).findAll('a')

    expect(links.map((link) => link.attributes('aria-label'))).toEqual([
      'WhatsApp de Pura Navaja',
      'Instagram de Pura Navaja',
      'Facebook de Pura Navaja',
      'TikTok de Pura Navaja',
    ])
    expect(links.map((link) => link.attributes('href'))).toEqual([
      ALL.whatsapp,
      ALL.instagram,
      ALL.facebook,
      ALL.tiktok,
    ])
    for (const link of links) {
      expect(link.attributes('target')).toBe('_blank')
      expect(link.attributes('rel')).toBe('noopener noreferrer')
      expect(link.classes()).toContain('size-11')
      expect(link.get('svg').attributes('aria-hidden')).toBe('true')
      expect(link.get('svg').attributes('width')).toBe('20')
    }
  })

  it('shows only the configured networks', () => {
    const wrapper = mount(SocialLinks, {
      props: { shopName: 'Pura Navaja', instagram: ALL.instagram },
    })

    expect(wrapper.findAll('a')).toHaveLength(1)
    expect(wrapper.get('a').attributes('aria-label')).toBe('Instagram de Pura Navaja')
  })

  it('drops values that are not https:// URLs', () => {
    const wrapper = mount(SocialLinks, {
      props: {
        shopName: 'Pura Navaja',
        whatsapp: 'http://wa.me/1',
        instagram: 'javascript:alert(1)',
        facebook: '@puranavaja',
        tiktok: 'https://',
      },
    })

    expect(wrapper.find('a').exists()).toBe(false)
  })

  it('renders nothing without networks', () => {
    expect(mount(SocialLinks, { props: { shopName: 'Pura Navaja' } }).html()).toBe('<!--v-if-->')
    expect(
      mount(SocialLinks, { props: { shopName: 'Pura Navaja', whatsapp: '' } })
        .find('ul')
        .exists(),
    ).toBe(false)
  })
})
