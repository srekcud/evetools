import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import BuyVsBuildTab from '@/components/industry/BuyVsBuildTab.vue'
import { useScannerStore } from '@/stores/industry/scanner'
import { useIndustryStore } from '@/stores/industry'
import type { BuyVsBuildComponent, BuyVsBuildResult } from '@/stores/industry/types'

const WIDOW_TYPE_ID = 22436
const MAGPULSE_THRUSTER_TYPE_ID = 11533
const SUSTAINED_SHIELD_EMITTER_TYPE_ID = 11558
const TITANIUM_CARBIDE_TYPE_ID = 16671
const FERROGEL_TYPE_ID = 16683
const WIDOW_RUNS = 10
const ENTERED_ME = 2

const MAGPULSE_THRUSTER_TO_BUILD: BuyVsBuildComponent = {
  typeId: MAGPULSE_THRUSTER_TYPE_ID,
  typeName: 'Magpulse Thruster',
  quantity: 2030,
  stage: 'T2 Component',
  buildCost: 41_000_000,
  buildMaterials: [{ typeId: 11399, typeName: 'Morphite', quantity: 30, unitPrice: 9_000, totalPrice: 270_000 }],
  buildJobInstallCost: 1_200_000,
  buyCostJita: 52_000_000,
  buyCostStructure: null,
  verdict: 'build',
  savings: 11_000_000,
  savingsPercent: 21.15,
  meUsed: 10,
  runs: 2030,
  missingData: [],
}

const SUSTAINED_SHIELD_EMITTER_TO_BUY: BuyVsBuildComponent = {
  typeId: SUSTAINED_SHIELD_EMITTER_TYPE_ID,
  typeName: 'Sustained Shield Emitter',
  quantity: 4413,
  stage: 'T2 Component',
  buildCost: 88_000_000,
  buildMaterials: [{ typeId: 16683, typeName: 'Ferrogel', quantity: 10, unitPrice: 31_000, totalPrice: 310_000 }],
  buildJobInstallCost: 2_400_000,
  buyCostJita: 79_000_000,
  buyCostStructure: 77_500_000,
  verdict: 'buy',
  savings: -10_500_000,
  savingsPercent: -13.55,
  meUsed: 10,
  runs: 4413,
  missingData: [],
}

const TITANIUM_CARBIDE_AT_A_LOSS: BuyVsBuildComponent = {
  typeId: TITANIUM_CARBIDE_TYPE_ID,
  typeName: 'Titanium Carbide',
  quantity: 20_000,
  stage: 'Reaction',
  buildCost: 6_300_000,
  buildMaterials: [{ typeId: 4312, typeName: 'Oxygen Fuel Block', quantity: 5, unitPrice: 17_000, totalPrice: 85_000 }],
  buildJobInstallCost: 310_000,
  buyCostJita: 4_100_000,
  buyCostStructure: null,
  verdict: 'loss',
  savings: -2_200_000,
  savingsPercent: -53.66,
  meUsed: 0,
  runs: 2,
  missingData: [],
}

const FERROGEL_WITHOUT_PRICE: BuyVsBuildComponent = {
  typeId: FERROGEL_TYPE_ID,
  typeName: 'Ferrogel',
  quantity: 90,
  stage: 'Reaction',
  buildCost: null,
  buildMaterials: [{ typeId: 16654, typeName: 'Titanium Chromide', quantity: 100, unitPrice: null, totalPrice: null }],
  buildJobInstallCost: 45_000,
  buyCostJita: null,
  buyCostStructure: null,
  verdict: 'unknown',
  savings: null,
  savingsPercent: null,
  meUsed: 0,
  runs: 1,
  missingData: [{ reason: 'market_price', typeId: FERROGEL_TYPE_ID }],
}

const WIDOW_ANALYSIS: BuyVsBuildResult = {
  typeId: WIDOW_TYPE_ID,
  typeName: 'Widow',
  isT2: true,
  runs: WIDOW_RUNS,
  totalProductionCost: null,
  sellPrice: 1_150_000_000,
  marginPercent: null,
  components: [MAGPULSE_THRUSTER_TO_BUILD, SUSTAINED_SHIELD_EMITTER_TO_BUY, TITANIUM_CARBIDE_AT_A_LOSS, FERROGEL_WITHOUT_PRICE],
  buildAllCost: null,
  buyAllCost: null,
  optimalMixCost: null,
  buildTypeIds: [MAGPULSE_THRUSTER_TYPE_ID],
  buyTypeIds: [SUSTAINED_SHIELD_EMITTER_TYPE_ID, TITANIUM_CARBIDE_TYPE_ID],
  missingData: [{ reason: 'market_price', typeId: FERROGEL_TYPE_ID }],
  sellStructureId: 1_035_466_617_946,
}

const NITROGEN_FUEL_BLOCK_ANALYSIS: BuyVsBuildResult = {
  typeId: 4051,
  typeName: 'Nitrogen Fuel Block',
  isT2: false,
  runs: 40,
  totalProductionCost: 39_452.95,
  sellPrice: 15_000,
  marginPercent: 1375.17,
  components: [],
  buildAllCost: 0,
  buyAllCost: 0,
  optimalMixCost: 0,
  buildTypeIds: [],
  buyTypeIds: [],
  missingData: [],
  sellStructureId: null,
}

function mountWithAnalysis(analysis: BuyVsBuildResult) {
  useScannerStore().buyVsBuildResult = analysis
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(BuyVsBuildTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
  })
}

type Wrapper = ReturnType<typeof mountWithAnalysis>

function componentCard(wrapper: Wrapper, typeName: string) {
  const card = wrapper.findAll('div.overflow-hidden').find(candidate => candidate.find('p.font-semibold').exists() && candidate.find('p.font-semibold').text() === typeName)
  if (!card) throw new Error(`No component card ${typeName}`)
  return card
}

async function chooseFor(wrapper: Wrapper, typeName: string, choice: 'Build' | 'Buy') {
  const button = componentCard(wrapper, typeName).findAll('button').find(candidate => candidate.text().trim() === choice)
  if (!button) throw new Error(`No ${choice} button for ${typeName}`)
  await button.trigger('click')
}

async function createTheProject(wrapper: Wrapper) {
  const button = wrapper.findAll('button').find(candidate => candidate.text().trim() === en.industry.scanner.buyVsBuild.createProject)
  if (!button) throw new Error('No create project button')
  await button.trigger('click')
  await flushPromises()
}

function exclusionNotice(wrapper: Wrapper): string {
  const notice = wrapper.findAll('p').find(candidate => candidate.text().startsWith('Excludes:'))
  if (!notice) throw new Error('No exclusion notice')
  return notice.text()
}

function excludedTypeIdsOfTheIntent(): unknown {
  const intent = useIndustryStore().navigationIntent
  if (intent === null || intent.target !== 'projects' || !intent.prefill) throw new Error('No project intent with prefill')
  return intent.prefill.excludedTypeIds
}

beforeEach(() => {
  setActivePinia(createPinia())
  useIndustryStore().navigationIntent = null
})

describe('BuyVsBuildTab create project from the chosen mix', () => {
  it('excludes the bought and loss components, not the built nor the unknown ones, without user choice', async () => {
    const wrapper = mountWithAnalysis(WIDOW_ANALYSIS)

    await createTheProject(wrapper)

    expect(useIndustryStore().navigationIntent).toEqual({
      target: 'projects',
      prefill: {
        typeId: WIDOW_TYPE_ID,
        typeName: 'Widow',
        runs: WIDOW_RUNS,
        me: ENTERED_ME,
        isT2: true,
        excludedTypeIds: [SUSTAINED_SHIELD_EMITTER_TYPE_ID, TITANIUM_CARBIDE_TYPE_ID],
      },
    })
    expect(exclusionNotice(wrapper)).toBe('Excludes: Sustained Shield Emitter, Titanium Carbide')
  })

  it('excludes an unknown component the user chose to buy', async () => {
    const wrapper = mountWithAnalysis(WIDOW_ANALYSIS)

    await chooseFor(wrapper, 'Ferrogel', 'Buy')
    await createTheProject(wrapper)

    expect(excludedTypeIdsOfTheIntent()).toEqual([SUSTAINED_SHIELD_EMITTER_TYPE_ID, TITANIUM_CARBIDE_TYPE_ID, FERROGEL_TYPE_ID])
    expect(exclusionNotice(wrapper)).toBe('Excludes: Sustained Shield Emitter, Titanium Carbide, Ferrogel')
  })

  it('keeps a bought component the user chose to build', async () => {
    const wrapper = mountWithAnalysis(WIDOW_ANALYSIS)

    await chooseFor(wrapper, 'Sustained Shield Emitter', 'Build')
    await createTheProject(wrapper)

    expect(excludedTypeIdsOfTheIntent()).toEqual([TITANIUM_CARBIDE_TYPE_ID])
    expect(exclusionNotice(wrapper)).toBe('Excludes: Titanium Carbide')
  })

  it('tells that a product which is not T2 is not T2', async () => {
    const wrapper = mountWithAnalysis(NITROGEN_FUEL_BLOCK_ANALYSIS)

    await createTheProject(wrapper)

    const intent = useIndustryStore().navigationIntent
    if (intent === null || intent.target !== 'projects' || !intent.prefill) throw new Error('No project intent with prefill')
    expect(intent.prefill.isT2).toBe(false)
  })
})
