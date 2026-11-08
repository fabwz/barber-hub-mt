import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { CreditCard } from 'lucide-vue-next'
import { defineComponent, ref } from 'vue'

import OptionCard from '@/components/OptionCard.vue'
import OptionGroup from '@/components/OptionGroup.vue'

function mountCard(variant: 'row' | 'card' | 'tile', selected: string | null) {
  const chosen = ref<string | null>(selected)
  const Host = defineComponent({
    components: { OptionGroup, OptionCard },
    setup: () => ({ chosen, variant, CreditCard }),
    template: `
      <OptionGroup v-model="chosen">
        <OptionCard value="one" :variant="variant" :icon="CreditCard">Tarjeta</OptionCard>
        <OptionCard value="two" :variant="variant">Efectivo</OptionCard>
      </OptionGroup>`,
  })
  return mount(Host)
}

describe('OptionCard tile', () => {
  it('puts the icon over the label, with no radio indicator', () => {
    const wrapper = mountCard('tile', 'one')
    const first = wrapper.get('[data-value="one"]')

    expect(first.classes()).toContain('flex-col')
    expect(first.classes()).toContain('rounded-card')
    expect(first.attributes('aria-checked')).toBe('true')
    expect(first.find('svg').classes()).toContain('text-accent')
    expect(first.findAll('span[aria-hidden="true"]')).toHaveLength(0)
  })
})

describe('OptionCard', () => {
  it('is a radio with aria-checked that follows the group value', () => {
    const wrapper = mountCard('card', 'two')
    const [one, two] = wrapper.findAll('[role="radio"]')

    expect(one?.attributes('aria-checked')).toBe('false')
    expect(two?.attributes('aria-checked')).toBe('true')
  })

  it('selected card uses the elevated surface and keeps the same border', () => {
    const wrapper = mountCard('card', 'one')
    const [one, two] = wrapper.findAll('[role="radio"]')

    expect(one?.classes()).toEqual(
      expect.arrayContaining(['bg-elevated', 'border-line-strong', 'rounded-card']),
    )
    expect(two?.classes()).toContain('hover:border-line-hover')
  })

  it('row variant has no border at rest and hovers on surface', () => {
    const wrapper = mountCard('row', null)
    const second = wrapper.findAll('[role="radio"]')[1]

    expect(second?.classes()).toEqual(
      expect.arrayContaining(['hover:bg-surface', 'after:bg-line', 'after:inset-x-4']),
    )
  })

  it('renders the 20 px icon and the radio indicator ring', () => {
    const wrapper = mountCard('card', null)
    const first = wrapper.findAll('[role="radio"]')[0]

    expect(first?.find('svg').attributes('width')).toBe('20')
    expect(first?.find('span[aria-hidden="true"]').classes()).toEqual(
      expect.arrayContaining(['size-5', 'rounded-full', 'border-line-strong']),
    )
  })

  it('refuses to render outside an OptionGroup', () => {
    expect(() => mount(OptionCard, { props: { value: 'x' } })).toThrow(/OptionGroup/)
  })
})
