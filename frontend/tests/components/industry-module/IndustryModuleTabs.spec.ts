import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import IndustryModuleTabs, { type IndustryModulePage } from '@/components/industry-module/IndustryModuleTabs.vue'

const PAGES: Array<[IndustryModulePage, string]> = [
  ['workshop', '/industry'],
  ['projects', '/industry/projects'],
  ['analysis', '/industry/analysis'],
  ['production', '/industry/production'],
]

enableAutoUnmount(afterEach)

async function mountTabs(active: IndustryModulePage, locale: 'en' | 'fr' = 'en') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/industry', name: 'industry', component: { template: '<div />' } },
      { path: '/industry/projects', name: 'industry-projects', component: { template: '<div />' } },
      { path: '/industry/analysis', name: 'industry-analysis', component: { template: '<div />' } },
      { path: '/industry/production', name: 'industry-production', component: { template: '<div />' } },
    ],
  })
  await router.push('/industry')
  await router.isReady()
  const wrapper = mount(IndustryModuleTabs, {
    props: { active },
    global: { plugins: [router, createI18n({ legacy: false, locale, messages: { en, fr } })] },
  })
  await flushPromises()
  return wrapper
}

describe('IndustryModuleTabs', () => {
  it('links the four pages of the module in order', async () => {
    const wrapper = await mountTabs('workshop')

    expect(wrapper.findAll('a').map(link => link.attributes('href'))).toEqual(PAGES.map(([, path]) => path))
  })

  it.each([
    ['en', ['Workshop', 'Projects', 'Analysis', 'Production']],
    ['fr', ['Atelier', 'Projets', 'Analyse', 'Production']],
  ] as const)('labels the four pages in %s', async (locale, labels) => {
    const wrapper = await mountTabs('workshop', locale)

    expect(wrapper.findAll('a').map(link => link.text().trim())).toEqual(labels)
  })

  it.each(PAGES)('marks only the %s tab as the current page', async (active, path) => {
    const wrapper = await mountTabs(active)

    const current = wrapper.findAll('a').filter(link => link.attributes('aria-current') === 'page')
    expect(current.map(link => link.attributes('href'))).toEqual([path])
  })
})
