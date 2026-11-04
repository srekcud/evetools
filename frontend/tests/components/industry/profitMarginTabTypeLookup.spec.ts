import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import ProductSearch from '@/components/industry/ProductSearch.vue'
import BuyVsBuildTab from '@/components/industry/BuyVsBuildTab.vue'
import PivotAdvisorTab from '@/components/industry/PivotAdvisorTab.vue'
import { ApiError, apiRequest } from '@/services/api'
import type { ProfitMarginResult, SearchResult, UserSettings } from '@/stores/industry/types'
import { apiOutcomesByPath, awaiting, deferred, rejectWith, respondWith, type ApiOutcome } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const TYPE_LOOKUP_ENDPOINT = '/industry/types/'
const PROFIT_MARGIN_ENDPOINT = '/industry/profit-margin/'
const SETTINGS_ENDPOINT = '/industry/settings'
const TYPE_LOOKUP_FAILED_KEY = 'industry.margins.typeLookupFailed'

const FEROX_TYPE_ID = 990001
const HECATE_TYPE_ID = 990002
const FERNITE_CARBIDE_TYPE_ID = 990003
const ABSENT_TYPE_ID = 990099

const FEROX: SearchResult = { typeId: FEROX_TYPE_ID, typeName: 'Ferox', isT2: false }
const HECATE: SearchResult = { typeId: HECATE_TYPE_ID, typeName: 'Hecate', isT2: true }
const FERNITE_CARBIDE: SearchResult = { typeId: FERNITE_CARBIDE_TYPE_ID, typeName: 'Fernite Carbide', isT2: false }

const FEROX_ONLY_MATERIAL = 'Mexallon'
const HECATE_ONLY_MATERIAL = 'Morphite'

const USER_SETTINGS: UserSettings = {
  favoriteManufacturingSystemId: null,
  favoriteManufacturingSystemName: null,
  favoriteReactionSystemId: null,
  favoriteReactionSystemName: null,
  brokerFeeRate: 0.025,
  salesTaxRate: 0.045,
  exportCostPerM3: 1200,
}

const FEROX_MARGIN: ProfitMarginResult = {
  typeId: FEROX_TYPE_ID,
  typeName: 'Ferox',
  isT2: false,
  runs: 1,
  outputQuantity: 1,
  outputPerRun: 1,
  materialCost: 41_250_000,
  materials: [
    { typeId: 36, typeName: FEROX_ONLY_MATERIAL, quantity: 125_000, unitPrice: 330, totalPrice: 41_250_000 },
  ],
  jobInstallCost: 612_000,
  jobInstallSteps: [],
  inventionCost: null,
  totalCost: 41_862_000,
  costPerUnit: 41_862_000,
  invention: null,
  sellPrices: {
    jitaSell: 52_400_000,
    structureSell: 51_100_000,
    structureBuy: 47_800_000,
    contractSell: null,
    contractCount: null,
    jitaSellCoveragePercent: null,
    structureId: 1_035_466_617_946,
    structureName: 'Tatara Alpha',
  },
  brokerFeeRate: 0.03,
  salesTaxRate: 0.036,
  margins: {
    jitaSell: { revenue: 52_400_000, fees: 3_458_400, profit: 7_079_600, margin: 13.51 },
  },
  dailyVolume: 21,
  missingData: [],
  warnings: [],
}

const HECATE_MARGIN: ProfitMarginResult = {
  ...FEROX_MARGIN,
  typeId: HECATE_TYPE_ID,
  typeName: 'Hecate',
  isT2: true,
  materialCost: 33_480_000,
  materials: [
    { typeId: 11_399, typeName: HECATE_ONLY_MATERIAL, quantity: 3_600, unitPrice: 9_300, totalPrice: 33_480_000 },
  ],
  jobInstallCost: 948_000,
  totalCost: 34_428_000,
  costPerUnit: 34_428_000,
  sellPrices: { ...FEROX_MARGIN.sellPrices, jitaSell: 61_900_000, structureSell: 60_200_000, structureBuy: 55_700_000 },
  margins: {
    jitaSell: { revenue: 61_900_000, fees: 4_085_400, profit: 23_386_600, margin: 37.78 },
  },
  dailyVolume: 9,
}

const outcomes = apiOutcomesByPath()

function respondToLookupOf(typeId: number, outcome: ApiOutcome): void {
  outcomes.respondTo(`${TYPE_LOOKUP_ENDPOINT}${typeId}`, outcome)
}

function respondToMarginOf(typeId: number, outcome: ApiOutcome): void {
  outcomes.respondTo(`${PROFIT_MARGIN_ENDPOINT}${typeId}`, outcome)
}

function requestedEndpoints(): string[] {
  return vi.mocked(apiRequest).mock.calls
    .map(([endpoint]) => endpoint)
    .filter(endpoint => endpoint !== SETTINGS_ENDPOINT)
}

function lookupRequests(): string[] {
  return requestedEndpoints().filter(endpoint => endpoint.startsWith(TYPE_LOOKUP_ENDPOINT))
}

function marginRequests(): string[] {
  return requestedEndpoints().filter(endpoint => endpoint.startsWith(PROFIT_MARGIN_ENDPOINT))
}

function createTestI18n(locale: 'en' | 'fr' = 'en') {
  return createI18n({ legacy: false, locale, messages: { en, fr }, missingWarn: false, fallbackWarn: false })
}

async function mountMarginsTab(initialTypeId?: number, locale: 'en' | 'fr' = 'en'): Promise<VueWrapper> {
  const wrapper = mount(ProfitMarginTab, {
    global: { plugins: [createTestI18n(locale)], stubs: { ProductSearch: true } },
    props: initialTypeId === undefined ? {} : { initialTypeId },
  })
  await flushPromises()
  return wrapper
}

async function openType(wrapper: VueWrapper, typeId: number): Promise<void> {
  await wrapper.setProps({ initialTypeId: typeId })
  await flushPromises()
}

function levelInput(wrapper: VueWrapper, label: string) {
  const levelLabel = wrapper.findAll('label').find(candidate => candidate.text() === label)
  if (levelLabel === undefined) throw new Error(`No ${label} label`)
  return wrapper.find(`[id="${levelLabel.attributes('for')}"]`)
}

function levels(wrapper: VueWrapper) {
  const me = levelInput(wrapper, en.industry.createProject.me).element as HTMLInputElement
  const te = levelInput(wrapper, en.industry.createProject.te).element as HTMLInputElement
  return { me: Number(me.value), te: Number(te.value), meLocked: me.disabled, teLocked: te.disabled }
}

function analyzeButton(wrapper: VueWrapper) {
  const button = wrapper.findAll('button').find(candidate => candidate.text() === en.industry.margins.analyze)
  if (button === undefined) throw new Error('No analyze button')
  return button
}

function typeLookupError(wrapper: VueWrapper) {
  return wrapper.find('[data-testid="type-lookup-error"]')
}

function retryButton(wrapper: VueWrapper, label: string = en.common.actions.retry) {
  const lookupError = typeLookupError(wrapper)
  if (!lookupError.exists()) return undefined
  return lookupError.findAll('button').find(candidate => candidate.text() === label)
}

function selectedProductName(wrapper: VueWrapper): string | null {
  const icon = wrapper.find('img[src*="/types/"]')
  return icon.exists() ? icon.attributes('alt') ?? null : null
}

async function chooseInSearch(wrapper: VueWrapper, product: SearchResult): Promise<void> {
  wrapper.findComponent(ProductSearch).vm.$emit('select', product)
  await flushPromises()
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  outcomes.clear()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    if (endpoint === SETTINGS_ENDPOINT) return USER_SETTINGS
    const answer = outcomes.answer(endpoint)
    if (answer === undefined) throw new Error(`Unexpected request ${endpoint}`)
    return answer
  })
})

describe('ProfitMarginTab opened type defaults (issue 238)', () => {
  it('looks the T1 type up before analyzing it with ME 10 and TE 20', async () => {
    respondToLookupOf(FEROX_TYPE_ID, respondWith(FEROX))
    respondToMarginOf(FEROX_TYPE_ID, respondWith(FEROX_MARGIN))

    await mountMarginsTab(FEROX_TYPE_ID)

    expect(requestedEndpoints()).toEqual([
      `${TYPE_LOOKUP_ENDPOINT}${FEROX_TYPE_ID}`,
      `${PROFIT_MARGIN_ENDPOINT}${FEROX_TYPE_ID}?runs=1&me=10&te=20`,
    ])
  })

  it('analyzes the T2 type with ME 2 and TE 4, levels locked before the analysis answers', async () => {
    respondToLookupOf(HECATE_TYPE_ID, respondWith(HECATE))
    respondToMarginOf(HECATE_TYPE_ID, awaiting(deferred<ProfitMarginResult>()))

    const wrapper = await mountMarginsTab(HECATE_TYPE_ID)

    expect({
      requests: requestedEndpoints(),
      levels: levels(wrapper),
      selectedProduct: selectedProductName(wrapper),
    }).toEqual({
      requests: [
        `${TYPE_LOOKUP_ENDPOINT}${HECATE_TYPE_ID}`,
        `${PROFIT_MARGIN_ENDPOINT}${HECATE_TYPE_ID}?runs=1&me=2&te=4`,
      ],
      levels: { me: 2, te: 4, meLocked: true, teLocked: true },
      selectedProduct: 'Hecate',
    })
  })

  it('analyzes a reaction product with the T1 defaults', async () => {
    respondToLookupOf(FERNITE_CARBIDE_TYPE_ID, respondWith(FERNITE_CARBIDE))
    respondToMarginOf(FERNITE_CARBIDE_TYPE_ID, awaiting(deferred<ProfitMarginResult>()))

    await mountMarginsTab(FERNITE_CARBIDE_TYPE_ID)

    expect(marginRequests()).toEqual([`${PROFIT_MARGIN_ENDPOINT}${FERNITE_CARBIDE_TYPE_ID}?runs=1&me=10&te=20`])
  })

  it('analyzes nothing while the type is being looked up, analyze disabled and loading shown', async () => {
    respondToLookupOf(HECATE_TYPE_ID, awaiting(deferred<SearchResult>()))

    const wrapper = await mountMarginsTab(HECATE_TYPE_ID)

    expect({
      lookups: lookupRequests(),
      analyses: marginRequests(),
      analyzeDisabled: analyzeButton(wrapper).attributes('disabled') !== undefined,
      loadingShown: wrapper.text().includes(en.common.status.loading),
    }).toEqual({
      lookups: [`${TYPE_LOOKUP_ENDPOINT}${HECATE_TYPE_ID}`],
      analyses: [],
      analyzeDisabled: true,
      loadingShown: true,
    })
  })

  it('keeps the levels changed by hand after the opened type was analyzed', async () => {
    respondToLookupOf(FEROX_TYPE_ID, respondWith(FEROX))
    respondToMarginOf(FEROX_TYPE_ID, respondWith(FEROX_MARGIN))
    respondToMarginOf(FEROX_TYPE_ID, awaiting(deferred<ProfitMarginResult>()))
    const wrapper = await mountMarginsTab(FEROX_TYPE_ID)

    await levelInput(wrapper, en.industry.createProject.me).setValue(7)
    await levelInput(wrapper, en.industry.createProject.te).setValue(14)
    await analyzeButton(wrapper).trigger('click')
    await flushPromises()

    expect({ lookups: lookupRequests(), analyses: marginRequests() }).toEqual({
      lookups: [`${TYPE_LOOKUP_ENDPOINT}${FEROX_TYPE_ID}`],
      analyses: [
        `${PROFIT_MARGIN_ENDPOINT}${FEROX_TYPE_ID}?runs=1&me=10&te=20`,
        `${PROFIT_MARGIN_ENDPOINT}${FEROX_TYPE_ID}?runs=1&me=7&te=14`,
      ],
    })
  })

  it('keeps the runs of the tab when applying the defaults of the opened type', async () => {
    respondToLookupOf(HECATE_TYPE_ID, respondWith(HECATE))
    respondToMarginOf(HECATE_TYPE_ID, awaiting(deferred<ProfitMarginResult>()))
    const wrapper = await mountMarginsTab()
    await levelInput(wrapper, en.industry.createProject.runs).setValue(12)

    await openType(wrapper, HECATE_TYPE_ID)

    expect(marginRequests()).toEqual([`${PROFIT_MARGIN_ENDPOINT}${HECATE_TYPE_ID}?runs=12&me=2&te=4`])
  })
})

describe('ProfitMarginTab type lookup failure (issue 238)', () => {
  it.each([
    ['not found', new ApiError('Not Found', 404)],
    ['network error', new TypeError('Failed to fetch')],
  ])('shows the lookup failure with a retry and analyzes nothing on a %s', async (_failure, reason) => {
    respondToLookupOf(ABSENT_TYPE_ID, rejectWith(reason))
    const i18n = createTestI18n()

    const wrapper = await mountMarginsTab(ABSENT_TYPE_ID)

    expect({
      analyses: marginRequests(),
      lookupError: typeLookupError(wrapper).exists() ? typeLookupError(wrapper).text() : null,
      retryShown: retryButton(wrapper) !== undefined,
      marginError: wrapper.find('[data-testid="margin-error"]').exists(),
    }).toEqual({
      analyses: [],
      lookupError: expect.stringContaining(i18n.global.t(TYPE_LOOKUP_FAILED_KEY, { typeId: ABSENT_TYPE_ID })),
      retryShown: true,
      marginError: false,
    })
  })

  it('names the type id in the lookup failure message of both locales', () => {
    const i18n = createTestI18n()

    expect({
      en: i18n.global.te(TYPE_LOOKUP_FAILED_KEY, 'en'),
      fr: i18n.global.te(TYPE_LOOKUP_FAILED_KEY, 'fr'),
      enNamesTheType: i18n.global.t(TYPE_LOOKUP_FAILED_KEY, { typeId: ABSENT_TYPE_ID }, { locale: 'en' }).includes(String(ABSENT_TYPE_ID)),
      frNamesTheType: i18n.global.t(TYPE_LOOKUP_FAILED_KEY, { typeId: ABSENT_TYPE_ID }, { locale: 'fr' }).includes(String(ABSENT_TYPE_ID)),
    }).toEqual({ en: true, fr: true, enNamesTheType: true, frNamesTheType: true })
  })

  it('shows the lookup failure in French with the French retry label', async () => {
    respondToLookupOf(ABSENT_TYPE_ID, rejectWith(new ApiError('Not Found', 404)))
    const i18n = createTestI18n('fr')

    const wrapper = await mountMarginsTab(ABSENT_TYPE_ID, 'fr')

    expect({
      lookupError: typeLookupError(wrapper).exists() ? typeLookupError(wrapper).text() : null,
      retryShown: retryButton(wrapper, fr.common.actions.retry) !== undefined,
    }).toEqual({
      lookupError: expect.stringContaining(i18n.global.t(TYPE_LOOKUP_FAILED_KEY, { typeId: ABSENT_TYPE_ID })),
      retryShown: true,
    })
  })

  it('hides the margin of the previous type under the lookup failure', async () => {
    respondToLookupOf(FEROX_TYPE_ID, respondWith(FEROX))
    respondToMarginOf(FEROX_TYPE_ID, respondWith(FEROX_MARGIN))
    const wrapper = await mountMarginsTab(FEROX_TYPE_ID)
    expect(wrapper.text()).toContain(FEROX_ONLY_MATERIAL)
    respondToLookupOf(ABSENT_TYPE_ID, rejectWith(new ApiError('Not Found', 404)))

    await openType(wrapper, ABSENT_TYPE_ID)

    expect({
      lookupErrorShown: typeLookupError(wrapper).exists(),
      previousMarginShown: wrapper.text().includes(FEROX_ONLY_MATERIAL),
      analyses: marginRequests(),
    }).toEqual({
      lookupErrorShown: true,
      previousMarginShown: false,
      analyses: [`${PROFIT_MARGIN_ENDPOINT}${FEROX_TYPE_ID}?runs=1&me=10&te=20`],
    })
  })

  it('looks the type up again on retry and analyzes the T2 type once with ME 2 and TE 4', async () => {
    respondToLookupOf(HECATE_TYPE_ID, rejectWith(new TypeError('Failed to fetch')))
    respondToLookupOf(HECATE_TYPE_ID, respondWith(HECATE))
    respondToMarginOf(HECATE_TYPE_ID, respondWith(HECATE_MARGIN))
    const wrapper = await mountMarginsTab(HECATE_TYPE_ID)

    await retryButton(wrapper)?.trigger('click')
    await flushPromises()

    expect({
      lookups: lookupRequests(),
      analyses: marginRequests(),
      lookupErrorShown: typeLookupError(wrapper).exists(),
      hecateMarginShown: wrapper.text().includes(HECATE_ONLY_MATERIAL),
    }).toEqual({
      lookups: [`${TYPE_LOOKUP_ENDPOINT}${HECATE_TYPE_ID}`, `${TYPE_LOOKUP_ENDPOINT}${HECATE_TYPE_ID}`],
      analyses: [`${PROFIT_MARGIN_ENDPOINT}${HECATE_TYPE_ID}?runs=1&me=2&te=4`],
      lookupErrorShown: false,
      hecateMarginShown: true,
    })
  })
})

describe('ProfitMarginTab successive opened types (issue 238)', () => {
  it('applies the T2 defaults to a new opened type after ME was changed by hand on the previous one', async () => {
    respondToLookupOf(FEROX_TYPE_ID, respondWith(FEROX))
    respondToMarginOf(FEROX_TYPE_ID, respondWith(FEROX_MARGIN))
    respondToLookupOf(HECATE_TYPE_ID, respondWith(HECATE))
    respondToMarginOf(HECATE_TYPE_ID, awaiting(deferred<ProfitMarginResult>()))
    const wrapper = await mountMarginsTab(FEROX_TYPE_ID)
    await levelInput(wrapper, en.industry.createProject.me).setValue(7)

    await openType(wrapper, HECATE_TYPE_ID)

    expect(marginRequests()).toEqual([
      `${PROFIT_MARGIN_ENDPOINT}${FEROX_TYPE_ID}?runs=1&me=10&te=20`,
      `${PROFIT_MARGIN_ENDPOINT}${HECATE_TYPE_ID}?runs=1&me=2&te=4`,
    ])
  })

  it('applies the T1 defaults again when the type chosen in the search is opened after ME was changed by hand', async () => {
    respondToLookupOf(FEROX_TYPE_ID, respondWith(FEROX))
    respondToMarginOf(FEROX_TYPE_ID, awaiting(deferred<ProfitMarginResult>()))
    const wrapper = await mountMarginsTab()
    await chooseInSearch(wrapper, FEROX)
    await levelInput(wrapper, en.industry.createProject.me).setValue(7)

    await openType(wrapper, FEROX_TYPE_ID)

    expect({ lookups: lookupRequests(), analyses: marginRequests() }).toEqual({
      lookups: [`${TYPE_LOOKUP_ENDPOINT}${FEROX_TYPE_ID}`],
      analyses: [`${PROFIT_MARGIN_ENDPOINT}${FEROX_TYPE_ID}?runs=1&me=10&te=20`],
    })
  })

  it('does not look the type up again when the same type is set again', async () => {
    respondToLookupOf(HECATE_TYPE_ID, respondWith(HECATE))
    respondToMarginOf(HECATE_TYPE_ID, respondWith(HECATE_MARGIN))
    const wrapper = await mountMarginsTab(HECATE_TYPE_ID)

    await openType(wrapper, HECATE_TYPE_ID)

    expect({ lookups: lookupRequests(), analyses: marginRequests() }).toEqual({
      lookups: [`${TYPE_LOOKUP_ENDPOINT}${HECATE_TYPE_ID}`],
      analyses: [`${PROFIT_MARGIN_ENDPOINT}${HECATE_TYPE_ID}?runs=1&me=2&te=4`],
    })
  })

  it.each(['after', 'before'] as const)('ignores the lookup of a replaced opened type answering %s the newer lookup', async (order) => {
    const feroxLookup = deferred<SearchResult>()
    const hecateLookup = deferred<SearchResult>()
    respondToLookupOf(FEROX_TYPE_ID, awaiting(feroxLookup))
    respondToLookupOf(HECATE_TYPE_ID, awaiting(hecateLookup))
    respondToMarginOf(HECATE_TYPE_ID, awaiting(deferred<ProfitMarginResult>()))
    const wrapper = await mountMarginsTab(FEROX_TYPE_ID)
    await openType(wrapper, HECATE_TYPE_ID)

    if (order === 'after') {
      hecateLookup.resolve(HECATE)
      await flushPromises()
      feroxLookup.resolve(FEROX)
    } else {
      feroxLookup.resolve(FEROX)
      await flushPromises()
      hecateLookup.resolve(HECATE)
    }
    await flushPromises()

    expect({
      analyses: marginRequests(),
      selectedProduct: selectedProductName(wrapper),
      levels: levels(wrapper),
    }).toEqual({
      analyses: [`${PROFIT_MARGIN_ENDPOINT}${HECATE_TYPE_ID}?runs=1&me=2&te=4`],
      selectedProduct: 'Hecate',
      levels: { me: 2, te: 4, meLocked: true, teLocked: true },
    })
  })

  it('ignores the failed lookup of a replaced opened type', async () => {
    const feroxLookup = deferred<SearchResult>()
    feroxLookup.promise.catch(() => undefined)
    respondToLookupOf(FEROX_TYPE_ID, awaiting(feroxLookup))
    respondToLookupOf(HECATE_TYPE_ID, respondWith(HECATE))
    respondToMarginOf(HECATE_TYPE_ID, respondWith(HECATE_MARGIN))
    const wrapper = await mountMarginsTab(FEROX_TYPE_ID)
    await openType(wrapper, HECATE_TYPE_ID)

    feroxLookup.reject(new ApiError('Not Found', 404))
    await flushPromises()

    expect({
      lookupErrorShown: typeLookupError(wrapper).exists(),
      hecateMarginShown: wrapper.text().includes(HECATE_ONLY_MATERIAL),
      analyses: marginRequests(),
    }).toEqual({
      lookupErrorShown: false,
      hecateMarginShown: true,
      analyses: [`${PROFIT_MARGIN_ENDPOINT}${HECATE_TYPE_ID}?runs=1&me=2&te=4`],
    })
  })

  it('ignores a retry of a replaced opened type that answers late', async () => {
    const lateRetry = deferred<SearchResult>()
    respondToLookupOf(ABSENT_TYPE_ID, rejectWith(new ApiError('Not Found', 404)))
    respondToLookupOf(ABSENT_TYPE_ID, awaiting(lateRetry))
    respondToLookupOf(FEROX_TYPE_ID, respondWith(FEROX))
    respondToMarginOf(FEROX_TYPE_ID, awaiting(deferred<ProfitMarginResult>()))
    const wrapper = await mountMarginsTab(ABSENT_TYPE_ID)
    await retryButton(wrapper)?.trigger('click')
    await flushPromises()
    await openType(wrapper, FEROX_TYPE_ID)

    lateRetry.resolve({ typeId: ABSENT_TYPE_ID, typeName: 'Absent Hull', isT2: true })
    await flushPromises()

    expect({
      lookups: lookupRequests(),
      analyses: marginRequests(),
      selectedProduct: selectedProductName(wrapper),
      levels: levels(wrapper),
    }).toEqual({
      lookups: [
        `${TYPE_LOOKUP_ENDPOINT}${ABSENT_TYPE_ID}`,
        `${TYPE_LOOKUP_ENDPOINT}${ABSENT_TYPE_ID}`,
        `${TYPE_LOOKUP_ENDPOINT}${FEROX_TYPE_ID}`,
      ],
      analyses: [`${PROFIT_MARGIN_ENDPOINT}${FEROX_TYPE_ID}?runs=1&me=10&te=20`],
      selectedProduct: 'Ferox',
      levels: { me: 10, te: 20, meLocked: false, teLocked: false },
    })
  })

  it('ignores the lookup of the opened type when a product is chosen in the search meanwhile', async () => {
    const hecateLookup = deferred<SearchResult>()
    respondToLookupOf(HECATE_TYPE_ID, awaiting(hecateLookup))
    respondToMarginOf(FEROX_TYPE_ID, awaiting(deferred<ProfitMarginResult>()))
    const wrapper = await mountMarginsTab(HECATE_TYPE_ID)
    await chooseInSearch(wrapper, FEROX)

    hecateLookup.resolve(HECATE)
    await flushPromises()
    const afterLateLookup = {
      analyses: marginRequests(),
      selectedProduct: selectedProductName(wrapper),
      levels: levels(wrapper),
    }
    await analyzeButton(wrapper).trigger('click')
    await flushPromises()

    expect({ afterLateLookup, analysesAfterAnalyze: marginRequests() }).toEqual({
      afterLateLookup: {
        analyses: [],
        selectedProduct: 'Ferox',
        levels: { me: 10, te: 20, meLocked: false, teLocked: false },
      },
      analysesAfterAnalyze: [`${PROFIT_MARGIN_ENDPOINT}${FEROX_TYPE_ID}?runs=1&me=10&te=20`],
    })
  })
})

describe('Analysis entries without type lookup (issue 238)', () => {
  it('analyzes a T2 product chosen in the search with ME 2 and TE 4 without looking it up', async () => {
    respondToMarginOf(HECATE_TYPE_ID, awaiting(deferred<ProfitMarginResult>()))
    const wrapper = await mountMarginsTab()

    await chooseInSearch(wrapper, HECATE)
    await analyzeButton(wrapper).trigger('click')
    await flushPromises()

    expect(requestedEndpoints()).toEqual([`${PROFIT_MARGIN_ENDPOINT}${HECATE_TYPE_ID}?runs=1&me=2&te=4`])
  })

  it.each([
    ['BuyVsBuildTab', BuyVsBuildTab, '/industry/buy-vs-build/'],
    ['PivotAdvisorTab', PivotAdvisorTab, '/industry/pivot-advisor/'],
  ] as const)('%s analyzes its initial type without looking it up', async (_name, tab, analysisEndpoint) => {
    outcomes.respondTo(`${analysisEndpoint}${HECATE_TYPE_ID}`, awaiting(deferred<unknown>()))

    mount(tab, {
      global: { plugins: [createTestI18n()], stubs: { ProductSearch: true } },
      props: { initialTypeId: HECATE_TYPE_ID },
    })
    await flushPromises()

    expect(lookupRequests()).toEqual([])
  })
})
