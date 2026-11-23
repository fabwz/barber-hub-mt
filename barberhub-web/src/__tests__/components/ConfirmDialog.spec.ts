import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'

import ConfirmDialog from '@/components/ConfirmDialog.vue'

afterEach(() => {
  document.body.innerHTML = ''
})

/** A button that opens the dialog, so the focus has somewhere to come back to. */
function mountWithOpener(props: { loading?: boolean } = {}) {
  const confirmed = ref(0)
  const cancelled = ref(0)
  const open = ref(false)
  const host = defineComponent({
    setup: () => () =>
      h('div', [
        h('button', { id: 'opener', onClick: () => (open.value = true) }, 'Abrir'),
        h(ConfirmDialog, {
          modelValue: open.value,
          'onUpdate:modelValue': (value: boolean) => (open.value = value),
          title: '¿Inactivar «Rapado»?',
          description: 'Dejará de poder reservarse.',
          cancelLabel: 'No, mantenerlo activo',
          confirmLabel: 'Sí, inactivar',
          loading: props.loading ?? false,
          onConfirm: () => confirmed.value++,
          onCancel: () => cancelled.value++,
        }),
      ]),
  })
  const wrapper = mount(host, { attachTo: document.body })
  return { wrapper, open, confirmed, cancelled }
}

async function openIt(wrapper: ReturnType<typeof mountWithOpener>['wrapper']) {
  const opener = wrapper.get('#opener').element as HTMLButtonElement
  opener.focus()
  await wrapper.get('#opener').trigger('click')
  await nextTick()
  await nextTick()
}

function buttons(): HTMLButtonElement[] {
  return Array.from(document.body.querySelectorAll('[role="alertdialog"] button'))
}

describe('ConfirmDialog', () => {
  it('renders nothing while closed', () => {
    mountWithOpener()
    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
  })

  it('is a modal dialog named by its title and described by its text', async () => {
    const { wrapper } = mountWithOpener()
    await openIt(wrapper)
    const dialog = document.body.querySelector('[role="alertdialog"]')!
    expect(dialog.getAttribute('aria-modal')).toBe('true')
    const title = document.getElementById(dialog.getAttribute('aria-labelledby')!)!
    expect(title.textContent).toBe('¿Inactivar «Rapado»?')
    const description = document.getElementById(dialog.getAttribute('aria-describedby')!)!
    expect(description.textContent).toBe('Dejará de poder reservarse.')
  })

  it('puts the focus on the safe answer', async () => {
    const { wrapper } = mountWithOpener()
    await openIt(wrapper)
    expect(document.activeElement?.textContent).toContain('No, mantenerlo activo')
  })

  it('traps the focus: Tab wraps from the last button to the first and Shift+Tab back', async () => {
    const { wrapper } = mountWithOpener()
    await openIt(wrapper)
    const [confirm, cancel] = buttons()
    const backdrop = document.body.querySelector('[data-testid="dialog-backdrop"]')!

    // Order in the page: «Sí, inactivar» then «No, mantenerlo activo» (the safe answer is last).
    cancel!.focus()
    backdrop.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }))
    expect(document.activeElement).toBe(confirm)

    backdrop.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true }),
    )
    expect(document.activeElement).toBe(cancel)
  })

  it('Escape cancels, closes and gives the focus back to what opened it', async () => {
    const { wrapper, open, cancelled } = mountWithOpener()
    await openIt(wrapper)
    document.body
      .querySelector('[data-testid="dialog-backdrop"]')!
      .dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await nextTick()
    await nextTick()
    expect(open.value).toBe(false)
    expect(cancelled.value).toBe(1)
    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
    expect(document.activeElement).toBe(wrapper.get('#opener').element)
  })

  it('the safe button cancels and the other one confirms without closing by itself', async () => {
    const { wrapper, open, confirmed, cancelled } = mountWithOpener()
    await openIt(wrapper)
    const [confirm, cancel] = buttons()

    confirm!.click()
    await nextTick()
    expect(confirmed.value).toBe(1)
    // The caller closes it when the action ends.
    expect(open.value).toBe(true)

    cancel!.click()
    await nextTick()
    expect(cancelled.value).toBe(1)
    expect(open.value).toBe(false)
  })

  it('while loading it holds both buttons and Escape', async () => {
    const { wrapper, open, cancelled } = mountWithOpener({ loading: true })
    await openIt(wrapper)
    const [confirm, cancel] = buttons()
    expect(confirm!.disabled).toBe(true)
    expect(confirm!.getAttribute('aria-busy')).toBe('true')
    expect(cancel!.disabled).toBe(true)
    document.body
      .querySelector('[data-testid="dialog-backdrop"]')!
      .dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await nextTick()
    expect(open.value).toBe(true)
    expect(cancelled.value).toBe(0)
  })
})
