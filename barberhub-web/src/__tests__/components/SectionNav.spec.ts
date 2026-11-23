import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

import SectionNav from '@/components/SectionNav.vue'

const page = defineComponent({ render: () => h('p') })

async function mountNav(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/a', name: 'a', component: page },
      { path: '/b', name: 'b', component: page },
    ],
  })
  await router.push(path)
  await router.isReady()
  return mount(SectionNav, {
    props: {
      label: 'Secciones de Equipo',
      sections: [
        { label: 'Primera', to: { name: 'a' } },
        { label: 'Segunda', to: { name: 'b' } },
      ],
    },
    global: { plugins: [router] },
  })
}

describe('SectionNav', () => {
  it('is a named navigation of links, not tabs', async () => {
    const wrapper = await mountNav('/a')
    expect(wrapper.get('nav').attributes('aria-label')).toBe('Secciones de Equipo')
    expect(wrapper.findAll('a').map((link) => link.attributes('href'))).toEqual(['/a', '/b'])
    expect(wrapper.find('[role="tab"], [role="tablist"], [role="tabpanel"]').exists()).toBe(false)
  })

  it('marks only the current section with aria-current', async () => {
    const wrapper = await mountNav('/b')
    const [first, second] = wrapper.findAll('a')
    expect(first!.attributes('aria-current')).toBeUndefined()
    expect(second!.attributes('aria-current')).toBe('page')
    expect(second!.classes()).toContain('font-medium')
  })
})
