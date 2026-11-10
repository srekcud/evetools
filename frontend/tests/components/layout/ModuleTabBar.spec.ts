import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import en from '@/i18n/locales/en.json'
import ModuleTabBar from '@/components/layout/ModuleTabBar.vue'
import { settingsShortcutLabel } from './moduleTabsHarness'

const OPERATION_ID = '0b8f5d2e-4c1a-4e7b-9a36-5d2f8c1e7a40'
const LONG_LABEL = 'Valeur du minerai après reprocessing et vente'

const TABS = [
  { id: 'operations', label: 'Operations', to: '/mining/operations', count: 3 },
  { id: 'moons', label: 'Moons', to: '/mining/moons' },
  { id: 'ore-value', label: LONG_LABEL, to: '/mining/ore-value' },
]

enableAutoUnmount(afterEach)

async function mountTabBar(path: string, activeId?: string, settingsSection?: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/mining/operations', component: { template: '<div />' } },
      { path: `/mining/operations/${OPERATION_ID}`, component: { template: '<div />' } },
      { path: '/mining/moons', component: { template: '<div />' } },
      { path: '/mining/ore-value', component: { template: '<div />' } },
      { path: '/settings/:section?', name: 'settings', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(ModuleTabBar, { props: { tabs: TABS, activeId, settingsSection }, global: { plugins: [router, createI18n({ legacy: false, locale: 'en', messages: { en } })] } })
  await flushPromises()
  return wrapper
}

describe('ModuleTabBar', () => {
  it('renders a navigation landmark with one link per tab, its count and its label as tooltip', async () => {
    const wrapper = await mountTabBar('/mining/moons', 'moons')

    expect(wrapper.find('nav, [role="navigation"]').exists()).toBe(true)
    const links = wrapper.findAll('a')
    expect(links.map(link => link.attributes('href'))).toEqual(['/mining/operations', '/mining/moons', '/mining/ore-value'])
    expect(links[0].text()).toContain('Operations')
    expect(links[0].text()).toContain('3')
    expect(links[1].text()).toBe('Moons')
    expect(links.map(link => link.attributes('title'))).toEqual(['Operations', 'Moons', LONG_LABEL])
  })

  it('marks only the active tab with aria-current page, the operations tab included on an operation detail', async () => {
    const wrapper = await mountTabBar(`/mining/operations/${OPERATION_ID}`, 'operations')

    expect(wrapper.findAll('a').map(link => link.attributes('aria-current'))).toEqual(['page', undefined, undefined])
  })

  it('renders the settings shortcut as the last link, towards the section named by settingsSection', async () => {
    const wrapper = await mountTabBar('/mining/moons', 'moons', 'mining')

    const links = wrapper.findAll('a')

    expect(typeof settingsShortcutLabel()).toBe('string')
    expect(links.map(link => link.attributes('href'))).toEqual(['/mining/operations', '/mining/moons', '/mining/ore-value', '/settings/mining'])
    expect(links[3].attributes('aria-label')).toBe(settingsShortcutLabel())
    expect(links[3].attributes('aria-current')).toBeUndefined()
  })
})
