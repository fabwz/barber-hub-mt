import { ACCOUNT, accountHandler } from '../helpers/account'
import { agendaApi, freezeClock } from '../helpers/agenda'
import { bookingHandler } from '../helpers/booking'
import { PURA_NAVAJA, FADE_LAB, CORTE_FINO, directoryHandler } from '../helpers/directory'
import { installFakeEcho } from '../helpers/echo'
import { jsonResponse, mockFetch, pathOf, problem } from '../helpers/http'
import { DETAIL_ID, mockBookingsFetch } from '../helpers/myBookings'
import { mockOffersApi } from '../helpers/offers'
import { RESERVATION_ID, detail, mockPaymentFetch } from '../helpers/payment'
import { scheduleApi, slot } from '../helpers/schedule'
import { servicesApi, service } from '../helpers/services'
import { settingsApi } from '../helpers/settings'
import { OWNER_SHOP, SHOP_A, STAFF_SHOP, staffHandler } from '../helpers/staff'
import { collaborator, teamApi } from '../helpers/team'
import { voucher, voucherDefinitionsApi } from '../helpers/voucherDefinitions'
import { VOUCHER_ID, mockVoucherFetch } from '../helpers/vouchers'

/**
 * Registry of the screens that the accessibility test visits (RNF-27, AC-01).
 *
 * One entry per named route of the router. `screens.spec.ts` fails when a route is neither here nor
 * in `EXEMPT`, so a new screen cannot reach main without being checked. To register one, see
 * docs/accessibility-tests.md.
 */
export interface Screen {
  /**
   * Who looks at it: `guest` (no session), `client` (a session, any role) or `owner` (a session, and
   * the fake API answers with the person as owner of the shop). The test signs in unless `guest`.
   */
  session: 'guest' | 'client' | 'owner'
  /** Installs the fake API (and anything else the screen needs) and returns the address to visit. */
  prepare: () => string
  /** Leaves the account unknown, so the router reads it (needed by the temporary-password screen). */
  knownAccount?: boolean
}

/** Routes that the test does not visit, each with its reason. */
export const EXEMPT: Record<string, string> = {
  catalog:
    'Component catalog: it only exists in development (import.meta.env.DEV), the build drops it, and it is not a screen of the product.',
  'payment-return':
    'Only redirects: on mount it sends the person to the payment page (or the voucher purchase, or home) and is on screen for a single tick. The page it sends to is registered (payment, voucher-purchase).',
}

const offline = () => mockFetch(() => problem(404, { type: 'no-encontrado' }))

const REPORT = {
  desde: '2026-11-01',
  hasta: '2026-11-07',
  cobrado_en_linea: { bruto: 186000, reembolsado: 13500, neto: 172500 },
  bonos_vendidos: { total: 74000, cantidad: 2 },
  servicios_atendidos: { total: 248500, cantidad: 26 },
  detalle: [],
}

function incomeApi(): string {
  mockFetch((request) => {
    const fallback = staffHandler([OWNER_SHOP, STAFF_SHOP])(request)
    if (fallback.status !== 404) return fallback
    if (pathOf(request) === `GET /api/v1/barberias/${SHOP_A}/administracion/ingresos`) {
      return jsonResponse(200, { data: REPORT })
    }
    return problem(404, { type: 'no-encontrado' })
  })
  return `/barberias/${SHOP_A}/ingresos`
}

const SHOP_PATH = '/b/pura-navaja'

export const SCREENS: Record<string, Screen> = {
  // Public
  home: { session: 'guest', prepare: () => (mockFetch(staffHandler([])), '/') },
  shop: {
    session: 'guest',
    prepare: () => (mockFetch(bookingHandler()), SHOP_PATH),
  },
  terms: { session: 'guest', prepare: () => (offline(), '/terminos') },
  privacy: { session: 'guest', prepare: () => (offline(), '/privacidad') },
  preferences: { session: 'guest', prepare: () => (offline(), '/preferencias') },
  login: { session: 'guest', prepare: () => (offline(), '/ingresar') },
  register: { session: 'guest', prepare: () => (offline(), '/registro') },
  'forgot-password': { session: 'guest', prepare: () => (offline(), '/olvide-mi-contrasena') },
  // The screen takes the token out of the address once it has read it; the form is what axe looks at.
  'reset-password': {
    session: 'guest',
    prepare: () => (offline(), '/restablecer-contrasena?token=a1b2c3'),
  },

  // Client
  shops: {
    session: 'client',
    prepare: () => (mockFetch(staffHandler([OWNER_SHOP, STAFF_SHOP])), '/negocios'),
  },
  directory: {
    session: 'client',
    prepare: () => (mockFetch(directoryHandler([PURA_NAVAJA, FADE_LAB, CORTE_FINO])), '/barberias'),
  },
  payment: {
    session: 'client',
    prepare: () => (mockPaymentFetch(), `${SHOP_PATH}/pago/${RESERVATION_ID}`),
  },
  'booking-confirmed': {
    session: 'client',
    prepare: () => {
      mockPaymentFetch({
        reservation: () =>
          jsonResponse(200, {
            data: detail({
              estado: 'confirmada',
              monto_a_pagar: 0,
              saldo_en_local: 10000,
              vence_en: null,
              expira_en: null,
            }),
          }),
      })
      return `${SHOP_PATH}/reserva/${RESERVATION_ID}/confirmada`
    },
  },
  vouchers: {
    session: 'client',
    prepare: () => (mockVoucherFetch(), `${SHOP_PATH}/bonos`),
  },
  'voucher-purchase': {
    session: 'client',
    prepare: () => (mockVoucherFetch(), `${SHOP_PATH}/bonos/${VOUCHER_ID}/compra`),
  },
  'my-bookings': {
    session: 'client',
    prepare: () => (mockBookingsFetch(), `${SHOP_PATH}/mis-reservas`),
  },
  'booking-detail': {
    session: 'client',
    prepare: () => (mockBookingsFetch(), `${SHOP_PATH}/mis-reservas/${DETAIL_ID}`),
  },
  waitlist: {
    session: 'client',
    prepare: () => (mockOffersApi(), `${SHOP_PATH}/lista-de-espera`),
  },
  'password-change': {
    session: 'client',
    knownAccount: false,
    prepare: () => {
      accountHandler({ account: { ...ACCOUNT, contrasena_temporal: true } })
      return '/cambiar-contrasena'
    },
  },
  'account-data': {
    session: 'client',
    prepare: () => (accountHandler(), '/cuenta/datos'),
  },
  'account-security': {
    session: 'client',
    prepare: () => (accountHandler(), '/cuenta/seguridad'),
  },
  'account-notices': {
    session: 'client',
    prepare: () => (accountHandler(), '/cuenta/avisos'),
  },
  'account-appearance': {
    session: 'client',
    prepare: () => (accountHandler(), '/cuenta/apariencia'),
  },

  // Staff (owner or collaborator of the shop)
  'staff-agenda': {
    session: 'owner',
    prepare: () => {
      freezeClock()
      installFakeEcho()
      return `/barberias/${agendaApi().shopId}/agenda`
    },
  },
  'staff-reservation': {
    session: 'owner',
    prepare: () => {
      freezeClock()
      installFakeEcho()
      return `/barberias/${agendaApi().shopId}/agenda/r1`
    },
  },

  // Owner only
  'staff-services': {
    session: 'owner',
    prepare: () => `/barberias/${servicesApi().shopId}/servicios`,
  },
  'staff-service-new': {
    session: 'owner',
    prepare: () => `/barberias/${servicesApi().shopId}/servicios/nuevo`,
  },
  'staff-service-edit': {
    session: 'owner',
    prepare: () => `/barberias/${servicesApi().shopId}/servicios/${service().id}`,
  },
  'staff-team': {
    session: 'owner',
    prepare: () =>
      `/barberias/${scheduleApi({ slots: [slot('h1', 1, '09:00', '19:00')] }).shopId}/equipo`,
  },
  'staff-team-members': {
    session: 'owner',
    prepare: () =>
      `/barberias/${teamApi({ collaborators: [collaborator()] }).shopId}/equipo/colaboradores`,
  },
  'staff-team-member-new': {
    session: 'owner',
    prepare: () => `/barberias/${teamApi().shopId}/equipo/colaboradores/nuevo`,
  },
  'staff-team-own-profile': {
    session: 'owner',
    prepare: () =>
      `/barberias/${teamApi({ collaborators: [collaborator({ es_dueno: true })] }).shopId}/equipo/colaboradores/mi-perfil`,
  },
  'staff-team-member-edit': {
    session: 'owner',
    prepare: () =>
      `/barberias/${teamApi({ collaborators: [collaborator()] }).shopId}/equipo/colaboradores/${collaborator().id}`,
  },
  'staff-vouchers': {
    session: 'owner',
    prepare: () => `/barberias/${voucherDefinitionsApi().shopId}/bonos`,
  },
  'staff-vouchers-sold': {
    session: 'owner',
    prepare: () => `/barberias/${voucherDefinitionsApi().shopId}/bonos/vendidos`,
  },
  'staff-voucher-new': {
    session: 'owner',
    prepare: () => `/barberias/${voucherDefinitionsApi().shopId}/bonos/nuevo`,
  },
  'staff-voucher-edit': {
    session: 'owner',
    prepare: () => `/barberias/${voucherDefinitionsApi().shopId}/bonos/${voucher().id}`,
  },
  'staff-income': { session: 'owner', prepare: incomeApi },
  'staff-settings': {
    session: 'owner',
    prepare: () => (settingsApi(), `/barberias/${SHOP_A}/configuracion`),
  },
  'staff-settings-billing': {
    session: 'owner',
    prepare: () => (settingsApi(), `/barberias/${SHOP_A}/configuracion/cobro`),
  },
}
