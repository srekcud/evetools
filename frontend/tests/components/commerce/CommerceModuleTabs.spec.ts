import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import CommerceModuleTabs, { type CommerceModulePage } from '@/components/commerce/CommerceModuleTabs.vue'

const PAGES: Array<[CommerceModulePage, string, string]> = [
  ['market', '/market', 'Market'],
  ['appraisal', '/appraisal', 'Appraisal'],
  ['contracts', '/contracts', 'Contracts'],
]

enableAutoUnmount(afterEach)

async function mountTabs(active: CommerceModulePage) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/market', name: 'market', component: { template: '<div />' } },
      { path: '/appraisal', name: 'appraisal', component: { template: '<div />' } },
      { path: '/contracts', name: 'contracts', component: { template: '<div />' } },
    ],
  })
  await router.push('/market')
  await router.isReady()
  const wrapper = mount(CommerceModuleTabs, {
    props: { active },
    global: { plugins: [router, createI18n({ legacy: false, locale: 'en', messages: { en } })] },
  })
  await flushPromises()
  return wrapper
}

describe('CommerceModuleTabs', () => {
  it('links the three pages of the module in order, labelled Market, Appraisal and Contracts', async () => {
    const wrapper = await mountTabs('market')

    expect(wrapper.findAll('a[title]').map(link => [link.attributes('href'), link.text()])).toEqual(
      PAGES.map(([, path, label]) => [path, label]),
    )
  })

  it('names the navigation Commerce', async () => {
    const wrapper = await mountTabs('market')

    expect(wrapper.find('nav').attributes('aria-label')).toBe('Commerce')
  })

  it.each(PAGES)('marks only the %s tab as the current page', async (active, path) => {
    const wrapper = await mountTabs(active)

    const current = wrapper.findAll('a').filter(link => link.attributes('aria-current') === 'page')
    expect(current.map(link => link.attributes('href'))).toEqual([path])
  })
})
