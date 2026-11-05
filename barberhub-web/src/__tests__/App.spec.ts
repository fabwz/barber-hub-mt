import { describe, it, expect } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import App from '@/App.vue'
import router from '@/router'

describe('App', () => {
  it('shows HomeView at the root route', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    await router.push('/')
    await router.isReady()

    const wrapper = mount(App, { global: { plugins: [pinia, router] } })
    await flushPromises()

    expect(wrapper.find('h1').text()).toBe('BarberHub')
  })
})
