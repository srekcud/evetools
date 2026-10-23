import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import StepsTab from '@/components/industry/StepsTab.vue'
import { apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { IndustryProject, IndustryProjectStep } from '@/stores/industry/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-185'
const FUSION_REACTOR_UNIT_STEP_ID = 'step-fusion-reactor-unit'
const FUSION_REACTOR_UNIT_NAME = 'Fusion Reactor Unit'
const TITANIUM_CARBIDE_STEP_ID = 'step-titanium-carbide'
const TITANIUM_CARBIDE_NAME = 'Titanium Carbide'
const PLANNED_FUSION_REACTOR_UNIT_RUNS = 12
const ENTERED_RUNS = 20
const CAPPED_RUNS = 18
const FULLY_SERVED_RUNS = 15
const FIXED_FUSION_REACTOR_UNIT_RUNS = 14
const TITANIUM_CARBIDE_RUNS = 30
const LINKED_ESI_JOB_ID = 701234567

function step(overrides: Partial<IndustryProjectStep> & Pick<IndustryProjectStep, 'id' | 'blueprintTypeId' | 'productTypeId' | 'productTypeName' | 'quantity' | 'runs' | 'depth' | 'activityType'>): IndustryProjectStep {
  return {
    runsFixed: false,
    sortOrder: 0,
    purchased: false,
    inStockQuantity: 0,
    meLevel: 10,
    teLevel: 20,
    jobMatchMode: 'auto',
    structureConfigId: null,
    structureConfigName: null,
    structureMaterialBonus: null,
    structureTimeBonus: null,
    favoriteSystemWithoutSuitableStructure: false,
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

function fusionReactorUnitStep(runs: number, overrides: Partial<IndustryProjectStep> = {}): IndustryProjectStep {
  return step({
    id: FUSION_REACTOR_UNIT_STEP_ID,
    blueprintTypeId: 17330,
    productTypeId: 11694,
    productTypeName: FUSION_REACTOR_UNIT_NAME,
    quantity: runs,
    runs,
    depth: 1,
    activityType: 'manufacturing',
    ...overrides,
  })
}

function titaniumCarbideStep(): IndustryProjectStep {
  return step({
    id: TITANIUM_CARBIDE_STEP_ID,
    blueprintTypeId: 17960,
    productTypeId: 16671,
    productTypeName: TITANIUM_CARBIDE_NAME,
    quantity: TITANIUM_CARBIDE_RUNS * 200,
    runs: TITANIUM_CARBIDE_RUNS,
    depth: 2,
    activityType: 'reaction',
    meLevel: 0,
    teLevel: 0,
  })
}

function project(steps: IndustryProjectStep[], status = 'active'): IndustryProject {
  return {
    id: PROJECT_ID,
    productTypeName: 'Ishtar',
    productTypeId: 12005,
    displayName: null,
    runs: 1,
    meLevel: 10,
    teLevel: 20,
    maxJobDurationDays: 2,
    status,
    personalUse: false,
    isT2: true,
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
    createdAt: '2026-10-23T08:00:00+00:00',
    completedAt: null,
    jobsStartDate: null,
    steps,
    tree: null,
  }
}

function mountStepsTab(steps: IndustryProjectStep[], options: { status?: string; locale?: 'en' | 'fr' } = {}) {
  useProjectsStore().currentProject = project(steps, options.status)
  const i18n = createI18n({ legacy: false, locale: options.locale ?? 'en', messages: { en, fr } })
  return mount(StepsTab, {
    props: { projectId: PROJECT_ID },
    global: { plugins: [i18n], stubs: { StepHierarchyTree: true } },
  })
}

function parentRow(wrapper: VueWrapper, productName: string) {
  const row = wrapper.findAll('.step-row:not(.ml-6)').find(candidate => candidate.text().includes(productName))
  if (!row) throw new Error(`No group row for ${productName}`)
  return row
}

function stepRow(wrapper: VueWrapper, productName: string) {
  const row = wrapper.findAll('.step-row.ml-6').find(candidate => candidate.text().includes(productName))
  if (!row) throw new Error(`No step row for ${productName}`)
  return row
}

function shownElement(row: ReturnType<typeof stepRow>, selector: string) {
  const element = row.find(selector)
  expect(element.exists(), selector).toBe(true)
  return element
}

async function expandGroup(wrapper: VueWrapper, productName: string) {
  await parentRow(wrapper, productName).trigger('click')
}

async function enterRuns(wrapper: VueWrapper, productName: string, currentRuns: number, enteredRuns: number) {
  const runsLabel = stepRow(wrapper, productName).findAll('span').find(span => span.text() === `(${currentRuns} runs)`)
  if (!runsLabel) throw new Error(`No runs label for ${productName}`)
  await runsLabel.trigger('dblclick')
  const runsInput = stepRow(wrapper, productName).find('input[min="1"]')
  await runsInput.setValue(enteredRuns)
  await runsInput.trigger('keydown', { key: 'Enter' })
  await flushPromises()
}

function stepPatchBodies(): unknown[] {
  return vi.mocked(apiRequest).mock.calls
    .filter(([path, options]) => path === `/industry/projects/${PROJECT_ID}/steps/${FUSION_REACTOR_UNIT_STEP_ID}` && options?.method === 'PATCH')
    .map(([, options]) => JSON.parse(String(options?.body)))
}

const OVERPRODUCE_BUTTON = '[data-testid="overproduce-step-runs"]'
const FIXED_RUNS_INDICATOR = '[data-testid="fixed-step-runs"]'
const RELEASE_BUTTON = '[data-testid="release-step-runs"]'

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
})

describe('StepsTab fixed step runs', () => {
  it('offers to overproduce the entered runs when the response caps them', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce([fusionReactorUnitStep(CAPPED_RUNS, { runsFixed: true })])
    const wrapper = mountStepsTab([fusionReactorUnitStep(PLANNED_FUSION_REACTOR_UNIT_RUNS), titaniumCarbideStep()])
    await expandGroup(wrapper, FUSION_REACTOR_UNIT_NAME)

    await enterRuns(wrapper, FUSION_REACTOR_UNIT_NAME, PLANNED_FUSION_REACTOR_UNIT_RUNS, ENTERED_RUNS)

    expect(stepPatchBodies()).toEqual([{ runs: ENTERED_RUNS }])
    expect(shownElement(stepRow(wrapper, FUSION_REACTOR_UNIT_NAME), OVERPRODUCE_BUTTON).text()).toBe('Overproduce 20 runs')
  })

  it('labels the overproduction offer in french', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce([fusionReactorUnitStep(CAPPED_RUNS, { runsFixed: true })])
    const wrapper = mountStepsTab([fusionReactorUnitStep(PLANNED_FUSION_REACTOR_UNIT_RUNS)], { locale: 'fr' })
    await expandGroup(wrapper, FUSION_REACTOR_UNIT_NAME)

    await enterRuns(wrapper, FUSION_REACTOR_UNIT_NAME, PLANNED_FUSION_REACTOR_UNIT_RUNS, ENTERED_RUNS)

    expect(shownElement(stepRow(wrapper, FUSION_REACTOR_UNIT_NAME), OVERPRODUCE_BUTTON).text()).toBe('Surproduire 20 runs')
  })

  it('resends the entered runs with the overproduction confirmation then hides the offer', async () => {
    vi.mocked(apiRequest)
      .mockResolvedValueOnce([fusionReactorUnitStep(CAPPED_RUNS, { runsFixed: true })])
      .mockResolvedValueOnce([fusionReactorUnitStep(ENTERED_RUNS, { runsFixed: true })])
    const wrapper = mountStepsTab([fusionReactorUnitStep(PLANNED_FUSION_REACTOR_UNIT_RUNS)])
    await expandGroup(wrapper, FUSION_REACTOR_UNIT_NAME)
    await enterRuns(wrapper, FUSION_REACTOR_UNIT_NAME, PLANNED_FUSION_REACTOR_UNIT_RUNS, ENTERED_RUNS)

    await shownElement(stepRow(wrapper, FUSION_REACTOR_UNIT_NAME), OVERPRODUCE_BUTTON).trigger('click')
    await flushPromises()

    expect(stepPatchBodies()).toEqual([{ runs: ENTERED_RUNS }, { runs: ENTERED_RUNS, overproduce: true }])
    expect(stepRow(wrapper, FUSION_REACTOR_UNIT_NAME).find(OVERPRODUCE_BUTTON).exists()).toBe(false)
  })

  it('offers no overproduction when the entered runs are fully served', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce([fusionReactorUnitStep(FULLY_SERVED_RUNS, { runsFixed: true })])
    const wrapper = mountStepsTab([fusionReactorUnitStep(PLANNED_FUSION_REACTOR_UNIT_RUNS)])
    await expandGroup(wrapper, FUSION_REACTOR_UNIT_NAME)

    await enterRuns(wrapper, FUSION_REACTOR_UNIT_NAME, PLANNED_FUSION_REACTOR_UNIT_RUNS, FULLY_SERVED_RUNS)

    expect(stepPatchBodies()).toEqual([{ runs: FULLY_SERVED_RUNS }])
    expect(stepRow(wrapper, FUSION_REACTOR_UNIT_NAME).find(OVERPRODUCE_BUTTON).exists()).toBe(false)
  })

  it('flags a fixed step with a release to the automatic calculation', async () => {
    const wrapper = mountStepsTab([
      fusionReactorUnitStep(FIXED_FUSION_REACTOR_UNIT_RUNS, { runsFixed: true }),
      titaniumCarbideStep(),
    ])
    await expandGroup(wrapper, FUSION_REACTOR_UNIT_NAME)
    await expandGroup(wrapper, TITANIUM_CARBIDE_NAME)

    const fixedRow = stepRow(wrapper, FUSION_REACTOR_UNIT_NAME)
    expect(shownElement(fixedRow, FIXED_RUNS_INDICATOR).text()).toBe('Fixed runs')
    expect(shownElement(fixedRow, RELEASE_BUTTON).text()).toBe('Automatic calculation')
    const automaticRow = stepRow(wrapper, TITANIUM_CARBIDE_NAME)
    expect(automaticRow.find(FIXED_RUNS_INDICATOR).exists()).toBe(false)
    expect(automaticRow.find(RELEASE_BUTTON).exists()).toBe(false)
  })

  it('labels the fixed step and its release in french', async () => {
    const wrapper = mountStepsTab([fusionReactorUnitStep(FIXED_FUSION_REACTOR_UNIT_RUNS, { runsFixed: true })], { locale: 'fr' })
    await expandGroup(wrapper, FUSION_REACTOR_UNIT_NAME)

    const fixedRow = stepRow(wrapper, FUSION_REACTOR_UNIT_NAME)
    expect(shownElement(fixedRow, FIXED_RUNS_INDICATOR).text()).toBe('Runs fixés')
    expect(shownElement(fixedRow, RELEASE_BUTTON).text()).toBe('Calcul automatique')
  })

  it('releases the fixed runs on the automatic calculation button', async () => {
    vi.mocked(apiRequest).mockResolvedValueOnce([fusionReactorUnitStep(PLANNED_FUSION_REACTOR_UNIT_RUNS)])
    const wrapper = mountStepsTab([fusionReactorUnitStep(FIXED_FUSION_REACTOR_UNIT_RUNS, { runsFixed: true })])
    await expandGroup(wrapper, FUSION_REACTOR_UNIT_NAME)

    await shownElement(stepRow(wrapper, FUSION_REACTOR_UNIT_NAME), RELEASE_BUTTON).trigger('click')
    await flushPromises()

    expect(stepPatchBodies()).toEqual([{ runsFixed: false }])
    const releasedRow = stepRow(wrapper, FUSION_REACTOR_UNIT_NAME)
    expect(releasedRow.find(FIXED_RUNS_INDICATOR).exists()).toBe(false)
    expect(releasedRow.find(RELEASE_BUTTON).exists()).toBe(false)
  })

  it('offers no release on a completed project', async () => {
    const wrapper = mountStepsTab(
      [fusionReactorUnitStep(FIXED_FUSION_REACTOR_UNIT_RUNS, { runsFixed: true })],
      { status: 'completed' },
    )
    await expandGroup(wrapper, FUSION_REACTOR_UNIT_NAME)

    const fixedRow = stepRow(wrapper, FUSION_REACTOR_UNIT_NAME)
    expect(shownElement(fixedRow, FIXED_RUNS_INDICATOR).text()).toBe('Fixed runs')
    expect(fixedRow.find(RELEASE_BUTTON).exists()).toBe(false)
  })

  it('offers no release on a step linked to an ESI job', async () => {
    const wrapper = mountStepsTab([
      fusionReactorUnitStep(FIXED_FUSION_REACTOR_UNIT_RUNS, { runsFixed: true, esiJobId: LINKED_ESI_JOB_ID, esiJobStatus: 'active' }),
    ])
    await expandGroup(wrapper, FUSION_REACTOR_UNIT_NAME)

    const fixedRow = stepRow(wrapper, FUSION_REACTOR_UNIT_NAME)
    expect(shownElement(fixedRow, FIXED_RUNS_INDICATOR).text()).toBe('Fixed runs')
    expect(fixedRow.find(RELEASE_BUTTON).exists()).toBe(false)
  })
})
