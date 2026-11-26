import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

import OfferPrompt from '@/components/OfferPrompt.vue'
import OfferSkipLink from '@/components/OfferSkipLink.vue'
import { useOffersStore } from '@/stores/offers'
import { useSessionStore } from '@/stores/session'
import { installFakeEcho, resetFakeEcho, type FakeEcho } from '../helpers/echo'
import { jsonResponse, problem } from '../helpers/http'
import { mountView } from '../helpers/mountView'
import {
  BASE,
  OFFER_ID,
  RESERVATION_ID,
  USER_ID,
  closed,
  created,
  mockOffersApi,
  offer,
  signInAs,
} from '../helpers/offers'
import { bought } from '../helpers/vouchers'

const blank = defineComponent({ render: () => h('p', 'pantalla') })

/** What `App` puts on every screen: the skip link, the screen (with a field to type in) and the pop-up. */
const shell = defineComponent({
  render: () => [
    h(OfferSkipLink),
    h('main', [h('input', { id: 'card', 'aria-label': 'Número de tarjeta' })]),
    h(OfferPrompt),
  ],
})

let echo: FakeEcho
const mounted: Array<{ unmount: () => void }> = []

beforeEach(() => {
  signInAs()
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
  echo = installFakeEcho()
})
afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount())
  resetFakeEcho()
  vi.restoreAllMocks()
  vi.useRealTimers()
  vi.unstubAllGlobals()
  sessionStorage.clear()
  document.body.innerHTML = ''
})

async function open(api: Parameters<typeof mockOffersApi>[0] = {}) {
  const mocked = mockOffersApi({ offers: () => [offer()], ...api })
  const view = await mountView(shell, '/blank', [{ path: '/blank', component: blank }])
  mounted.push(view.wrapper)
  useSessionStore().setUserId(USER_ID)
  await flushPromises()
  return { ...view, ...mocked, store: useOffersStore() }
}

/** An offer arrives through the channel and the pop-up shows it. */
async function arrive(over: Record<string, unknown> = {}) {
  echo.emitEvent('.oferta.creada', created(over))
  await flushPromises()
}

const dialog = () => document.querySelector<HTMLElement>('[role="dialog"]')
const buttonOf = (text: string) =>
  [...document.querySelectorAll<HTMLButtonElement>('button')].find(
    (b) => b.textContent?.trim() === text,
  )

describe('the pop-up of an offer', () => {
  it('shows nothing until an offer arrives, then a dialog that is not modal', async () => {
    await open()
    expect(dialog()).toBeNull()

    await arrive()

    const box = dialog()!
    expect(box.getAttribute('aria-label')).toBe('Oferta de la lista de espera')
    // Not modal: the rest of the page stays usable.
    expect(box.hasAttribute('aria-modal')).toBe(false)
    expect(box.textContent).toContain('Se liberó un espacio')
    expect(box.textContent).toContain('Corte clásico con Mauricio')
    expect(box.textContent).toContain('Pura Navaja')
    expect(box.textContent).toContain('mar 5 mar')
    expect(box.textContent).toContain('10:00–10:35')
    // No price: the offer carries none and the pop-up does not ask for it.
    expect(box.textContent).not.toContain('₡')
  })

  it('uses the barber that the API names, and the day only when it sends null', async () => {
    const { requests } = await open({ offers: () => [offer({ barbero_nombre: 'Daniela' })] })
    await arrive()

    expect(dialog()!.textContent).toContain('Corte clásico con Daniela')
    expect(requests.filter((item) => item.endsWith('/disponibilidad'))).toEqual([])
  })

  it('falls back to the name that the day lists when the API sends null, and to none', async () => {
    const { requests } = await open({ offers: () => [offer({ barbero_nombre: null })] })
    await arrive()

    expect(dialog()!.textContent).toContain('Corte clásico con Mauricio')
    expect(requests.filter((item) => item.endsWith('/disponibilidad'))).toHaveLength(1)
  })

  it('shows the offer without «con …» when nobody can name the barber', async () => {
    await open({ offers: () => [offer({ barbero_nombre: null })], barbers: [] })
    await arrive()

    expect(dialog()!.textContent).toContain('Corte clásico')
    expect(dialog()!.textContent).not.toContain(' con ')
  })

  it('counts down to the deadline of the offer', async () => {
    await open()
    await arrive()
    expect(dialog()!.querySelector('[role="timer"]')!.textContent).toContain('15:00')

    await vi.advanceTimersByTimeAsync(61_000)

    expect(dialog()!.querySelector('[role="timer"]')!.textContent).toContain('13:59')
  })

  it('does not take the focus: the person keeps typing where they were', async () => {
    await open()
    const field = document.getElementById('card') as HTMLInputElement
    field.focus()

    await arrive()

    expect(dialog()).not.toBeNull()
    expect(document.activeElement).toBe(field)
  })

  it('announces it to the screen readers once, with a polite note for the last two minutes', async () => {
    await open()
    await arrive()

    const live = [...document.querySelectorAll('[aria-live]')]
    const assertive = live.find((item) => item.getAttribute('aria-live') === 'assertive')!
    const polite = live.find((item) => item.getAttribute('aria-live') === 'polite')!
    expect(assertive.textContent).toContain('Se liberó un espacio en Pura Navaja')
    expect(polite.textContent).toBe('')

    await vi.advanceTimersByTimeAsync((13 * 60 + 1) * 1000)

    expect(polite.textContent).toContain('Quedan menos de dos minutos')
    expect(dialog()!.textContent).toContain('Quedan menos de dos minutos para responder.')
  })

  it('«Ir al aviso» is the first stop of the keyboard and moves the focus to the pop-up', async () => {
    await open()
    expect(document.body.textContent).not.toContain('Ir al aviso')
    await arrive()

    const first = document.querySelector<HTMLElement>('a[href], button, input')!
    expect(first.textContent?.trim()).toBe('Ir al aviso')
    first.click()

    expect(document.activeElement).toBe(dialog())
  })

  it('has no movement of its own, so reduced motion has nothing to turn off', async () => {
    await open()
    await arrive()

    expect(dialog()!.className).not.toMatch(/animate|transition-(all|transform)|duration/)
  })
})

describe('hiding and answering', () => {
  it('Escape only hides it: nothing is rejected and the offer is still there', async () => {
    const { store, requests } = await open()
    await arrive()
    const field = document.getElementById('card') as HTMLInputElement
    field.focus()
    buttonOf('Aceptar')!.focus()

    dialog()!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()

    expect(dialog()).toBeNull()
    expect(store.find(OFFER_ID)?.hidden).toBe(true)
    expect(requests.filter((item) => item.startsWith('POST'))).toEqual([])
  })

  it('«Ocultar el aviso» does the same and gives the focus back to where it came from', async () => {
    await open()
    await arrive()
    const field = document.getElementById('card') as HTMLInputElement
    field.focus()
    const close = document.querySelector<HTMLButtonElement>('[aria-label="Ocultar el aviso"]')!
    close.dispatchEvent(new FocusEvent('focusin', { bubbles: true, relatedTarget: field }))
    close.focus()

    close.click()
    await flushPromises()

    expect(dialog()).toBeNull()
    expect(document.activeElement).toBe(field)
  })

  it('«Rechazar» sends the answer and the pop-up leaves', async () => {
    const { requests } = await open()
    await arrive()

    buttonOf('Rechazar')!.click()
    await flushPromises()

    expect(requests).toContain(`POST ${BASE}/ofertas/${OFFER_ID}/rechazo`)
    expect(dialog()).toBeNull()
  })

  it('«Aceptar» with a reservation that is confirmed goes to its confirmation, without waiting for the event', async () => {
    const { requests, router } = await open()
    await arrive()

    buttonOf('Aceptar')!.click()
    await flushPromises()

    expect(requests).toContain(`POST ${BASE}/ofertas/${OFFER_ID}/aceptacion`)
    expect(dialog()).toBeNull()
    // The screen of the next route is loaded lazily.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('booking-confirmed'))
    expect(router.currentRoute.value.params).toMatchObject({
      enlace: 'pura-navaja',
      reserva: RESERVATION_ID,
    })
  })

  it('«Aceptar» with an amount to pay goes to the payment, and the pop-up is already gone', async () => {
    const { router, store } = await open({
      accept: () => jsonResponse(200, { data: offer({ reserva: RESERVATION_ID }) }),
    })
    await arrive()

    buttonOf('Aceptar')!.click()
    await flushPromises()

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('payment'))
    expect(router.currentRoute.value.params.reserva).toBe(RESERVATION_ID)
    expect(dialog()).toBeNull()
    // The offer stays pending until the gateway confirms: the event only closes what is still open.
    expect(store.find(OFFER_ID)?.phase).toBe('paying')
    echo.emitEvent('.oferta.cerrada', closed('aceptada'))
    await flushPromises()
    expect(dialog()).toBeNull()
  })

  it('offers «Usar bono» only when a voucher covers the service, and sends `con_bono`', async () => {
    const { fetchMock } = await open({ vouchers: [bought()] })
    await arrive()

    expect(buttonOf('Usar bono')).toBeDefined()
    buttonOf('Usar bono')!.click()
    await flushPromises()

    const request = fetchMock.mock.calls.find(([r]) => r.url.endsWith('/aceptacion'))![0]
    expect(await request.clone().json()).toEqual({ con_bono: true })
  })

  it.each([
    ['no voucher', []],
    ['a voucher of another service', [bought({ servicios: [{ id: 's9', nombre: 'Tinte' }] })]],
    ['a voucher without sessions', [bought({ saldo: 0 })]],
  ])('does not offer «Usar bono» with %s', async (_name, vouchers) => {
    await open({ vouchers })
    await arrive()

    expect(buttonOf('Aceptar')).toBeDefined()
    expect(buttonOf('Usar bono')).toBeUndefined()
  })

  it('a failure stays in the pop-up with its message and lets the person try again', async () => {
    await open({
      vouchers: [bought()],
      accept: () =>
        problem(409, { type: 'http://localhost/problemas/saldo-insuficiente', detail: 'x' }),
    })
    await arrive()

    buttonOf('Usar bono')!.click()
    await flushPromises()

    expect(dialog()!.querySelector('[role="alert"]')!.textContent).toContain(
      'Su bono ya no tiene sesiones disponibles',
    )
    expect(buttonOf('Aceptar')!.disabled).toBe(false)
  })
})

describe('when the offer is over', () => {
  it('an expired offer says so clearly and is forgotten with «Entendido»', async () => {
    const { store } = await open()
    await arrive()

    echo.emitEvent('.oferta.cerrada', closed('vencida'))
    await flushPromises()

    expect(dialog()!.textContent).toContain('La oferta venció.')
    expect(buttonOf('Aceptar')).toBeUndefined()
    buttonOf('Entendido')!.click()
    await flushPromises()
    expect(dialog()).toBeNull()
    expect(store.find(OFFER_ID)).toBeUndefined()
  })

  it('the clock reaching zero blocks the answers before the API says anything', async () => {
    await open()
    await arrive()

    await vi.advanceTimersByTimeAsync(15 * 60 * 1000 + 1000)

    expect(dialog()!.textContent).toContain('La oferta venció.')
    expect(buttonOf('Aceptar')).toBeUndefined()
  })

  it('an offer accepted on another device is told as such', async () => {
    await open()
    await arrive()

    echo.emitEvent('.oferta.cerrada', closed('aceptada'))
    await flushPromises()

    expect(dialog()!.textContent).toContain('Esta oferta ya fue aceptada.')
  })

  it('when the person leaves, the pop-up goes with the session', async () => {
    await open()
    await arrive()

    useSessionStore().clear()
    await flushPromises()

    expect(dialog()).toBeNull()
  })

  it('says how many more offers wait behind the one on screen', async () => {
    await open({
      offers: () => [offer(), offer({ id: 'otra-oferta', fila: 'otra-fila' })],
    })

    await arrive()

    expect(dialog()!.textContent).toContain('Tiene otra oferta esperando.')
    expect(dialog()!.textContent).toContain('Ver mis filas')
  })
})
