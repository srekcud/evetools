import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import IndustryDashboard from '@/components/industry/IndustryDashboard.vue'
import { useProjectsStore } from '@/stores/industry/projects'
import type { IndustryProject } from '@/stores/industry/types'
import { apiRequest } from '@/services/api'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const WIDOW_TYPE_ID = 22436
const RIFTER_TYPE_ID = 587

function project(overrides: Partial<IndustryProject>): IndustryProject {
  return {
    id: 'project',
    productTypeName: null,
    productTypeId: WIDOW_TYPE_ID,
    displayName: 'Project',
    runs: 1,
    meLevel: 10,
    teLevel: 20,
    maxJobDurationDays: 2,
    status: 'active',
    personalUse: false,
    isT2: false,
    bpoCost: null,
    materialCost: null,
    transportCost: null,
    jobsCost: null,
    taxAmount: null,
    sellPrice: null,
    totalCost: null,
    estimatedJobCost: null,
    estimatedSellPrice: null,
    estimatedSellPriceSource: null,
    estimatedTaxAmount: null,
    estimatedMaterialCost: null,
    profit: null,
    profitPercent: null,
    notes: null,
    createdAt: '2026-10-01T10:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
    ...overrides,
  }
}

const ACTIVE_WIDOW_PROJECT = project({ id: 'widow', displayName: 'Widow', productTypeId: WIDOW_TYPE_ID, profit: 1_000_000 })
const ACTIVE_RIFTER_PROJECT_WITH_AN_UNKNOWN_PROFIT = project({ id: 'rifter-unknown', displayName: 'Rifter unknown', productTypeId: RIFTER_TYPE_ID, profit: null })
const COMPLETED_RIFTER_PROJECT = project({ id: 'rifter', displayName: 'Rifter', productTypeId: RIFTER_TYPE_ID, status: 'completed', profit: 5_000_000 })

function mountDashboard(projects: IndustryProject[], locale: 'en' | 'fr') {
  useProjectsStore().projects = projects
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(IndustryDashboard, { global: { plugins: [i18n] } })
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockReturnValue(new Promise(() => {}))
})

describe('IndustryDashboard estimated profit', () => {
  it.each([
    ['en', '1.00 M', '1 project with unknown profit excluded'],
    ['fr', '1,00 M', '1 projet au profit inconnu exclu'],
  ] as const)('totals the known profits of the active projects and mentions the excluded one (%s)', (locale, total, mention) => {
    const wrapper = mountDashboard([ACTIVE_WIDOW_PROJECT, ACTIVE_RIFTER_PROJECT_WITH_AN_UNKNOWN_PROFIT, COMPLETED_RIFTER_PROJECT], locale)

    expect(wrapper.text()).toContain(total)
    expect(wrapper.text()).toContain(mention)
    expect(wrapper.text()).not.toContain('6.00 M')
  })

  it('shows no mention when every active project has a known profit', () => {
    const wrapper = mountDashboard([ACTIVE_WIDOW_PROJECT, COMPLETED_RIFTER_PROJECT], 'en')

    expect(wrapper.text()).toContain('1.00 M')
    expect(wrapper.text()).not.toContain('excluded')
  })
})
