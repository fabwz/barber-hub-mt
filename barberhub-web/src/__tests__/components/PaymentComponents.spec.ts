import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import CardForm from '@/components/CardForm.vue'
import PaymentStatus from '@/components/PaymentStatus.vue'
import SinpeForm from '@/components/SinpeForm.vue'
import SinpeInstructions from '@/components/SinpeInstructions.vue'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

describe('CardForm', () => {
  const FIELDS = ['cc-number', 'cc-exp', 'cc-csc', 'cc-name']

  it('uses the autocomplete tokens and numeric input of a card', () => {
    const wrapper = mount(CardForm)

    for (const token of FIELDS) {
      expect(wrapper.find(`input[autocomplete="${token}"]`).exists(), token).toBe(true)
    }
    for (const token of ['cc-number', 'cc-exp', 'cc-csc']) {
      expect(wrapper.get(`input[autocomplete="${token}"]`).attributes('inputmode')).toBe('numeric')
    }
  })

  it('formats the number and the expiry while typing and ignores what is not a digit', async () => {
    const wrapper = mount(CardForm)

    await wrapper.get('input[autocomplete="cc-number"]').setValue('4242-4242 abc 42424242')
    await wrapper.get('input[autocomplete="cc-exp"]').setValue('1/2a35')
    await wrapper.get('input[autocomplete="cc-csc"]').setValue('7a3b7c9')

    expect(wrapper.get<HTMLInputElement>('input[autocomplete="cc-number"]').element.value).toBe(
      '4242 4242 4242 4242',
    )
    expect(wrapper.get<HTMLInputElement>('input[autocomplete="cc-exp"]').element.value).toBe(
      '12/35',
    )
    expect(wrapper.get<HTMLInputElement>('input[autocomplete="cc-csc"]').element.value).toBe('7379')
  })

  it('hands out the card once validated and wipes it on reset', async () => {
    const wrapper = mount(CardForm, { attachTo: document.body })
    await wrapper.get('input[autocomplete="cc-number"]').setValue('4242424242424242')
    await wrapper.get('input[autocomplete="cc-exp"]').setValue('1235')
    await wrapper.get('input[autocomplete="cc-csc"]').setValue('737')
    await wrapper.get('input[autocomplete="cc-name"]').setValue('  Ana Mora ')

    const card = (wrapper.vm as unknown as { collect: () => unknown }).collect()
    expect(card).toEqual({
      number: '4242424242424242',
      expMonth: 12,
      expYear: 2035,
      cvv: '737',
      holderName: 'Ana Mora',
    })

    ;(wrapper.vm as unknown as { reset: () => void }).reset()
    await flushPromises()
    for (const token of FIELDS) {
      expect(wrapper.get<HTMLInputElement>(`input[autocomplete="${token}"]`).element.value).toBe('')
    }
    wrapper.unmount()
  })

  it('rejects a past date and focuses the first invalid field', async () => {
    const wrapper = mount(CardForm, { attachTo: document.body })
    await wrapper.get('input[autocomplete="cc-number"]').setValue('4242424242424242')
    await wrapper.get('input[autocomplete="cc-exp"]').setValue('0120')

    const card = (wrapper.vm as unknown as { collect: () => unknown }).collect()

    expect(card).toBeNull()
    await flushPromises()
    expect(wrapper.text()).toContain('Ingrese el vencimiento como MM/AA')
    expect(document.activeElement).toBe(wrapper.get('input[autocomplete="cc-exp"]').element)
    wrapper.unmount()
  })

  it('shows an error that only the processor can tell under the number and blocks the fields while disabled', () => {
    const wrapper = mount(CardForm, {
      props: { error: 'No pudimos validar su tarjeta.', disabled: true },
    })

    expect(wrapper.text()).toContain('No pudimos validar su tarjeta.')
    expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined()
  })
})

describe('SinpeForm', () => {
  it('gives the 8-digit number the +506 prefix and returns the details', async () => {
    const wrapper = mount(SinpeForm, { attachTo: document.body })
    await wrapper.get('input[autocomplete="tel"]').setValue('8888 8888')
    await wrapper.get('select').setValue('3')
    await wrapper.get('input[autocomplete="off"]').setValue(' 3-101-123456 ')

    expect((wrapper.vm as unknown as { collect: () => unknown }).collect()).toEqual({
      phone: '+50688888888',
      idType: '3',
      identification: '3-101-123456',
    })
    wrapper.unmount()
  })

  it('names every missing field and returns nothing', async () => {
    const wrapper = mount(SinpeForm, { attachTo: document.body })

    expect((wrapper.vm as unknown as { collect: () => unknown }).collect()).toBeNull()
    await flushPromises()

    expect(wrapper.text()).toContain('Ingrese los 8 dígitos de su teléfono')
    expect(wrapper.text()).toContain('Elija el tipo de identificación.')
    expect(document.activeElement).toBe(wrapper.get('input[autocomplete="tel"]').element)
    wrapper.unmount()
  })

  it('shows the messages the API gives for each field', () => {
    const wrapper = mount(SinpeForm, {
      props: { errors: { phone: 'Teléfono inválido.', idType: 'Tipo inválido.' } },
    })

    expect(wrapper.text()).toContain('Teléfono inválido.')
    expect(wrapper.text()).toContain('Tipo inválido.')
    expect(wrapper.get('select').attributes('aria-invalid')).toBe('true')
  })
})

describe('SinpeForm: what each field takes', () => {
  const phone = (w: ReturnType<typeof mount>) =>
    w.get<HTMLInputElement>('input[autocomplete="tel"]')
  const identification = (w: ReturnType<typeof mount>) =>
    w.get<HTMLInputElement>('input[autocomplete="off"]')

  it('shows the phone as 1234-5678, with digits only and no more than 8', async () => {
    const wrapper = mount(SinpeForm)

    await phone(wrapper).setValue('12a34')
    expect(phone(wrapper).element.value).toBe('1234')
    await phone(wrapper).setValue('1234567890')
    expect(phone(wrapper).element.value).toBe('1234-5678')
    expect(phone(wrapper).attributes('placeholder')).toBe('1234-5678')
  })

  it('drops the +506 of an autofilled phone', async () => {
    const wrapper = mount(SinpeForm)

    await phone(wrapper).setValue('+506 8888 8888')

    expect(phone(wrapper).element.value).toBe('8888-8888')
  })

  it('does not take the identification until the type is chosen', () => {
    const wrapper = mount(SinpeForm)

    expect(identification(wrapper).attributes('disabled')).toBeDefined()
    expect(identification(wrapper).attributes('placeholder')).toBe('Elija primero el tipo')
  })

  it.each([
    ['0', '113931919', '1-1393-1919', '1-1393-1919'],
    ['0', '0113931919', '01-1393-1919', '1-1393-1919'],
    ['1', '15581234567890', '155812345678', '155812345678'],
    ['3', '3101123456', '3-101-123456', '3-101-123456'],
    ['9', 'ab-123 456x', 'AB123456X', 'AB1234567'],
  ])('type %s: «%s» becomes «%s» (placeholder %s)', async (type, typed, shown, placeholder) => {
    const wrapper = mount(SinpeForm)
    await wrapper.get('select').setValue(type)

    await identification(wrapper).setValue(typed)

    expect(identification(wrapper).element.value).toBe(shown)
    expect(identification(wrapper).attributes('placeholder')).toBe(placeholder)
  })

  it('keeps letters out of the numeric types and symbols out of the foreign one', async () => {
    const wrapper = mount(SinpeForm)
    await wrapper.get('select').setValue('3')
    await identification(wrapper).setValue('3a-1b01')
    expect(identification(wrapper).element.value).toBe('3-101')

    await wrapper.get('select').setValue('9')
    await identification(wrapper).setValue('a-b#1')
    expect(identification(wrapper).element.value).toBe('AB1')
  })

  it('reformats what was typed when the type changes', async () => {
    const wrapper = mount(SinpeForm)
    await wrapper.get('select').setValue('1')
    await identification(wrapper).setValue('155812345678')

    await wrapper.get('select').setValue('3')

    expect(identification(wrapper).element.value).toBe('1-558-123456')
  })

  it('asks for the whole identification of its type', async () => {
    const wrapper = mount(SinpeForm, { attachTo: document.body })
    await phone(wrapper).setValue('88888888')
    await wrapper.get('select').setValue('3')
    await identification(wrapper).setValue('3101')

    expect((wrapper.vm as unknown as { collect: () => unknown }).collect()).toBeNull()
    await flushPromises()

    expect(wrapper.text()).toContain(
      'Ingrese su identificación completa, por ejemplo 3-101-123456.',
    )
    wrapper.unmount()
  })
})

describe('CardForm: what each field takes', () => {
  it('takes letters in the name and no digits or symbols', async () => {
    const wrapper = mount(CardForm)
    const name = wrapper.get<HTMLInputElement>('input[autocomplete="cc-name"]')

    await name.setValue("Ana María O'Brien-Mora 123 @#")

    expect(name.element.value).toBe("Ana María O'Brien-Mora  ")
  })

  it('limits each field to what it needs', () => {
    const wrapper = mount(CardForm)
    const max = (token: string) =>
      wrapper.get(`input[autocomplete="${token}"]`).attributes('maxlength')

    expect(max('cc-number')).toBe('23')
    expect(max('cc-exp')).toBe('5')
    expect(max('cc-csc')).toBe('4')
    expect(max('cc-name')).toBe('40')
  })
})

/** Types one key after the others, as a person does (each key goes through the input event). */
async function typeKeys(
  input: { element: HTMLInputElement; trigger: (e: string) => Promise<void> },
  keys: string,
) {
  for (const key of keys) {
    input.element.value += key
    await input.trigger('input')
  }
}

describe('typing key by key leaves nothing that does not belong', () => {
  it('card number, expiry and CVV take only digits', async () => {
    const wrapper = mount(CardForm)
    const field = (token: string) => wrapper.get<HTMLInputElement>(`input[autocomplete="${token}"]`)

    await typeKeys(field('cc-number'), '4242a-4242 b')
    await typeKeys(field('cc-exp'), '12x/3y5')
    await typeKeys(field('cc-csc'), '7a3-7')

    expect(field('cc-number').element.value).toBe('4242 4242')
    expect(field('cc-exp').element.value).toBe('12/35')
    expect(field('cc-csc').element.value).toBe('737')
  })

  it('the name takes no digits', async () => {
    const wrapper = mount(CardForm)
    const name = wrapper.get<HTMLInputElement>('input[autocomplete="cc-name"]')

    await typeKeys(name, 'José 99 Mora7')

    expect(name.element.value).toBe('José  Mora')
  })

  it('SINPE phone and identification take what belongs to them', async () => {
    const wrapper = mount(SinpeForm)
    const phone = wrapper.get<HTMLInputElement>('input[autocomplete="tel"]')
    await wrapper.get('select').setValue('3')
    const id = wrapper.get<HTMLInputElement>('input[autocomplete="off"]')

    await typeKeys(phone, '88a88x-8888')
    await typeKeys(id, '3a101-b123456')

    expect(phone.element.value).toBe('8888-8888')
    expect(id.element.value).toBe('3-101-123456')
  })
})

describe('SinpeInstructions', () => {
  const props = { destination: '+506 70196686', amount: 3333 }

  it('shows the destination and the exact amount', () => {
    const wrapper = mount(SinpeInstructions, { props })

    expect(wrapper.text()).toContain('+506 70196686')
    expect(wrapper.text()).toContain('₡3.333')
  })

  it('copies each datum and says so in a live region and on the button', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    const wrapper = mount(SinpeInstructions, { props })

    await wrapper.get('[aria-label="Copiar número de destino"]').trigger('click')
    await flushPromises()
    expect(writeText).toHaveBeenCalledWith('+506 70196686')
    expect(wrapper.get('[aria-label="Copiar número de destino"]').text()).toBe('Copiado')
    expect(wrapper.get('[aria-live="polite"]').text()).toBe('Número de destino copiado.')

    await wrapper.get('[aria-label="Copiar monto"]').trigger('click')
    await flushPromises()
    expect(writeText).toHaveBeenLastCalledWith('3333')
    expect(wrapper.get('[aria-live="polite"]').text()).toBe('Monto copiado.')
  })

  it('says it could not copy when the browser refuses', async () => {
    vi.stubGlobal('navigator', {
      clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) },
    })
    const wrapper = mount(SinpeInstructions, { props })

    await wrapper.get('[aria-label="Copiar monto"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('No se pudo copiar.')
  })

  it('clears its timer on unmount', async () => {
    vi.useFakeTimers()
    vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn().mockResolvedValue(undefined) } })
    const wrapper = mount(SinpeInstructions, { props })
    await wrapper.get('[aria-label="Copiar monto"]').trigger('click')
    await vi.advanceTimersByTimeAsync(0)
    expect(vi.getTimerCount()).toBe(1)

    wrapper.unmount()

    expect(vi.getTimerCount()).toBe(0)
  })
})

describe('PaymentStatus', () => {
  it('shows a skeleton while the status is read the first time', () => {
    const wrapper = mount(PaymentStatus, { props: { state: null } })

    expect(wrapper.text()).toContain('Verificando su pago…')
    expect(wrapper.get('[aria-busy="true"]').exists()).toBe(true)
  })

  it('waits for a pending payment and lets the person check now', async () => {
    const wrapper = mount(PaymentStatus, { props: { state: 'pendiente' } })

    expect(wrapper.text()).toContain('Esperando la confirmación de su pago…')
    expect(wrapper.text()).toContain('Pago pendiente')
    await wrapper.get('button').trigger('click')
    expect(wrapper.emitted('check')).toHaveLength(1)
  })

  it('says an approved payment and the refund when it cannot confirm the reservation', () => {
    const wrapper = mount(PaymentStatus, {
      props: { state: 'aprobado', amount: 3333, refundPending: true },
    })

    expect(wrapper.text()).toContain('Su pago de ₡3.333 fue aprobado.')
    expect(wrapper.text()).toContain('el pago se le devolverá')
    // The banner already says it: no extra label with a dot that cannot be told from the text.
    expect(wrapper.text()).not.toContain('Pago aprobado')
  })

  it.each(['rechazado', 'fallido'] as const)(
    'shows the reason of a %s payment and offers another method',
    async (state) => {
      const wrapper = mount(PaymentStatus, { props: { state, reason: 'Fondos insuficientes.' } })

      expect(wrapper.get('[role="alert"]').text()).toContain('Fondos insuficientes.')
      await wrapper.get('button').trigger('click')
      expect(wrapper.emitted('retry')).toHaveLength(1)
    },
  )

  it('says what happens when the provider does not answer', async () => {
    const wrapper = mount(PaymentStatus, { props: { state: 'pendiente', unreachable: true } })

    expect(wrapper.text()).toContain('El proveedor de pago no responde.')
    expect(wrapper.text()).toContain('no se cobrará dos veces')
    await wrapper.get('button').trigger('click')
    expect(wrapper.emitted('check')).toHaveLength(1)
  })
})
