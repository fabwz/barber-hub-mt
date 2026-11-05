import { describe, it, expect } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'

import OptionCard from '@/components/OptionCard.vue'
import OptionGroup from '@/components/OptionGroup.vue'

function mountGroup(initial: string | null): {
  wrapper: VueWrapper
  chosen: { value: string | null }
} {
  const chosen = ref<string | null>(initial)
  const Host = defineComponent({
    components: { OptionGroup, OptionCard },
    setup: () => ({ chosen }),
    template: `
      <OptionGroup v-model="chosen" aria-label="Servicio">
        <OptionCard value="a">Corte<template #description>45 min</template></OptionCard>
        <OptionCard value="b">Barba</OptionCard>
        <OptionCard value="c" disabled>Cejas</OptionCard>
        <OptionCard value="d">Combo</OptionCard>
      </OptionGroup>`,
  })
  const wrapper = mount(Host, { attachTo: document.body })
  return { wrapper, chosen: chosen as unknown as { value: string | null } }
}

describe('OptionGroup and OptionCard', () => {
  it('uses the radiogroup and radio roles with aria-checked', () => {
    const { wrapper } = mountGroup('b')
    const radios = wrapper.findAll('[role="radio"]')

    expect(wrapper.find('[role="radiogroup"]').exists()).toBe(true)
    expect(radios.map((radio) => radio.attributes('aria-checked'))).toEqual([
      'false',
      'true',
      'false',
      'false',
    ])
    wrapper.unmount()
  })

  it('shows the 10 px accent dot only on the selected card', () => {
    const { wrapper } = mountGroup('a')

    expect(wrapper.findAll('.size-2\\.5.bg-accent')).toHaveLength(1)
    wrapper.unmount()
  })

  it('moves the tab stop to the selected card (roving tabindex)', () => {
    const { wrapper } = mountGroup('d')

    expect(wrapper.findAll('[role="radio"]').map((radio) => radio.attributes('tabindex'))).toEqual([
      '-1',
      '-1',
      '-1',
      '0',
    ])
    wrapper.unmount()
  })

  it('gives the tab stop to the first card when nothing is chosen', () => {
    const { wrapper } = mountGroup(null)

    expect(wrapper.findAll('[role="radio"]')[0]?.attributes('tabindex')).toBe('0')
    expect(wrapper.findAll('[role="radio"]')[1]?.attributes('tabindex')).toBe('-1')
    wrapper.unmount()
  })

  it('selects with click, Space and Enter', async () => {
    const { wrapper, chosen } = mountGroup(null)
    const radios = wrapper.findAll('[role="radio"]')

    await radios[1]?.trigger('click')
    expect(chosen.value).toBe('b')
    await radios[0]?.trigger('keydown', { key: ' ' })
    expect(chosen.value).toBe('a')
    await radios[3]?.trigger('keydown', { key: 'Enter' })
    expect(chosen.value).toBe('d')
    wrapper.unmount()
  })

  it('moves focus and selection with the arrow keys, skipping disabled cards and wrapping', async () => {
    const { wrapper, chosen } = mountGroup('b')
    const radios = wrapper.findAll('[role="radio"]')

    ;(radios[1]?.element as HTMLElement).focus()
    await radios[1]?.trigger('keydown', { key: 'ArrowDown' })
    expect(chosen.value).toBe('d')
    expect(document.activeElement).toBe(radios[3]?.element)

    await radios[3]?.trigger('keydown', { key: 'ArrowRight' })
    expect(chosen.value).toBe('a')

    await radios[0]?.trigger('keydown', { key: 'ArrowUp' })
    expect(chosen.value).toBe('d')

    await radios[3]?.trigger('keydown', { key: 'Home' })
    expect(chosen.value).toBe('a')

    await radios[0]?.trigger('keydown', { key: 'End' })
    expect(chosen.value).toBe('d')
    wrapper.unmount()
  })

  it('does not select a disabled card and marks it aria-disabled', async () => {
    const { wrapper, chosen } = mountGroup('a')
    const disabled = wrapper.findAll('[role="radio"]')[2]

    await disabled?.trigger('click')
    expect(chosen.value).toBe('a')
    expect(disabled?.attributes('aria-disabled')).toBe('true')
    wrapper.unmount()
  })

  it('names each card and links the description with aria-labelledby and aria-describedby', () => {
    const { wrapper } = mountGroup('a')
    const first = wrapper.findAll('[role="radio"]')[0]
    const labelId = first?.attributes('aria-labelledby') ?? ''
    const descriptionId = first?.attributes('aria-describedby') ?? ''

    expect(wrapper.find(`[id="${labelId}"]`).text()).toBe('Corte')
    expect(wrapper.find(`[id="${descriptionId}"]`).text()).toBe('45 min')
    expect(wrapper.findAll('[role="radio"]')[1]?.attributes('aria-describedby')).toBeUndefined()
    wrapper.unmount()
  })

  it('fails loudly when an OptionCard is used outside an OptionGroup', () => {
    expect(() => mount(OptionCard, { props: { value: 'x' } })).toThrow(/OptionGroup/)
  })
})
