import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import ErrorState from '@/components/ErrorState.vue'

describe('ErrorState', () => {
  it('is an alert with the message, the way out and a Reintentar button', () => {
    const wrapper = mount(ErrorState, {
      props: { message: 'Error: no pudimos cargar las reservas.' },
    })

    expect(wrapper.attributes('role')).toBe('alert')
    expect(wrapper.find('h2').text()).toBe('Error: no pudimos cargar las reservas.')
    expect(wrapper.find('p').text()).toContain('Revise su conexión')
    expect(wrapper.find('button').text()).toBe('Reintentar')
  })

  it('emits retry when the button is clicked and when it is activated with the keyboard', async () => {
    const wrapper = mount(ErrorState, { props: { message: 'Error: no pudimos cargar.' } })

    await wrapper.find('button').trigger('click')

    expect(wrapper.emitted('retry')).toHaveLength(1)
    expect(wrapper.find('button').attributes('type')).toBe('button')
  })

  it('renders the extra action slot next to the retry button', () => {
    const wrapper = mount(ErrorState, {
      props: { message: 'Error: no pudimos cargar.' },
      slots: { action: '<a href="/">Volver al inicio</a>' },
    })

    expect(wrapper.find('a').text()).toBe('Volver al inicio')
    expect(wrapper.findAll('button')).toHaveLength(1)
  })
})
