import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProjectTable from '@/components/industry/ProjectTable.vue'
import { useProjectsStore } from '@/stores/industry/projects'
import type { IndustryProject } from '@/stores/industry/types'

const ME_LABEL = en.industry.createProject.me
const TE_LABEL = en.industry.createProject.te

function project(overrides: Partial<IndustryProject>): IndustryProject {
  return {
    id: 'project',
    productTypeName: null,
    productTypeId: 22436,
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
    createdAt: '2026-10-29T08:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
    ...overrides,
  }
}

const WIDOW_PROJECT = project({ id: 'widow', displayName: 'Widow', productTypeId: 22436, runs: 2, meLevel: 8, teLevel: 6 })
const RIFTER_PROJECT = project({ id: 'rifter', displayName: 'Rifter', productTypeId: 587, runs: 15, meLevel: 3, teLevel: 12 })

function buttonNamed(wrapper: VueWrapper, name: string) {
  const button = wrapper.findAll('button').find(candidate => candidate.text().trim() === name)
  if (!button) throw new Error(`No button named ${name}`)
  return button
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('ProjectTable ME/TE edition', () => {
  it('focuses the ME input of the edited project row', async () => {
    useProjectsStore().projects = [{ ...WIDOW_PROJECT }, { ...RIFTER_PROJECT }]
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
    const wrapper = mount(ProjectTable, { props: { activeOnly: true }, global: { plugins: [i18n] }, attachTo: document.body })

    await buttonNamed(wrapper, `${RIFTER_PROJECT.meLevel}/${RIFTER_PROJECT.teLevel}`).trigger('dblclick')
    await flushPromises()

    const focused = document.activeElement
    expect({
      isTheMeInput: focused === wrapper.get(`input[aria-label="${ME_LABEL}"]`).element,
      label: focused?.getAttribute('aria-label') ?? null,
      value: focused instanceof HTMLInputElement ? focused.value : null,
      editedTeValue: (wrapper.get(`input[aria-label="${TE_LABEL}"]`).element as HTMLInputElement).value,
    }).toEqual({ isTheMeInput: true, label: ME_LABEL, value: '3', editedTeValue: '12' })
  })
})
