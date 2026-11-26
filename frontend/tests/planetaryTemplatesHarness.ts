import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import PiPlannerTemplates from '@/components/planetary/PiPlannerTemplates.vue'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import { apiRequest } from '@/services/api'
import { usePlanetaryPlannerStore } from '@/stores/planetaryPlanner'
import type { PlannerCatalog } from '@/utils/planetaryPlanner'
import type { PlanTemplate } from '@/utils/planetaryTemplates'
import { PLANNER_ACCOUNT, PLANNER_MARKET } from './planetaryPlannerFixtures'
import { TEMPLATE_CATALOG } from './planetaryTemplatesFixtures'

export const TEMPLATES_ENDPOINT = '/planetary/templates'

export interface TemplatesScenario {
  catalog?: PlannerCatalog
  templates?: PlanTemplate[]
  saved?: PlanTemplate
  locale?: 'en' | 'fr'
}

export async function mountTemplates(scenario: TemplatesScenario = {}) {
  const catalog = scenario.catalog === undefined ? TEMPLATE_CATALOG : scenario.catalog
  const templates = scenario.templates === undefined ? [] : scenario.templates
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation((async (endpoint: string, options?: RequestInit) => {
    const method = options?.method ?? 'GET'
    if (endpoint === TEMPLATES_ENDPOINT && method === 'GET') return templates
    if (endpoint === TEMPLATES_ENDPOINT && method === 'POST') return scenario.saved
    if (endpoint.startsWith(`${TEMPLATES_ENDPOINT}/`) && method === 'DELETE') return null
    if (endpoint === '/planetary/planner/account') return { id: 'account', ...PLANNER_ACCOUNT }
    if (endpoint === '/planetary/planner/market') return { id: 'market', ...PLANNER_MARKET }
    return { id: 'planner', ...catalog }
  }) as never)
  const store = usePlanetaryPlannerStore()
  await store.fetchCatalog()
  await store.fetchAccount()
  await store.fetchTemplates()
  const wrapper = mount(PiPlannerTemplates, {
    global: {
      plugins: [createI18n({ legacy: false, locale: scenario.locale === undefined ? 'en' : scenario.locale, messages: { en, fr } })],
      stubs: { RouterLink: RouterLinkStub },
    },
  })
  await flushPromises()
  return { wrapper, store }
}

export type TemplatesWrapper = Awaited<ReturnType<typeof mountTemplates>>['wrapper']

export function templateRows(wrapper: TemplatesWrapper) {
  return wrapper.findAll('[data-testid="template-row"]')
}

export function rowOfTemplate(wrapper: TemplatesWrapper, templateId: string) {
  return wrapper.get(`[data-testid="template-row"][data-template-id="${templateId}"]`)
}

export function cellOfTemplate(wrapper: TemplatesWrapper, templateId: string, testId: string) {
  return rowOfTemplate(wrapper, templateId).get(`[data-testid="${testId}"]`)
}

export function postedBodies(): unknown[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(call => call[0] === TEMPLATES_ENDPOINT && (call[1] as RequestInit | undefined)?.method === 'POST')
    .map(call => JSON.parse(String((call[1] as RequestInit).body)))
}

export function deletedEndpoints(): string[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(call => (call[1] as RequestInit | undefined)?.method === 'DELETE')
    .map(call => call[0])
}
