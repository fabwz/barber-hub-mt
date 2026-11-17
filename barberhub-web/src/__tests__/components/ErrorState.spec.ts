import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import ErrorState from '@/components/ErrorState.vue'

describe('ErrorState', () => {
  it('is an alert with the message, the way out and a Reintentar button', () => {
    const wrapper = mount(ErrorState, {
      props: { message: 'No pudimos cargar las reservas.' },
    })

    expect(wrapper.attributes('role')).toBe('alert')
    expect(wrapper.find('h2').text()).toBe('No pudimos cargar las reservas.')
    expect(wrapper.find('p').text()).toContain('Revise su conexión')
    expect(wrapper.find('button').text()).toBe('Reintentar')
  })

  it('emits retry when the button is clicked and when it is activated with the keyboard', async () => {
    const wrapper = mount(ErrorState, { props: { message: 'No pudimos cargar.' } })

    await wrapper.find('button').trigger('click')

    expect(wrapper.emitted('retry')).toHaveLength(1)
    expect(wrapper.find('button').attributes('type')).toBe('button')
  })

  it('renders the extra action slot next to the retry button', () => {
    const wrapper = mount(ErrorState, {
      props: { message: 'No pudimos cargar.' },
      slots: { action: '<a href="/">Volver al inicio</a>' },
    })

    expect(wrapper.find('a').text()).toBe('Volver al inicio')
    expect(wrapper.findAll('button')).toHaveLength(1)
  })

  it('follows the design: icon, danger-surface fill, no border, title in danger, secondary button', () => {
    const wrapper = mount(ErrorState, { props: { message: 'No pudimos cargar.' } })

    expect(wrapper.classes()).toEqual(
      expect.arrayContaining(['bg-danger-surface', 'rounded-field']),
    )
    expect(wrapper.classes().some((name) => name.startsWith('border'))).toBe(false)
    expect(wrapper.find('svg').attributes('aria-hidden')).toBe('true')
    expect(wrapper.find('h2').classes()).toContain('text-danger')
    expect(wrapper.find('button').classes()).toContain('bg-transparent')
  })

  it('holds the retry button while a wait is running and shows the extra line of the slot', async () => {
    const wrapper = mount(ErrorState, {
      props: { message: 'No pudimos procesar.', retryDisabled: true },
      slots: { default: '<p>Podrá intentar de nuevo en 20 s.</p>' },
    })

    expect(wrapper.find('button').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Podrá intentar de nuevo en 20 s.')

    await wrapper.find('button').trigger('click')
    expect(wrapper.emitted('retry')).toBeUndefined()
  })
})
