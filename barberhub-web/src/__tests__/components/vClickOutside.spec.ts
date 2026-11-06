import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent } from 'vue'

import { vClickOutside } from '@/composables/vClickOutside'

afterEach(() => {
  document.body.innerHTML = ''
})

const Host = defineComponent({
  directives: { clickOutside: vClickOutside },
  props: { onOutside: { type: Function, required: true } },
  template:
    '<div><div id="box" v-click-outside="onOutside"><span id="in" /></div><p id="out" /></div>',
})

describe('vClickOutside', () => {
  it('calls the handler for a click outside, not inside', () => {
    const handler = vi.fn()
    mount(Host, { props: { onOutside: handler }, attachTo: document.body })

    document.getElementById('in')!.click()
    document.getElementById('box')!.click()
    expect(handler).not.toHaveBeenCalled()

    document.getElementById('out')!.click()
    document.body.click()
    expect(handler).toHaveBeenCalledTimes(2)
  })

  it('removes the listener when the element is unmounted', () => {
    const handler = vi.fn()
    const wrapper = mount(Host, { props: { onOutside: handler }, attachTo: document.body })

    wrapper.unmount()
    document.body.click()

    expect(handler).not.toHaveBeenCalled()
  })
})
