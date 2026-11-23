import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import TimeField from '@/components/TimeField.vue'
import { cleanTime, isValidTime } from '@/composables/timeOfDay'

afterEach(() => {
  document.body.innerHTML = ''
})

describe('cleanTime and isValidTime', () => {
  it.each([
    ['0', '0'],
    ['09', '09'],
    ['090', '09:0'],
    ['0900', '09:00'],
    ['09:00', '09:00'],
    ['9a0b0c0', '90:00'],
    ['123456', '12:34'],
    ['', ''],
  ])('cleans «%s» to «%s»', (typed, shown) => {
    expect(cleanTime(typed)).toBe(shown)
  })

  it.each(['00:00', '09:05', '19:00', '23:59'])('%s is an hour', (value) => {
    expect(isValidTime(value)).toBe(true)
  })

  it.each(['', '9:00', '24:00', '12:60', '09:0', '0900', 'ab:cd'])(
    '«%s» is not an hour',
    (value) => {
      expect(isValidTime(value)).toBe(false)
    },
  )
})

describe('TimeField', () => {
  function mountField(props: Record<string, unknown> = {}) {
    return mount(TimeField, {
      props: { label: 'Apertura del lunes', ...props },
      attachTo: document.body,
    })
  }

  it('names the field for everyone and hides the label unless asked to show it', () => {
    const hidden = mountField()
    expect(hidden.get('label').classes()).toContain('sr-only')
    expect(hidden.get('input').attributes('id')).toBe(hidden.get('label').attributes('for'))
    const shown = mountField({ showLabel: true })
    expect(shown.get('label').classes()).not.toContain('sr-only')
  })

  it('adds the colon by itself and keeps only digits', async () => {
    const wrapper = mountField()
    const input = wrapper.get('input')
    await input.setValue('0 9x0 0')
    expect((input.element as HTMLInputElement).value).toBe('09:00')
    expect(wrapper.emitted('update:modelValue')!.at(-1)).toEqual(['09:00'])
  })

  it('shows the value it receives', () => {
    const wrapper = mountField({ modelValue: '12:30' })
    expect((wrapper.get('input').element as HTMLInputElement).value).toBe('12:30')
  })

  it('marks the problem for assistive technology and points to its message', () => {
    const wrapper = mountField({ invalid: true, describedBy: 'row-error' })
    const input = wrapper.get('input')
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(input.attributes('aria-describedby')).toBe('row-error')
    expect(input.classes()).toContain('border-danger-border')
  })
})
