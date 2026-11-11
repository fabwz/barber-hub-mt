import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type RouteRecordRaw } from 'vue-router'
import { defineComponent, h, type Component } from 'vue'

import { routes } from '@/router'
import { installGuards } from '@/router/guard'
import { connectSession } from '@/stores/session'

const agenda = defineComponent({ render: () => h('p', 'agenda') })

/** Monta una vista con Pinia y un router real (historial en memoria) sobre las rutas de la app. */
export async function mountView(view: Component, path: string, extraRoutes: RouteRecordRaw[] = []) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [...routes, { path: '/agenda', name: 'agenda', component: agenda }, ...extraRoutes],
  })
  installGuards(router)
  connectSession(router)
  await router.push(path)
  await router.isReady()
  const wrapper = mount(view, { global: { plugins: [pinia, router] }, attachTo: document.body })
  return { wrapper, router }
}
