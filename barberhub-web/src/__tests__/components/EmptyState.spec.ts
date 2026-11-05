import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import EmptyState from '@/components/EmptyState.vue'

describe('EmptyState', () => {
  it('shows the title as a heading, one line of explanation and the action slot', () => {
    const wrapper = mount(EmptyState, {
      props: { title: 'No hay reservas para el sáb 7 nov.', description: 'Aparecerán aquí.' },
      slots: { action: '<button>Ir a hoy</button>' },
    })

    expect(wrapper.find('h2').text()).toBe('No hay reservas para el sáb 7 nov.')
    expect(wrapper.find('p').text()).toBe('Aparecerán aquí.')
    expect(wrapper.find('button').text()).toBe('Ir a hoy')
  })

  it('has no action area without the slot', () => {
    const wrapper = mount(EmptyState, { props: { title: 'Vacío', description: 'Nada aún.' } })

    expect(wrapper.find('button').exists()).toBe(false)
  })

  it('does not claim to be busy or an alert: an empty list is a normal result', () => {
    const wrapper = mount(EmptyState, { props: { title: 'Vacío', description: 'Nada aún.' } })

    expect(wrapper.attributes('aria-busy')).toBeUndefined()
    expect(wrapper.attributes('role')).toBeUndefined()
  })
})
