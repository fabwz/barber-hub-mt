import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import BaseCheckbox from '@/components/BaseCheckbox.vue'

function make(props: Record<string, unknown> = {}) {
  return mount(BaseCheckbox, {
    props,
    slots: { default: 'Acepto los <a href="/terminos">Términos</a>' },
  })
}

describe('BaseCheckbox', () => {
  it('is a button with role checkbox, aria-checked, aria-required and aria-labelledby', () => {
    const wrapper = make({ required: true })
    const box = wrapper.get('[role="checkbox"]')
    const labelId = box.attributes('aria-labelledby') ?? ''

    expect(box.element.tagName).toBe('BUTTON')
    expect(box.attributes('type')).toBe('button')
    expect(box.attributes('aria-checked')).toBe('false')
    expect(box.attributes('aria-required')).toBe('true')
    expect(wrapper.get(`[id="${labelId}"]`).text()).toContain('Acepto los')
    expect(box.classes()).toContain('border-field')
    expect(box.find('svg').exists()).toBe(false)
  })

  it('the box is 20 px inside a row of at least 44 px, with a 14 px check when marked', async () => {
    const wrapper = make()
    const box = wrapper.get('[role="checkbox"]')

    expect(box.classes()).toContain('size-5')
    expect(box.element.parentElement?.classList.contains('min-h-11')).toBe(true)
    await box.trigger('click')
    expect(box.get('svg').attributes('width')).toBe('14')
  })

  it('does not set aria-required when it is optional', () => {
    expect(make().get('[role="checkbox"]').attributes('aria-required')).toBeUndefined()
  })

  it('toggles with a click, showing the check mark (not only color)', async () => {
    const wrapper = make()
    const box = wrapper.get('[role="checkbox"]')

    await box.trigger('click')

    expect(box.attributes('aria-checked')).toBe('true')
    expect(box.find('svg').exists()).toBe(true)
    expect(box.classes()).toContain('bg-elevated')
    expect(box.classes()).toContain('border-line-strong')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([true])
  })

  it('toggles with the Space key and not with other keys', async () => {
    const wrapper = make()
    const box = wrapper.get('[role="checkbox"]')

    await box.trigger('keyup', { key: 'a' })
    expect(box.attributes('aria-checked')).toBe('false')

    await box.trigger('keyup', { key: ' ', code: 'Space' })
    expect(box.attributes('aria-checked')).toBe('true')
    await box.trigger('keyup', { key: ' ', code: 'Space' })
    expect(box.attributes('aria-checked')).toBe('false')
  })

  it('a click on the label text toggles; a click on a link does not', async () => {
    const wrapper = make()
    const box = wrapper.get('[role="checkbox"]')

    await wrapper.get('a').trigger('click')
    expect(box.attributes('aria-checked')).toBe('false')

    await wrapper.get('span').trigger('click')
    expect(box.attributes('aria-checked')).toBe('true')
  })

  it('with an error uses the error border, links the message and sets aria-invalid', () => {
    const wrapper = make({ error: 'Marque esta casilla para crear su cuenta.' })
    const box = wrapper.get('[role="checkbox"]')
    const messageId = box.attributes('aria-describedby') ?? ''

    expect(box.classes()).toContain('border-danger-border')
    expect(box.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get(`[id="${messageId}"]`).text()).toContain('Marque esta casilla')
    expect(wrapper.get(`[id="${messageId}"] svg`).attributes('width')).toBe('16')
  })

  it('while sending is dimmed, uses the line border and ignores clicks', async () => {
    const wrapper = make({ loading: true })
    const box = wrapper.get('[role="checkbox"]')

    expect(box.classes()).toEqual(expect.arrayContaining(['border-line', 'opacity-60']))
    expect(box.attributes('disabled')).toBeDefined()
    await box.trigger('click')
    expect(box.attributes('aria-checked')).toBe('false')
  })

  it('works with v-model', async () => {
    const wrapper = mount(BaseCheckbox, {
      props: {
        modelValue: true,
        'onUpdate:modelValue': (value: boolean) => wrapper.setProps({ modelValue: value }),
      },
    })

    expect(wrapper.get('[role="checkbox"]').attributes('aria-checked')).toBe('true')
    await wrapper.get('[role="checkbox"]').trigger('click')
    expect(wrapper.get('[role="checkbox"]').attributes('aria-checked')).toBe('false')
  })
})
