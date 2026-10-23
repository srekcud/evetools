import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectTable from '@/components/industry/ProjectTable.vue'
import { useProjectsStore } from '@/stores/industry/projects'
import type { IndustryProject } from '@/stores/industry/types'

const WIDOW_TYPE_ID = 22436
const RIFTER_TYPE_ID = 587
const ISHTAR_TYPE_ID = 12005

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

const ACTIVE_WIDOW_PROJECT = project({ id: 'widow', displayName: 'Widow', productTypeId: WIDOW_TYPE_ID, status: 'active', profit: 300_000 })
const COMPLETED_RIFTER_PROJECT = project({ id: 'rifter', displayName: 'Rifter', productTypeId: RIFTER_TYPE_ID, status: 'completed', profit: 700_000 })
const ACTIVE_RIFTER_PROJECT_WITH_AN_UNKNOWN_PROFIT = project({ id: 'rifter-unknown', displayName: 'Rifter unknown', productTypeId: RIFTER_TYPE_ID, status: 'active', profit: null })
const COMPLETED_RIFTER_PROJECT_WITH_AN_UNKNOWN_PROFIT = project({ id: 'rifter-completed-unknown', displayName: 'Rifter completed unknown', productTypeId: RIFTER_TYPE_ID, status: 'completed', profit: null })
const ACTIVE_PERSONAL_USE_ISHTAR_PROJECT = project({ id: 'ishtar', displayName: 'Ishtar', productTypeId: ISHTAR_TYPE_ID, status: 'active', personalUse: true, profit: null })

function mountTable(projects: IndustryProject[], activeOnly: boolean, locale: 'en' | 'fr' = 'en') {
  useProjectsStore().projects = projects
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(ProjectTable, { props: { activeOnly }, global: { plugins: [i18n] } })
}

function totalRow(wrapper: ReturnType<typeof mountTable>) {
  return wrapper.get('tfoot')
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('ProjectTable profit total', () => {
  it('totals only the displayed active projects in activeOnly mode', () => {
    const wrapper = mountTable([ACTIVE_WIDOW_PROJECT, COMPLETED_RIFTER_PROJECT], true)

    expect(totalRow(wrapper).get('span.font-mono').text()).toBe('300.00 K')
  })

  it('totals every project when not in activeOnly mode', () => {
    const wrapper = mountTable([ACTIVE_WIDOW_PROJECT, COMPLETED_RIFTER_PROJECT], false)

    expect(totalRow(wrapper).get('span.font-mono').text()).toBe('1.00 M')
  })

  it.each([
    ['en', '1 project with unknown profit excluded'],
    ['fr', '1 projet au profit inconnu exclu'],
  ] as const)('mentions the single displayed project with an unknown profit excluded (%s)', (locale, mention) => {
    const wrapper = mountTable([ACTIVE_WIDOW_PROJECT, ACTIVE_RIFTER_PROJECT_WITH_AN_UNKNOWN_PROFIT, ACTIVE_PERSONAL_USE_ISHTAR_PROJECT], true, locale)

    expect(totalRow(wrapper).get('span.font-mono').text()).toBe(locale === 'en' ? '300.00 K' : '300,00 K')
    expect(totalRow(wrapper).text()).toContain(mention)
  })

  it.each([
    ['en', '2 projects with unknown profit excluded'],
    ['fr', '2 projets au profit inconnu exclus'],
  ] as const)('mentions the plural of excluded projects (%s)', (locale, mention) => {
    const wrapper = mountTable([ACTIVE_WIDOW_PROJECT, ACTIVE_RIFTER_PROJECT_WITH_AN_UNKNOWN_PROFIT, COMPLETED_RIFTER_PROJECT_WITH_AN_UNKNOWN_PROFIT], false, locale)

    expect(totalRow(wrapper).text()).toContain(mention)
  })

  it('does not count a hidden completed project with an unknown profit as excluded in activeOnly mode', () => {
    const wrapper = mountTable([ACTIVE_WIDOW_PROJECT, COMPLETED_RIFTER_PROJECT_WITH_AN_UNKNOWN_PROFIT], true)

    expect(totalRow(wrapper).text()).not.toContain('excluded')
  })

  it('shows no mention when every counted project has a known profit or is personal use', () => {
    const wrapper = mountTable([ACTIVE_WIDOW_PROJECT, ACTIVE_PERSONAL_USE_ISHTAR_PROJECT], true)

    expect(totalRow(wrapper).text()).not.toContain('excluded')
  })
})
