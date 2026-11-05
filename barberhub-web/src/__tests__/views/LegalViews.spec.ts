import { describe, expect, it } from 'vitest'

import PrivacyView from '@/views/PrivacyView.vue'
import TermsView from '@/views/TermsView.vue'
import { mountView } from '../helpers/mountView'

describe.each([
  [
    'TermsView',
    TermsView,
    '/terminos',
    'Términos y Condiciones de Uso de BarberHub',
    15,
    '/privacidad',
  ],
  [
    'PrivacyView',
    PrivacyView,
    '/privacidad',
    'Política de Privacidad y Tratamiento de Datos Personales de BarberHub',
    16,
    '/terminos',
  ],
])('%s', (_name, view, path, title, sections, linkTarget) => {
  it('has one h1, one h2 per section, the date of today and a reading width', async () => {
    const { wrapper } = await mountView(view, path)

    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe(title)
    expect(wrapper.findAll('h2')).toHaveLength(sections)
    expect(wrapper.text()).toContain('Última actualización: 5 de noviembre de 2026')
    expect(wrapper.text()).toContain('Versión 1.0')
    expect(wrapper.get('[role="note"]').text().replace(/\s+/g, ' ')).toBe(
      'Texto de ejemplo. El contenido final lo define el negocio.',
    )
    expect(wrapper.get('main > div').classes()).toContain('max-w-[58ch]')
    expect(wrapper.get('img').attributes('alt')).toBe('BarberHub')
  })

  it('shows no raw markdown and links to the other legal page', async () => {
    const { wrapper } = await mountView(view, path)

    expect(wrapper.text()).not.toMatch(/\*\*|\]\(|`/)
    expect(wrapper.find(`main a[href="${linkTarget}"]`).exists()).toBe(true)
    expect(wrapper.find('main strong').exists()).toBe(true)
    expect(
      wrapper.findAll('main strong').every((node) => node.classes().includes('font-medium')),
    ).toBe(true)
  })

  it('Volver goes back when there is history and to «/» on a direct visit', async () => {
    const { wrapper, router } = await mountView(view, path)

    await wrapper.get('header button').trigger('click')
    await new Promise((resolve) => setTimeout(resolve))

    expect(wrapper.get('header button').text()).toBe('Volver')
    expect(router.currentRoute.value.path).toBe('/')
  })
})

describe('PrivacyView table', () => {
  it('shows the roles table with headers, three rows and scrolls on narrow screens', async () => {
    const { wrapper } = await mountView(PrivacyView, '/privacidad')

    const headers = wrapper.findAll('th').map((cell) => cell.text())
    expect(headers).toEqual(['Tipo de dato', 'Responsable', 'Encargado'])
    expect(wrapper.findAll('tbody tr')).toHaveLength(3)
    expect(wrapper.get('table').element.parentElement?.classList.contains('overflow-x-auto')).toBe(
      true,
    )
  })
})

describe('TermsView links', () => {
  it('the clauses that mention the Privacy Policy link to /privacidad', async () => {
    const { wrapper } = await mountView(TermsView, '/terminos')

    expect(wrapper.findAll('main a[href="/privacidad"]')).toHaveLength(3)
  })
})
