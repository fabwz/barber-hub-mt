import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { signIn } from '../helpers/account'
import { problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import { BILLING, FEATURES, settingsApi } from '../helpers/settings'
import { SHOP_A } from '../helpers/staff'

beforeEach(() => {
  signIn('marco@example.test')
})
afterEach(() => {
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

async function open(options: Parameters<typeof settingsApi>[0] = {}) {
  const api = settingsApi(options)
  const mounted = await mountView(App, `/barberias/${SHOP_A}/configuracion/cobro`)
  await flushPromises()
  return { ...mounted, ...api }
}

type Wrapper = Awaited<ReturnType<typeof open>>['wrapper']

function input(wrapper: Wrapper, label: string): HTMLInputElement {
  const field = wrapper.findAll('label').find((item) => item.text() === label)
  return wrapper.get(`#${field!.attributes('for')}`).element as HTMLInputElement
}

async function type(wrapper: Wrapper, label: string, value: string) {
  const element = input(wrapper, label)
  element.value = value
  element.dispatchEvent(new Event('input'))
  await flushPromises()
}

async function pick(wrapper: Wrapper, value: string) {
  await wrapper.get(`[role="radio"][data-value="${value}"]`).trigger('click')
  await flushPromises()
}

async function save(wrapper: Wrapper) {
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

const switchOf = (wrapper: Wrapper, name: string) =>
  wrapper
    .findAll('[role="switch"]')
    .find(
      (item) =>
        item.attributes('aria-labelledby') !== undefined &&
        wrapper.get(`#${item.attributes('aria-labelledby')}`).text() === name,
    )!

describe('SettingsBillingView: loading', () => {
  it('shows a skeleton while it loads, then the policy and the switches', async () => {
    let release: () => void = () => {}
    const gate = new Promise<void>((resolve) => (release = resolve))
    settingsApi({
      override: async (request) => {
        if (request.url.endsWith('/administracion/cobro')) await gate
        return undefined
      },
    })
    const { wrapper } = await mountView(App, `/barberias/${SHOP_A}/configuracion/cobro`)
    await flushPromises()
    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true)

    release()
    await vi.waitFor(() => expect(wrapper.find('[aria-busy="true"]').exists()).toBe(false))
    expect(wrapper.get('[role="radio"][data-value="deposito"]').attributes('aria-checked')).toBe(
      'true',
    )
    expect(input(wrapper, 'Porcentaje del depósito (%)').value).toBe('30')
    expect(wrapper.get('[role="radio"][data-value="onvo"]').attributes('aria-checked')).toBe('true')
    expect(input(wrapper, 'Plazo, en horas antes de la reserva').value).toBe('4')
    expect(switchOf(wrapper, 'Venta de bonos').attributes('aria-checked')).toBe('true')
    expect(switchOf(wrapper, 'Lista de espera').attributes('aria-checked')).toBe('true')
  })

  it('says that a change applies to the new bookings', async () => {
    const { wrapper } = await open()
    expect(wrapper.text()).toContain('Un cambio aplica a las reservas nuevas.')
    expect(wrapper.text()).toContain('Las que ya están hechas conservan lo que tenían.')
  })

  it('shows the error with «Reintentar» and loads again', async () => {
    let fail = true
    const { wrapper } = await open({
      override: (request) =>
        fail && request.url.endsWith('/administracion/cobro') ? problem(500) : undefined,
    })
    expect(wrapper.text()).toContain('No pudimos cargar la configuración de cobro')

    fail = false
    await wrapper
      .findAll('button')
      .find((button) => button.text() === 'Reintentar')!
      .trigger('click')
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(true)
  })
})

describe('SettingsBillingView: the mode', () => {
  it('«Sin pago» hides the percentage and the gateway selector and says why', async () => {
    const { wrapper } = await open()
    await pick(wrapper, 'sin_pago')

    expect(wrapper.text()).toContain('Sin pago no hay pasarela: la reserva se confirma sin cobro.')
    expect(wrapper.find('[data-value="onvo"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Porcentaje del depósito')
  })

  it('«Pago total» shows the gateway and hides the percentage', async () => {
    const { wrapper } = await open()
    await pick(wrapper, 'total')

    expect(wrapper.text()).not.toContain('Porcentaje del depósito')
    expect(wrapper.find('[data-value="paypal"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Tarjeta y SINPE Móvil.')
  })

  it('does not send the gateway with «Sin pago» and does not try to clear the old one', async () => {
    const { wrapper, bodies } = await open()
    await pick(wrapper, 'sin_pago')
    await save(wrapper)

    expect(bodies).toEqual([{ modalidad: 'sin_pago' }])
    expect(wrapper.text()).toContain('Cambios guardados. Aplican a las reservas nuevas.')
  })

  it('switches to the total with PayPal sending the mode and the gateway', async () => {
    const { wrapper, bodies } = await open()
    await pick(wrapper, 'total')
    await pick(wrapper, 'paypal')
    await save(wrapper)

    expect(bodies).toEqual([{ modalidad: 'total', pasarela: 'paypal' }])
  })
})

describe('SettingsBillingView: checks before the trip', () => {
  it.each([['5'], ['120'], ['']])('rejects a deposit of «%s» %%', async (value) => {
    const { wrapper, calls } = await open()
    await type(wrapper, 'Porcentaje del depósito (%)', value)
    await save(wrapper)

    expect(wrapper.text()).toContain('El porcentaje debe estar entre 10 y 100 %.')
    expect(input(wrapper, 'Porcentaje del depósito (%)').getAttribute('aria-invalid')).toBe('true')
    expect(calls.some((call) => call.startsWith('PATCH'))).toBe(false)
  })

  it('accepts 30 % and 100 %', async () => {
    const { wrapper, bodies } = await open()
    await type(wrapper, 'Porcentaje del depósito (%)', '100')
    await save(wrapper)
    expect(bodies).toEqual([{ porcentaje_deposito: 100 }])
  })

  it('keeps only digits in the percentage and the deadline', async () => {
    const { wrapper } = await open()
    await type(wrapper, 'Porcentaje del depósito (%)', '4a5')
    await type(wrapper, 'Plazo, en horas antes de la reserva', '1-2')
    expect(input(wrapper, 'Porcentaje del depósito (%)').value).toBe('45')
    expect(input(wrapper, 'Plazo, en horas antes de la reserva').value).toBe('12')
  })

  it('rejects a deadline over 720 hours and accepts 0', async () => {
    const { wrapper, bodies } = await open()
    await type(wrapper, 'Plazo, en horas antes de la reserva', '721')
    await save(wrapper)
    expect(wrapper.text()).toContain('El plazo debe estar entre 0 y 720 horas.')
    expect(bodies).toEqual([])

    await type(wrapper, 'Plazo, en horas antes de la reserva', '0')
    await save(wrapper)
    expect(bodies).toEqual([{ plazo_cancelacion_horas: 0 }])
  })

  it('asks for a gateway when a charging mode has none', async () => {
    const { wrapper, calls } = await open({
      billing: { ...BILLING, modalidad: 'sin_pago', porcentaje_deposito: null, pasarela: null },
    })
    await pick(wrapper, 'total')
    await save(wrapper)

    expect(wrapper.text()).toContain('Elija una pasarela para cobrar.')
    expect(calls.some((call) => call.startsWith('PATCH'))).toBe(false)
  })

  it('asks for the percentage when switching to a deposit with none', async () => {
    const { wrapper } = await open({
      billing: { ...BILLING, modalidad: 'total', porcentaje_deposito: null },
    })
    await pick(wrapper, 'deposito')
    await save(wrapper)
    expect(wrapper.text()).toContain('El porcentaje debe estar entre 10 y 100 %.')
  })
})

describe('SettingsBillingView: errors of the API', () => {
  it('puts a 422 with fields in its field', async () => {
    const { wrapper } = await open({
      override: (request) =>
        request.method === 'PATCH'
          ? problem(422, { errores: { plazo_cancelacion_horas: ['El plazo no es válido.'] } })
          : undefined,
    })
    await type(wrapper, 'Plazo, en horas antes de la reserva', '12')
    await save(wrapper)

    expect(wrapper.text()).toContain('El plazo no es válido.')
    expect(input(wrapper, 'Plazo, en horas antes de la reserva').getAttribute('aria-invalid')).toBe(
      'true',
    )
  })

  it('shows a rule between fields, which comes with no field, in the general notice', async () => {
    const { wrapper } = await open({
      override: (request) =>
        request.method === 'PATCH'
          ? problem(422, {
              detail: 'Cobrar un depósito o el pago total exige elegir una pasarela de pago.',
            })
          : undefined,
    })
    await type(wrapper, 'Plazo, en horas antes de la reserva', '12')
    await save(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain(
      'Cobrar un depósito o el pago total exige elegir una pasarela de pago.',
    )
  })

  it('says «No pudimos guardar los cambios» and keeps the form on any other failure', async () => {
    const { wrapper } = await open({
      override: (request) => (request.method === 'PATCH' ? problem(500) : undefined),
    })
    await type(wrapper, 'Plazo, en horas antes de la reserva', '12')
    await save(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain('No pudimos guardar los cambios.')
    expect(wrapper.get('[role="alert"]').text()).toContain('No se cambió nada.')
    expect(input(wrapper, 'Plazo, en horas antes de la reserva').value).toBe('12')
  })
})

describe('SettingsBillingView: the switches', () => {
  it('sends only the switch that changed, after the policy', async () => {
    const { wrapper, calls, bodies } = await open()
    await type(wrapper, 'Plazo, en horas antes de la reserva', '12')
    await switchOf(wrapper, 'Lista de espera').trigger('click')
    await save(wrapper)

    expect(calls.filter((call) => call.startsWith('PATCH'))).toEqual([
      `PATCH /api/v1/barberias/${SHOP_A}/administracion/cobro`,
      `PATCH /api/v1/barberias/${SHOP_A}/administracion/funciones`,
    ])
    expect(bodies).toEqual([{ plazo_cancelacion_horas: 12 }, { lista_espera_activa: false }])
  })

  it('warns that selling vouchers needs a gateway only while the gateway is null', async () => {
    const withoutGateway = await open({
      billing: { ...BILLING, modalidad: 'sin_pago', porcentaje_deposito: null, pasarela: null },
    })
    expect(withoutGateway.wrapper.text()).toContain('Para vender bonos necesita una pasarela')
    withoutGateway.wrapper.unmount()

    const withGateway = await open({
      billing: { ...BILLING, modalidad: 'sin_pago', porcentaje_deposito: null, pasarela: 'onvo' },
    })
    expect(withGateway.wrapper.text()).not.toContain('Para vender bonos necesita una pasarela')
  })

  it('does not warn when the vouchers are off', async () => {
    const { wrapper } = await open({
      features: { ...FEATURES, bonos_activos: false },
      billing: { ...BILLING, modalidad: 'sin_pago', porcentaje_deposito: null, pasarela: null },
    })
    expect(wrapper.text()).not.toContain('Para vender bonos necesita una pasarela')
  })

  it('says that turning a function off does not cancel what is in course', async () => {
    const { wrapper } = await open()
    expect(wrapper.text()).toContain('Desactivar una función no cancela lo que ya está en curso')
  })
})

describe('SettingsBillingView: partial save', () => {
  it('says the policy was saved and the functions were not, and a new try sends only the functions', async () => {
    let failFeatures = true
    const { wrapper, calls } = await open({
      override: (request) =>
        failFeatures && request.method === 'PATCH' && request.url.endsWith('/funciones')
          ? problem(500)
          : undefined,
    })
    await type(wrapper, 'Plazo, en horas antes de la reserva', '12')
    await switchOf(wrapper, 'Venta de bonos').trigger('click')
    await save(wrapper)

    const alert = wrapper.get('[role="alert"]').text()
    expect(alert).toContain('Se guardó la política de cobro.')
    expect(alert).toContain('Falta guardar las funciones.')
    expect(input(wrapper, 'Plazo, en horas antes de la reserva').value).toBe('12')
    expect(switchOf(wrapper, 'Venta de bonos').attributes('aria-checked')).toBe('false')

    calls.length = 0
    failFeatures = false
    await save(wrapper)
    expect(calls.filter((call) => call.startsWith('PATCH'))).toEqual([
      `PATCH /api/v1/barberias/${SHOP_A}/administracion/funciones`,
    ])
    expect(wrapper.text()).toContain('Cambios guardados. Aplican a las reservas nuevas.')
  })
})
