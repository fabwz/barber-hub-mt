import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'

import { useSessionStore } from '@/stores/session'
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
    path: '/b/:enlace/pago/:reserva',
    name: 'payment',
    component: () => import('@/views/PaymentView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/b/:enlace/reserva/:reserva/confirmada',
    name: 'booking-confirmed',
    component: () => import('@/views/BookingConfirmedView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/b/:enlace/bonos',
    name: 'vouchers',
    component: () => import('@/views/VouchersView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/b/:enlace/bonos/:bono/compra',
    name: 'voucher-purchase',
    component: () => import('@/views/VoucherPurchaseView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/b/:enlace/mis-reservas',
    name: 'my-bookings',
    component: () => import('@/views/MyBookingsView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/b/:enlace/mis-reservas/:reserva',
    name: 'booking-detail',
    component: () => import('@/views/BookingDetailView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/b/:enlace/lista-de-espera',
    name: 'waitlist',
    component: () => import('@/views/WaitlistView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/pago/retorno',
    name: 'payment-return',
    component: () => import('@/views/PaymentReturnView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/negocios',
    name: 'shops',
    component: () => import('@/views/ShopSelectView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/barberias',
    name: 'directory',
    component: () => import('@/views/DirectoryView.vue'),
    meta: { requiresAuth: true },
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
    path: '/preferencias',
    name: 'preferences',
    component: () => import('@/views/PreferencesView.vue'),
    meta: { public: true },
    // With a session the same block lives in «Mi cuenta · Apariencia»; the old address still works.
    beforeEnter: () => (useSessionStore().isAuthenticated ? { name: 'account-appearance' } : true),
  },
  {
    path: '/cuenta',
    component: () => import('@/views/AccountView.vue'),
    meta: { requiresAuth: true },
    redirect: { name: 'account-data' },
    children: [
      {
        path: 'datos',
        name: 'account-data',
        component: () => import('@/views/account/AccountDataView.vue'),
      },
      {
        path: 'seguridad',
        name: 'account-security',
        component: () => import('@/views/account/AccountSecurityView.vue'),
      },
      {
        path: 'apariencia',
        name: 'account-appearance',
        component: () => import('@/views/account/AccountAppearanceView.vue'),
      },
    ],
  },
  {
    // The agenda of a shop (#78): the reservation panel is a child route, so it has its own address.
    path: '/barberias/:barberia/agenda',
    name: 'staff-agenda',
    component: () => import('@/views/StaffAgendaView.vue'),
    meta: { requiresAuth: true, requiresStaffShop: true },
    children: [
      {
        path: ':reserva',
        name: 'staff-reservation',
        component: () => import('@/components/ReservationPanel.vue'),
      },
    ],
  },
  {
    // The services of a shop (#76, RF-03): only the owner. The form has its own address, new and edit.
    path: '/barberias/:barberia/servicios',
    name: 'staff-services',
    component: () => import('@/views/StaffServicesView.vue'),
    meta: { requiresAuth: true, requiresStaffShop: true, requiresOwner: true },
  },
  {
    path: '/barberias/:barberia/servicios/nuevo',
    name: 'staff-service-new',
    component: () => import('@/views/StaffServiceFormView.vue'),
    meta: { requiresAuth: true, requiresStaffShop: true, requiresOwner: true },
  },
  {
    path: '/barberias/:barberia/servicios/:servicio',
    name: 'staff-service-edit',
    component: () => import('@/views/StaffServiceFormView.vue'),
    meta: { requiresAuth: true, requiresStaffShop: true, requiresOwner: true },
  },
  {
    // «Equipo» (#76, RF-04): only the owner. Each section is a child route; `staff-team` is the schedule of the
    // shop and «Colaboradores» joins as another child (entrega 3), so the navbar link points to the first one.
    path: '/barberias/:barberia/equipo',
    component: () => import('@/views/StaffTeamView.vue'),
    meta: { requiresAuth: true, requiresStaffShop: true, requiresOwner: true },
    children: [
      {
        path: '',
        name: 'staff-team',
        component: () => import('@/views/TeamScheduleView.vue'),
      },
      {
        path: 'colaboradores',
        name: 'staff-team-members',
        component: () => import('@/views/TeamMembersView.vue'),
      },
      // Static paths before `:colaborador`, so «nuevo» and «mi-perfil» are never read as an id.
      {
        path: 'colaboradores/nuevo',
        name: 'staff-team-member-new',
        component: () => import('@/views/TeamMemberFormView.vue'),
      },
      {
        path: 'colaboradores/mi-perfil',
        name: 'staff-team-own-profile',
        component: () => import('@/views/TeamMemberFormView.vue'),
      },
      {
        path: 'colaboradores/:colaborador',
        name: 'staff-team-member-edit',
        component: () => import('@/views/TeamMemberFormView.vue'),
      },
    ],
  },
  {
    // «Bonos» (#111, RF-26, RF-29): only the owner. The list of the shop and the sold ones are child routes, as in
    // «Equipo»; the form has its own address, new and edit, and «nuevo» goes before `:bono` so it is never read as an id.
    path: '/barberias/:barberia/bonos',
    component: () => import('@/views/StaffVouchersView.vue'),
    meta: { requiresAuth: true, requiresStaffShop: true, requiresOwner: true },
    children: [
      {
        path: '',
        name: 'staff-vouchers',
        component: () => import('@/views/VoucherDefinitionsView.vue'),
      },
      {
        path: 'vendidos',
        name: 'staff-vouchers-sold',
        component: () => import('@/views/SoldVouchersView.vue'),
      },
    ],
  },
  {
    path: '/barberias/:barberia/bonos/nuevo',
    name: 'staff-voucher-new',
    component: () => import('@/views/VoucherDefinitionFormView.vue'),
    meta: { requiresAuth: true, requiresStaffShop: true, requiresOwner: true },
  },
  {
    path: '/barberias/:barberia/bonos/:bono',
    name: 'staff-voucher-edit',
    component: () => import('@/views/VoucherDefinitionFormView.vue'),
    meta: { requiresAuth: true, requiresStaffShop: true, requiresOwner: true },
  },
  {
    // «Configuración» (#77, RF-01, RF-08, RF-09): only the owner. Each section is a child route, as in «Equipo».
    path: '/barberias/:barberia/configuracion',
    component: () => import('@/views/StaffSettingsView.vue'),
    meta: { requiresAuth: true, requiresStaffShop: true, requiresOwner: true },
    children: [
      {
        path: '',
        name: 'staff-settings',
        component: () => import('@/views/SettingsPublicView.vue'),
      },
      {
        path: 'cobro',
        name: 'staff-settings-billing',
        component: () => import('@/views/SettingsBillingView.vue'),
      },
    ],
  },
  {
    path: '/cambiar-contrasena',
    name: 'password-change',
    component: () => import('@/views/PasswordChangeView.vue'),
    meta: { requiresAuth: true },
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
