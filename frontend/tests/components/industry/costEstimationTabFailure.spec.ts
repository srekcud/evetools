import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import CostEstimationTab from '@/components/industry/CostEstimationTab.vue'
import { ApiError, apiRequest } from '@/services/api'
import { useProjectsStore } from '@/stores/industry/projects'
import type { CopyCosts, CostEstimation, IndustryProject } from '@/stores/industry/types'
import { apiOutcomesByPath, rejectWith, respondWith, type ApiOutcome } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-189'
const ISHTAR_TYPE_ID = 12005
const COST_ESTIMATION_PATH = `/industry/projects/${PROJECT_ID}/cost-estimation`
const COPY_COSTS_PATH = `/industry/projects/${PROJECT_ID}/copy-costs`
const COPY_COSTS_ERROR = { en: 'BPC copy costs could not be loaded.', fr: 'Les coûts de copie des BPC n\'ont pas pu être chargés.' } as const

const ISHTAR_PROJECT = {
  id: PROJECT_ID,
  productTypeName: 'Ishtar',
  productTypeId: ISHTAR_TYPE_ID,
  displayName: null,
  runs: 2,
  meLevel: 2,
  teLevel: 4,
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
  createdAt: '2026-10-29T10:00:00+00:00',
  completedAt: null,
  jobsStartDate: null,
  rootProducts: [{ typeId: ISHTAR_TYPE_ID, typeName: 'Ishtar', runs: 2, meLevel: 2, teLevel: 4 }],
  steps: [],
} as IndustryProject

const FIRST_COST_ESTIMATION: CostEstimation = {
  id: PROJECT_ID,
  materialCost: 48_200_000,
  jobInstallCost: 1_350_000,
  bpoCost: 0,
  totalCost: 49_550_000,
  perUnit: 24_775_000,
  outputQuantity: 2,
  materials: [],
  jobInstallSteps: [],
  failedProducts: [],
}

const RETRIED_COST_ESTIMATION: CostEstimation = {
  ...FIRST_COST_ESTIMATION,
  materialCost: 49_610_000,
  jobInstallCost: 1_410_000,
  totalCost: 51_020_000,
  perUnit: 25_510_000,
}

const COPY_COSTS: CopyCosts = {
  id: PROJECT_ID,
  copies: [
    { blueprintTypeId: 627, blueprintName: 'Vexor Blueprint', productTypeName: 'Vexor', runs: 10, cost: 288_000, depth: 1 },
    { blueprintTypeId: 2186, blueprintName: 'Hammerhead II Blueprint', productTypeName: 'Hammerhead II', runs: 4, cost: 124_000, depth: 2 },
  ],
  totalCopyCost: 412_000,
}

const outcomes = apiOutcomesByPath()

function respondToCostEstimation(outcome: ApiOutcome): void {
  outcomes.respondTo(COST_ESTIMATION_PATH, outcome)
}

function respondToCopyCosts(outcome: ApiOutcome): void {
  outcomes.respondTo(COPY_COSTS_PATH, outcome)
}

async function mountActivatedTab(locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  useProjectsStore().currentProject = ISHTAR_PROJECT
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(CostEstimationTab, {
    global: { plugins: [i18n], stubs: { LoadingSpinner: true } },
    props: { projectId: PROJECT_ID },
  })
  await activate(wrapper)
  return wrapper
}

async function activate(wrapper: VueWrapper): Promise<void> {
  await (wrapper.vm as unknown as { activate: () => Promise<void> }).activate()
  await flushPromises()
}

async function clickButton(wrapper: VueWrapper, label: string): Promise<void> {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === label)
  expect(button, `button ${label}`).toBeDefined()
  await button!.trigger('click')
  await flushPromises()
}

function totalProductionCost(wrapper: VueWrapper): string | null {
  const card = wrapper.findAll('.eve-card').find(candidate => candidate.find('p').exists() && candidate.find('p').text() === en.industry.costEstimation.totalProductionCost)
  return card ? card.find('p.text-cyan-400').text() : null
}

function errorCard(wrapper: VueWrapper): { texts: string[], buttons: string[] } | null {
  const card = wrapper.findAll('div').find(candidate => candidate.classes('bg-red-900/20'))
  if (!card) return null
  return { texts: card.findAll('p').map(line => line.text()), buttons: card.findAll('button').map(button => button.text()) }
}

function copyCostsSectionShown(wrapper: VueWrapper): boolean {
  return wrapper.findAll('h4').some(title => title.text() === en.industry.costEstimation.bpcCopyCosts)
}

function copyCostsErrorText(wrapper: VueWrapper): string | null {
  const copyCostsError = wrapper.find('[data-testid="copy-costs-error"]')
  return copyCostsError.exists() ? copyCostsError.text() : null
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  outcomes.clear()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    const answer = outcomes.answer(endpoint)
    if (answer === undefined) throw new Error(`Unexpected request ${endpoint}`)
    return answer
  })
})

describe('CostEstimationTab cost estimation failure (issue 189)', () => {
  it('shows the total production cost and the copy costs without any copy costs alert when both load', async () => {
    respondToCostEstimation(respondWith(FIRST_COST_ESTIMATION))
    respondToCopyCosts(respondWith(COPY_COSTS))

    const wrapper = await mountActivatedTab()

    expect({
      totalProductionCost: totalProductionCost(wrapper),
      copyCostsSectionShown: copyCostsSectionShown(wrapper),
      copyCostsError: copyCostsErrorText(wrapper),
    }).toEqual({ totalProductionCost: '49,550,000', copyCostsSectionShown: true, copyCostsError: null })
  })

  it('shows the error card with the failure and a retry button, without the previous estimation, when refreshing the prices fails', async () => {
    respondToCostEstimation(respondWith(FIRST_COST_ESTIMATION))
    respondToCopyCosts(respondWith(COPY_COSTS))
    respondToCostEstimation(rejectWith(new ApiError('API error: 503', 503)))
    const wrapper = await mountActivatedTab()

    await clickButton(wrapper, en.industry.costEstimation.refreshPrices)

    expect({
      errorCard: errorCard(wrapper),
      previousTotalShown: wrapper.text().includes('49,550,000'),
      lastUpdatedShown: wrapper.text().includes(en.industry.costEstimation.lastUpdated),
      pageError: useProjectsStore().error,
    }).toEqual({
      errorCard: { texts: [en.industry.costEstimation.error, 'API error: 503'], buttons: [en.common.actions.retry] },
      previousTotalShown: false,
      lastUpdatedShown: false,
      pageError: null,
    })
  })

  it('removes the error card and shows the new estimation when the retry succeeds', async () => {
    respondToCostEstimation(respondWith(FIRST_COST_ESTIMATION))
    respondToCopyCosts(respondWith(COPY_COSTS))
    respondToCostEstimation(rejectWith(new ApiError('API error: 503', 503)))
    respondToCostEstimation(respondWith(RETRIED_COST_ESTIMATION))
    const wrapper = await mountActivatedTab()
    await clickButton(wrapper, en.industry.costEstimation.refreshPrices)

    await clickButton(wrapper, en.common.actions.retry)

    expect({ errorCard: errorCard(wrapper), totalProductionCost: totalProductionCost(wrapper) })
      .toEqual({ errorCard: null, totalProductionCost: '51,020,000' })
  })

  it('shows the error card with the failure of the first load and leaves the page error untouched', async () => {
    respondToCostEstimation(rejectWith(new Error('Network down (fictif)')))
    respondToCopyCosts(respondWith(COPY_COSTS))

    const wrapper = await mountActivatedTab()

    expect({ errorCard: errorCard(wrapper), pageError: useProjectsStore().error }).toEqual({
      errorCard: { texts: [en.industry.costEstimation.error, 'Network down (fictif)'], buttons: [en.common.actions.retry] },
      pageError: null,
    })
  })

  it('reloads the estimation when the tab is activated again after a failure', async () => {
    respondToCostEstimation(rejectWith(new Error('Network down (fictif)')))
    respondToCopyCosts(respondWith(COPY_COSTS))
    respondToCostEstimation(respondWith(RETRIED_COST_ESTIMATION))
    const wrapper = await mountActivatedTab()

    await activate(wrapper)

    expect({ errorCard: errorCard(wrapper), totalProductionCost: totalProductionCost(wrapper) })
      .toEqual({ errorCard: null, totalProductionCost: '51,020,000' })
  })

  it('reloads the estimation when the tab is activated again after refreshing the prices failed', async () => {
    respondToCostEstimation(respondWith(FIRST_COST_ESTIMATION))
    respondToCopyCosts(respondWith(COPY_COSTS))
    respondToCostEstimation(rejectWith(new ApiError('API error: 503', 503)))
    respondToCostEstimation(respondWith(RETRIED_COST_ESTIMATION))
    const wrapper = await mountActivatedTab()
    await clickButton(wrapper, en.industry.costEstimation.refreshPrices)

    await activate(wrapper)

    expect({
      errorCard: errorCard(wrapper),
      totalProductionCost: totalProductionCost(wrapper),
      requested: vi.mocked(apiRequest).mock.calls.map(([endpoint]) => endpoint),
    }).toEqual({
      errorCard: null,
      totalProductionCost: '51,020,000',
      requested: [COST_ESTIMATION_PATH, COPY_COSTS_PATH, COST_ESTIMATION_PATH, COST_ESTIMATION_PATH],
    })
  })

  it.each([
    ['en', en.industry.costEstimation.error],
    ['fr', fr.industry.costEstimation.error],
  ] as const)('shows the generic cost estimation failure when the failure carries no message (%s)', async (locale, costEstimationError) => {
    respondToCostEstimation(rejectWith('Gateway timeout (fictif)'))
    respondToCopyCosts(respondWith(COPY_COSTS))

    const wrapper = await mountActivatedTab(locale)

    expect(errorCard(wrapper)?.texts).toEqual([costEstimationError, costEstimationError])
  })
})

describe('CostEstimationTab copy costs failure (issue 189)', () => {
  it.each([
    ['en', COPY_COSTS_ERROR.en],
    ['fr', COPY_COSTS_ERROR.fr],
  ] as const)('keeps the estimation and signals copy costs that could not be loaded instead of hiding them (%s)', async (locale, copyCostsError) => {
    respondToCostEstimation(respondWith(FIRST_COST_ESTIMATION))
    respondToCopyCosts(rejectWith(new ApiError('API error: 503', 503)))

    const wrapper = await mountActivatedTab(locale)

    const alert = wrapper.find('[data-testid="copy-costs-error"]')
    expect({
      estimationShown: wrapper.text().includes('49,550,000'),
      copyCostsError: alert.exists() ? alert.text() : null,
      role: alert.exists() ? alert.attributes('role') : null,
      red: alert.exists() ? alert.classes('text-red-400') : null,
      copyTableShown: wrapper.text().includes('Vexor Blueprint'),
      pageError: useProjectsStore().error,
    }).toEqual({ estimationShown: true, copyCostsError, role: 'alert', red: true, copyTableShown: false, pageError: null })
  })

  it('removes the copy costs alert and shows the copy costs when the tab is activated again and they load', async () => {
    respondToCostEstimation(respondWith(FIRST_COST_ESTIMATION))
    respondToCopyCosts(rejectWith(new ApiError('API error: 503', 503)))
    respondToCopyCosts(respondWith(COPY_COSTS))
    const wrapper = await mountActivatedTab()

    await activate(wrapper)

    expect({
      copyCostsError: copyCostsErrorText(wrapper),
      copyCostsSectionShown: copyCostsSectionShown(wrapper),
      totalCopyCostShown: wrapper.text().includes('412,000 ISK'),
    }).toEqual({ copyCostsError: null, copyCostsSectionShown: true, totalCopyCostShown: true })
  })

  it('removes the copy costs alert when the tab is activated again and the project needs no copy', async () => {
    respondToCostEstimation(respondWith(FIRST_COST_ESTIMATION))
    respondToCopyCosts(rejectWith(new ApiError('API error: 503', 503)))
    respondToCopyCosts(respondWith({ id: PROJECT_ID, copies: [], totalCopyCost: 0 } satisfies CopyCosts))
    const wrapper = await mountActivatedTab()
    const copyCostsErrorAfterFailure = copyCostsErrorText(wrapper)

    await activate(wrapper)

    expect({
      copyCostsErrorAfterFailure,
      copyCostsError: copyCostsErrorText(wrapper),
      copyCostsSectionShown: copyCostsSectionShown(wrapper),
    }).toEqual({ copyCostsErrorAfterFailure: COPY_COSTS_ERROR.en, copyCostsError: null, copyCostsSectionShown: false })
  })
})
