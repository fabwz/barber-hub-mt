import { afterEach, describe, expect, it } from 'vitest'

import { expectKeyboardReady, expectNoViolations } from './helpers'

// The helpers must fail when something is broken: a test that never fails proves nothing.
function render(html: string): void {
  document.documentElement.lang = 'es-CR'
  document.title = 'BarberHub'
  document.body.innerHTML = html
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('accessibility helpers', () => {
  it('accept a screen that is in order', async () => {
    render(`<main><h1>Título</h1><label>Nombre <input /></label><button>Guardar</button></main>`)

    await expectNoViolations(document)
    expectKeyboardReady(document)
  })

  it.each([
    ['a button without a name', '<button></button>'],
    ['an image without alt', '<img src="x.png" />'],
    ['a field without a label', '<input type="text" />'],
    ['a link without a name', '<a href="/x"></a>'],
  ])('axe fails on %s and says which rule and node', async (_name, html) => {
    render(`<main><h1>Título</h1>${html}</main>`)

    await expect(expectNoViolations(document)).rejects.toThrow(/\[\w+\] [\w-]+: .+\n\s+- /)
  })

  it('the keyboard check fails on a custom control that cannot take focus', () => {
    render(`<main><h1>Título</h1><div role="button">Pagar</div></main>`)

    expect(() => expectKeyboardReady(document)).toThrow(/cannot take focus/)
  })

  it('the keyboard check fails on a control without a name', () => {
    render(`<main><h1>Título</h1><button><svg></svg></button></main>`)

    expect(() => expectKeyboardReady(document)).toThrow(/no accessible name/)
  })

  it('the keyboard check fails on a positive tabindex', () => {
    render(`<main><h1>Título</h1><button tabindex="3">Pagar</button></main>`)

    expect(() => expectKeyboardReady(document)).toThrow(/positive tabindex/)
  })

  it('the keyboard check ignores what is hidden or disabled', () => {
    render(
      `<main><h1>Título</h1><div style="display: none"><button></button></div><button disabled></button><button aria-hidden="true"></button></main>`,
    )

    expect(() => expectKeyboardReady(document)).not.toThrow()
  })
})
