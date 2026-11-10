import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import MiningModuleTabs, { type MiningModulePage } from '@/components/mining-module/MiningModuleTabs.vue'

const PAGES: Array<[MiningModulePage, string]> = [
  ['operations', '/mining/operations'],
  ['moons', '/mining/moons'],
  ['sites', '/mining/sites'],
  ['ore-value', '/mining/ore-value'],
  ['my-mining', '/mining/my-mining'],
]

enableAutoUnmount(afterEach)

async function mountTabs(active: MiningModulePage) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/mining/operations', name: 'mining-operations', component: { template: '<div />' } },
      { path: '/mining/moons', name: 'mining-moons', component: { template: '<div />' } },
      { path: '/mining/sites', name: 'mining-sites', component: { template: '<div />' } },
      { path: '/mining/ore-value', name: 'mining-ore-value', component: { template: '<div />' } },
      { path: '/mining/my-mining', name: 'mining-my-mining', component: { template: '<div />' } },
    ],
  })
  await router.push('/mining/operations')
  await router.isReady()
  const wrapper = mount(MiningModuleTabs, {
    props: { active },
    global: { plugins: [router, createI18n({ legacy: false, locale: 'en', messages: { en } })] },
  })
  await flushPromises()
  return wrapper
}

describe('MiningModuleTabs', () => {
  it('links the five pages of the module in order', async () => {
    const wrapper = await mountTabs('operations')

    expect(wrapper.findAll('a[title]').map(link => link.attributes('href'))).toEqual(PAGES.map(([, path]) => path))
  })

  it.each(PAGES)('marks only the %s tab as the current page', async (active, path) => {
    const wrapper = await mountTabs(active)

    const current = wrapper.findAll('a').filter(link => link.attributes('aria-current') === 'page')
    expect(current.map(link => link.attributes('href'))).toEqual([path])
  })
})
