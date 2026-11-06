import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'

import HomeView from '@/views/HomeView.vue'
import { installGuards } from './guard'

export const routes: RouteRecordRaw[] = [
  { path: '/', name: 'home', component: HomeView },
  {
    path: '/b/:enlace',
    name: 'shop',
    component: () => import('@/views/ShopView.vue'),
    meta: { public: true },
  },
  {
    path: '/ingresar',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { public: true, guestOnly: true },
  },
  {
    path: '/registro',
    name: 'register',
    component: () => import('@/views/RegisterView.vue'),
    meta: { public: true, guestOnly: true },
  },
  {
    path: '/terminos',
    name: 'terms',
    component: () => import('@/views/TermsView.vue'),
    meta: { public: true },
  },
  {
    path: '/privacidad',
    name: 'privacy',
    component: () => import('@/views/PrivacyView.vue'),
    meta: { public: true },
  },
]

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

installGuards(router)

export default router
