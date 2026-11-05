import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { Transition } from 'vue'

import NoticeBanner from '@/components/NoticeBanner.vue'

describe('NoticeBanner', () => {
  it('success uses role status, the surface fill and the default slot', () => {
    const wrapper = mount(NoticeBanner, { slots: { default: 'Reserva confirmada.' } })
    const banner = wrapper.find('[role]')

    expect(banner.attributes('role')).toBe('status')
    expect(banner.classes()).toEqual(
      expect.arrayContaining(['bg-surface', 'border-line', 'rounded-card']),
    )
    expect(banner.text()).toBe('Reserva confirmada.')
  })

  it('error uses role alert and the danger tokens', () => {
    const wrapper = mount(NoticeBanner, { props: { variant: 'error' } })
    const banner = wrapper.find('[role]')

    expect(banner.attributes('role')).toBe('alert')
    expect(banner.classes()).toEqual(
      expect.arrayContaining(['bg-danger-surface', 'border-danger-border', 'text-danger']),
    )
  })

  it('renders the action slot only when provided', () => {
    const without = mount(NoticeBanner)
    const withAction = mount(NoticeBanner, { slots: { action: '<button>Deshacer</button>' } })

    expect(without.find('button').exists()).toBe(false)
    expect(withAction.find('button').text()).toBe('Deshacer')
  })

  it('is removed from the page when not visible', () => {
    const wrapper = mount(NoticeBanner, { props: { visible: false } })

    expect(wrapper.find('[role]').exists()).toBe(false)
  })

  it('fades in 150 ms and drops the transition with prefers-reduced-motion', () => {
    const transition = mount(NoticeBanner).findComponent(Transition)
    const enter = String(transition.props('enterActiveClass') ?? '')
    const leave = String(transition.props('leaveActiveClass') ?? '')

    for (const classes of [enter, leave]) {
      expect(classes).toContain('duration-150')
      expect(classes).toContain('ease-out')
      expect(classes).toContain('motion-reduce:transition-none')
    }
  })
})
