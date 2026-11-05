import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import TextField from '@/components/TextField.vue'

describe('TextField', () => {
  it('links the label to the input', () => {
    const wrapper = mount(TextField, { props: { label: 'Nombre' } })
    const input = wrapper.find('input')

    expect(wrapper.find('label').attributes('for')).toBe(input.attributes('id'))
    expect(wrapper.find('label').text()).toBe('Nombre')
  })

  it('at rest has no aria-invalid nor aria-describedby', () => {
    const input = mount(TextField, { props: { label: 'Nombre' } }).find('input')

    expect(input.attributes('aria-invalid')).toBeUndefined()
    expect(input.attributes('aria-describedby')).toBeUndefined()
    expect(input.classes()).toContain('border-field')
  })

  it('with an error sets aria-invalid and describes the input with the message', () => {
    const wrapper = mount(TextField, {
      props: { label: 'Correo', error: 'El correo no es válido. Revise el formato.' },
    })
    const input = wrapper.find('input')
    const describedBy = input.attributes('aria-describedby') ?? ''

    expect(input.attributes('aria-invalid')).toBe('true')
    expect(input.classes()).toContain('border-danger-border')
    expect(wrapper.find(`[id="${describedBy}"]`).text()).toContain('El correo no es válido')
    expect(wrapper.find(`[id="${describedBy}"] svg`).attributes('width')).toBe('16')
  })

  it('forwards native attributes to the input, not to the wrapper', () => {
    const wrapper = mount(TextField, {
      props: { label: 'Teléfono' },
      attrs: { type: 'tel', placeholder: '8888 8888', disabled: true, 'data-x': '1' },
    })
    const input = wrapper.find('input')

    expect(input.attributes('type')).toBe('tel')
    expect(input.attributes('placeholder')).toBe('8888 8888')
    expect(input.attributes('disabled')).toBeDefined()
    expect(input.attributes('data-x')).toBe('1')
    expect(wrapper.element.getAttribute('data-x')).toBeNull()
  })

  it('updates the v-model when typing', async () => {
    const wrapper = mount(TextField, {
      props: {
        label: 'Nombre',
        modelValue: '',
        'onUpdate:modelValue': (value: string | undefined) =>
          wrapper.setProps({ modelValue: value }),
      },
    })

    await wrapper.find('input').setValue('Ana')

    expect(wrapper.props('modelValue')).toBe('Ana')
  })

  it('exposes focus() to move the focus to the input', () => {
    const wrapper = mount(TextField, { props: { label: 'Nombre' }, attachTo: document.body })

    ;(wrapper.vm as unknown as { focus: () => void }).focus()

    expect(document.activeElement).toBe(wrapper.find('input').element)
    wrapper.unmount()
  })
})
