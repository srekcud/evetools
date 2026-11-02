import { enableAutoUnmount, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import StepTree from '@/components/industry/StepTree.vue'
import type { IndustryProjectStep } from '@/stores/industry/types'
import { expectNoneOf, expectOnlyLocaleTexts, messageOf, TEST_LOCALES, type TestLocale } from '../../localeTexts'

const BONUS_PERCENT = 4.2
const SIMILAR_JOB_RUNS = 6
const JOB_RUNS = 9
const DELIVERED_RUNS = 5
const ACTIVE_RUNS = 4

const FORMER_ENGLISH_TEXTS = ['Split 1/2', 'Split 2/2', 'Bonus: 4.2%', 'Manufacturing']
const FORMER_FRENCH_TEXTS = ['(manuel)', '5 livrés, 4 en cours', 'Runs :', 'Division']

function formerHardcodedTextsOtherThan(locale: TestLocale): string[] {
  return locale === 'fr' ? FORMER_ENGLISH_TEXTS : FORMER_FRENCH_TEXTS
}

function stepOf(overrides: Partial<IndustryProjectStep>): IndustryProjectStep {
  return {
    id: 'step-282',
    blueprintTypeId: 28201,
    productTypeId: 28202,
    productTypeName: 'Unknown Skills Hull',
    quantity: 18,
    runs: 18,
    depth: 0,
    activityType: 'manufacturing',
    runsFixed: false,
    sortOrder: 0,
    purchased: false,
    inStockQuantity: 0,
    meLevel: 10,
    teLevel: 0,
    jobMatchMode: 'auto',
    structureConfigId: null,
    structureConfigName: null,
    structureMaterialBonus: null,
    structureTimeBonus: null,
    favoriteSystemWithoutSuitableStructure: false,
    deletable: true,
    timePerRun: null,
    recommendedCharacterName: null,
    splitGroupId: null,
    splitIndex: 0,
    totalGroupRuns: null,
    facilityInfoType: null,
    actualFacilityName: null,
    bestStructureName: null,
    bestMaterialBonus: null,
    jobMatches: [],
    jobsCost: null,
    purchases: [],
    purchasesCost: null,
    purchasedQuantity: 0,
    inStock: false,
    esiJobId: null,
    esiJobCost: null,
    esiJobStatus: null,
    esiJobEndDate: null,
    esiJobRuns: null,
    esiJobCharacterName: null,
    esiJobsCount: null,
    esiJobsTotalRuns: null,
    esiJobsActiveRuns: null,
    esiJobsDeliveredRuns: null,
    manualJobData: false,
    recommendedStructureName: null,
    structureBonus: null,
    similarJobs: [],
    ...overrides,
  }
}

function jobOfASplitGroup(splitIndex: number, overrides: Partial<IndustryProjectStep>): IndustryProjectStep {
  return stepOf({
    id: `step-220-job-${splitIndex}`,
    blueprintTypeId: 22011,
    productTypeId: 22012,
    productTypeName: 'Localized Battleship',
    quantity: JOB_RUNS,
    runs: JOB_RUNS,
    depth: 1,
    timePerRun: 9000,
    splitGroupId: 'split-group-220',
    splitIndex,
    totalGroupRuns: 18,
    recommendedStructureName: 'Raitaru Forge',
    structureBonus: BONUS_PERCENT,
    ...overrides,
  })
}

const STEPS_WITH_JOBS_AND_SIMILAR_JOBS: IndustryProjectStep[] = [
  jobOfASplitGroup(0, {
    esiJobId: 220001,
    esiJobStatus: 'active',
    esiJobCharacterName: 'Hild Brandt',
    esiJobsCount: 2,
    esiJobsTotalRuns: JOB_RUNS,
    esiJobsDeliveredRuns: DELIVERED_RUNS,
    esiJobsActiveRuns: ACTIVE_RUNS,
    similarJobs: [{ esiJobId: 220002, runs: SIMILAR_JOB_RUNS, status: 'active', characterName: 'Oda Lind', facilityId: null, facilityName: null }],
  }),
  jobOfASplitGroup(1, { esiJobId: null, manualJobData: true }),
]

function mountStepTree(locale: TestLocale): VueWrapper {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  return mount(StepTree, { props: { steps: STEPS_WITH_JOBS_AND_SIMILAR_JOBS }, global: { plugins: [i18n] } })
}

function titlesOf(wrapper: Pick<DOMWrapper<Element>, 'findAll'>): string {
  return wrapper.findAll('[title]').map(element => element.attributes('title')).join('\n')
}

function format(locale: TestLocale, key: string, values: Record<string, string | number>): string {
  return Object.entries(values).reduce((text, [name, value]) => text.replace(`{${name}}`, String(value)), messageOf(locale, key))
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('StepTree locale', () => {
  it.each(TEST_LOCALES)('shows only %s texts on the split group row', (locale) => {
    const wrapper = mountStepTree(locale)

    const text = wrapper.text()
    expect(text).toContain(format(locale, 'industry.stepTree.jobsCount', { count: 2 }))
    expect(text).toContain(format(locale, 'industry.stepTree.runsProgress', { actual: 18, expected: 18 }))
    expect(text).toContain(messageOf(locale, 'industry.activity.manufacturing'))
    expect(titlesOf(wrapper)).toContain(format(locale, 'industry.stepTree.structureBonus', { percent: BONUS_PERCENT }))
    expectNoneOf(text, formerHardcodedTextsOtherThan(locale))
    expectNoneOf(titlesOf(wrapper), formerHardcodedTextsOtherThan(locale))
  })

  it.each(TEST_LOCALES)('shows only %s texts on the rows of the jobs', async (locale) => {
    const wrapper = mountStepTree(locale)
    await wrapper.get('button[aria-expanded="false"]').trigger('click')

    const rows = wrapper.findAll('.step-row').filter(row => !row.find('button[aria-expanded]').exists())
    expect(rows).toHaveLength(2)
    const [linkedJobRow, manualJobRow] = rows

    expect(linkedJobRow.text()).toContain(format(locale, 'industry.stepTree.runsProgress', { actual: JOB_RUNS, expected: JOB_RUNS }))
    expect(linkedJobRow.text()).toContain(format(locale, 'industry.stepTree.jobsCount', { count: 2 }))
    expect(linkedJobRow.text()).toContain(format(locale, 'industry.stepTree.esiJobsBreakdown', { delivered: DELIVERED_RUNS, active: ACTIVE_RUNS }))
    expect(linkedJobRow.text()).toContain(format(locale, 'industry.stepTree.runsCount', { count: JOB_RUNS }))
    expect(linkedJobRow.text()).toContain(format(locale, 'industry.stepTree.splitIndex', { index: 1, total: 2 }))
    expect(linkedJobRow.text()).toContain(`${messageOf(locale, 'industry.stepTree.perRun')})`)
    expect(titlesOf(linkedJobRow)).toContain(format(locale, 'industry.stepTree.similarJobLine', {
      character: 'Oda Lind',
      runs: SIMILAR_JOB_RUNS,
      status: messageOf(locale, 'industry.stepStatus.active'),
    }))
    expect(manualJobRow.text()).toContain(`(${messageOf(locale, 'industry.stepTree.manualJob')})`)
    expect(manualJobRow.text()).toContain(format(locale, 'industry.stepTree.splitIndex', { index: 2, total: 2 }))

    expectNoneOf(wrapper.text(), formerHardcodedTextsOtherThan(locale))
    expectNoneOf(titlesOf(wrapper), formerHardcodedTextsOtherThan(locale))
  })

  it.each(TEST_LOCALES)('shows the %s runs label when adding a job to a split group', async (locale) => {
    const wrapper = mountStepTree(locale)
    await wrapper.get('button[aria-expanded="false"]').trigger('click')

    const addJobButton = wrapper.findAll('button').find(button => button.text() === messageOf(locale, 'industry.stepTree.addJob'))
    if (addJobButton === undefined) throw new Error('add job button not found')
    await addJobButton.trigger('click')

    expectOnlyLocaleTexts(wrapper.text(), locale, ['industry.stepTree.runsLabel'])
    expectNoneOf(wrapper.text(), formerHardcodedTextsOtherThan(locale))
  })
})
