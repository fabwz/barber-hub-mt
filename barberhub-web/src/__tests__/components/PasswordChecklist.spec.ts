import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import PasswordChecklist from '@/components/PasswordChecklist.vue'
import { PASSWORD_RULES, meetsPasswordPolicy } from '@/composables/passwordPolicy'

function states(password: string, failed = false) {
  const wrapper = mount(PasswordChecklist, { props: { password, failed } })
  return {
    wrapper,
    states: wrapper.findAll('li').map((item) => item.attributes('data-state')),
  }
}

describe('PasswordChecklist (SE-07)', () => {
  it('names the five requirements in order', () => {
    expect(PASSWORD_RULES.map((rule) => rule.text)).toEqual([
      'Mínimo 8 caracteres',
      'Una letra mayúscula',
      'Una letra minúscula',
      'Un número',
      'Un signo especial',
    ])
  })

  it('shows every requirement as «Pendiente» for an empty password', () => {
    const { wrapper, states: result } = states('')

    expect(result).toEqual(Array(5).fill('pending'))
    expect(wrapper.findAll('.sr-only').map((word) => word.text())).toEqual(
      Array(5).fill('Pendiente:'),
    )
  })

  it('shows what is met as «Cumplido»', () => {
    expect(states('Abc').states).toEqual(['pending', 'met', 'met', 'pending', 'pending'])
    expect(states('Abcdefg1!').states).toEqual(Array(5).fill('met'))
  })

  it('after a failed attempt, what is missing is «Falta» and what is met stays «Cumplido»', () => {
    const { wrapper, states: result } = states('abcdefgh1', true)

    expect(result).toEqual(['met', 'missing', 'met', 'met', 'missing'])
    expect(wrapper.findAll('li[data-state="missing"] .sr-only').map((word) => word.text())).toEqual(
      ['Falta:', 'Falta:'],
    )
  })

  it('each state has an icon and a word, not only a color', () => {
    const { wrapper } = states('Abc', true)

    for (const item of wrapper.findAll('li')) {
      expect(item.find('svg[aria-hidden="true"]').exists()).toBe(true)
      expect(item.get('.sr-only').text()).toMatch(/^(Cumplido|Pendiente|Falta):$/)
    }
  })

  it('exposes the id so a field can point to the list', () => {
    const wrapper = mount(PasswordChecklist, { props: { password: '', id: 'lista' } })

    expect(wrapper.attributes('id')).toBe('lista')
  })

  it('the policy accepts only a password that meets all of them', () => {
    expect(meetsPasswordPolicy('Abcdefg1!')).toBe(true)
    expect(meetsPasswordPolicy('abcdefg1!')).toBe(false)
    expect(meetsPasswordPolicy('Abc1!')).toBe(false)
  })
})
