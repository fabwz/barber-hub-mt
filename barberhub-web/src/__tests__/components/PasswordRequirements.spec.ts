import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import PasswordRequirements, {
  type PasswordRequirement,
} from '@/components/PasswordRequirements.vue'

const requirements: PasswordRequirement[] = [
  { id: 'length', text: 'Mínimo 8 caracteres', test: (p) => p.length >= 8 },
  { id: 'upper', text: 'Una mayúscula', test: (p) => /[A-Z]/.test(p) },
  { id: 'digit', text: 'Un número', test: (p) => /\d/.test(p) },
]
const hint = 'Mínimo 8 caracteres, una mayúscula y un número.'

function make(password: string, failed = false) {
  return mount(PasswordRequirements, { props: { requirements, password, hint, failed } })
}

describe('PasswordRequirements', () => {
  it('is a polite live region with the requirements in one sentence', () => {
    const wrapper = make('')

    expect(wrapper.attributes('aria-live')).toBe('polite')
    expect(wrapper.get('p').text()).toBe(hint)
    expect(wrapper.get('p').classes()).toContain('text-fg-subtle')
  })

  it('stays quiet while typing, even with requirements missing', () => {
    const wrapper = make('abc')

    expect(wrapper.get('p').classes()).toContain('text-fg-subtle')
    expect(wrapper.find('li').exists()).toBe(false)
  })

  it('after a failed attempt with something missing the sentence is red and weight 500', () => {
    const wrapper = make('abc', true)

    expect(wrapper.get('p').classes()).toEqual(
      expect.arrayContaining(['text-danger', 'font-medium']),
    )
    expect(wrapper.get('p').text()).toBe(hint)
  })

  it('announces each requirement to screen readers after a failed attempt', () => {
    const wrapper = make('Abc', true)

    expect(wrapper.findAll('li').map((li) => li.text())).toEqual([
      'No cumplido: Mínimo 8 caracteres',
      'Cumplido: Una mayúscula',
      'No cumplido: Un número',
    ])
    expect(wrapper.get('ul').classes()).toContain('sr-only')
  })

  it('if everything is met nothing happens, even after a failed attempt', () => {
    const wrapper = make('Abcdefg1', true)

    expect(wrapper.get('p').classes()).toContain('text-fg-subtle')
    expect(wrapper.find('ul').exists()).toBe(false)
  })

  it('forwards the id', () => {
    const wrapper = mount(PasswordRequirements, {
      props: { requirements, password: '', hint, id: 'reqs' },
    })
    expect(wrapper.attributes('id')).toBe('reqs')
  })
})
