import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'

import HomeView from '@/views/HomeView.vue'

const routes: RouteRecordRaw[] = [{ path: '/', name: 'home', component: HomeView }]

// The component catalog only exists in development; the build drops this branch.
if (import.meta.env.DEV) {
  routes.push({
    path: '/componentes',
    name: 'catalog',
    component: () => import('@/views/CatalogView.vue'),
  })
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

export default router
