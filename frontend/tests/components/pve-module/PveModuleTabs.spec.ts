import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PveModuleTabs, { type PveModulePage } from '@/components/pve-module/PveModuleTabs.vue'

const PAGES: Array<[PveModulePage, string]> = [
  ['revenue', '/pve/revenue'],
  ['escalations', '/pve/escalations'],
]

enableAutoUnmount(afterEach)

async function mountTabs(active: PveModulePage) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/pve/revenue', name: 'pve-revenue', component: { template: '<div />' } },
      { path: '/pve/escalations', name: 'pve-escalations', component: { template: '<div />' } },
    ],
  })
  await router.push('/pve/revenue')
  await router.isReady()
  const wrapper = mount(PveModuleTabs, {
    props: { active },
    global: { plugins: [router, createI18n({ legacy: false, locale: 'en', messages: { en } })] },
  })
  await flushPromises()
  return wrapper
}

describe('PveModuleTabs', () => {
  it('links the two pages of the module in order', async () => {
    const wrapper = await mountTabs('revenue')

    expect(wrapper.findAll('a[title]').map(link => link.attributes('href'))).toEqual(PAGES.map(([, path]) => path))
  })

  it.each(PAGES)('marks only the %s tab as the current page', async (active, path) => {
    const wrapper = await mountTabs(active)

    const current = wrapper.findAll('a').filter(link => link.attributes('aria-current') === 'page')
    expect(current.map(link => link.attributes('href'))).toEqual([path])
  })

  it('names the escalations tab Escalations', async () => {
    const wrapper = await mountTabs('escalations')

    expect(wrapper.findAll('a[aria-current="page"]').map(link => link.text())).toEqual(['Escalations'])
  })
})
