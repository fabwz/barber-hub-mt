import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import SkeletonBlock from '@/components/SkeletonBlock.vue'

describe('SkeletonBlock', () => {
  it('marks the container as busy and announces the loading to screen readers', () => {
    const wrapper = mount(SkeletonBlock)

    expect(wrapper.attributes('aria-busy')).toBe('true')
    expect(wrapper.find('.sr-only').text()).toBe('Cargando')
  })

  it('renders the requested number of line-colored 8 px blocks hidden from assistive technology', () => {
    const blocks = mount(SkeletonBlock, { props: { count: 3 } }).findAll('[aria-hidden="true"]')

    expect(blocks).toHaveLength(3)
    expect(blocks[0]?.classes()).toEqual(expect.arrayContaining(['bg-line', 'rounded-field']))
  })

  it('pulses slowly and turns the animation off with prefers-reduced-motion', () => {
    const block = mount(SkeletonBlock).find('[aria-hidden="true"]')

    expect(block.classes()).toContain('animate-pulse')
    expect(block.classes()).toContain('motion-reduce:animate-none')
  })

  it('imitates a text line, a row or a larger block', () => {
    const heightOf = (shape: 'text' | 'row' | 'block') =>
      mount(SkeletonBlock, { props: { shape } }).find('[aria-hidden="true"]').classes()

    expect(heightOf('text')).toContain('h-4')
    expect(heightOf('row')).toContain('h-12')
    expect(heightOf('block')).toContain('h-24')
  })
})
