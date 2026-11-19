import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { defineComponent } from 'vue'

import BackLink from '@/components/BackLink.vue'

const page = defineComponent({ template: '<p />' })

async function build(slots?: Record<string, string>) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: page },
      { path: '/barberias', name: 'directory', component: page },
    ],
  })
  await router.push('/')
  await router.isReady()
  const wrapper = mount(BackLink, {
    props: { to: { name: 'directory' } },
    slots,
    global: { plugins: [router] },
  })
  return { wrapper, router }
}

describe('BackLink', () => {
  it('is a link to the given route that says «Volver»', async () => {
    const { wrapper } = await build()

    expect(wrapper.element.tagName).toBe('A')
    expect(wrapper.attributes('href')).toBe('/barberias')
    expect(wrapper.text()).toBe('Volver')
  })

  it('goes to the route when clicked', async () => {
    const { wrapper, router } = await build()

    await wrapper.trigger('click')
    await new Promise((resolve) => setTimeout(resolve))

    expect(router.currentRoute.value.name).toBe('directory')
  })

  it('has the look of a button: no underline, 44 px target and a hidden chevron', async () => {
    const { wrapper } = await build()

    expect(wrapper.classes()).toEqual(
      expect.arrayContaining(['no-underline', 'min-h-11', 'min-w-11']),
    )
    expect(wrapper.get('svg').attributes('aria-hidden')).toBe('true')
  })

  it('takes another label through the slot', async () => {
    const { wrapper } = await build({ default: 'Volver a la lista' })

    expect(wrapper.text()).toBe('Volver a la lista')
  })
})
