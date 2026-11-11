import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ProfitMarginTab from '@/components/industry/ProfitMarginTab.vue'
import { apiRequest } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type { ProfitMarginResult, SearchResult } from '@/stores/industry/types'
import type { Character, User } from '@/types'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

enableAutoUnmount(afterEach)

const SABRE_TYPE_ID = 22456
const PROFIT_MARGIN_ENDPOINT = `/industry/profit-margin/${SABRE_TYPE_ID}`
const SABRE: SearchResult = { typeId: SABRE_TYPE_ID, typeName: 'Sabre', isT2: true }
const MAIN_ID = '0192f5a0-7c1e-7d2a-9b4e-3a5c6d7e8f90'
const ALT_ID = '0192f5a0-7c1e-7d2a-9b4e-3a5c6d7e8faa'

function character(id: string, name: string, isMain: boolean): Character {
  return {
    id,
    eveCharacterId: isMain ? 90000001 : 90000002,
    name,
    corporationId: 98000001,
    corporationName: 'Corp',
    allianceId: null,
    allianceName: null,
    isMain,
    hasValidToken: true,
    lastSyncAt: null,
  }
}

const SABRE_MARGIN_RESULT: ProfitMarginResult = {
  typeId: SABRE_TYPE_ID,
  typeName: 'Sabre',
  isT2: true,
  runs: 4,
  outputQuantity: 40,
  outputPerRun: 10,
  materialCost: 100_000,
  materials: [],
  jobInstallCost: 5_000,
  jobInstallSteps: [],
  inventionCost: 26_000,
  totalCost: 131_000,
  costPerUnit: 3_275,
  invention: {
    baseProbability: 0.34,
    unknownReason: null,
    datacores: ['Datacore A'],
    datacoresCostPerAttempt: 460,
    inventor: { characterId: MAIN_ID, characterName: 'Pilot Prime', skillsKnown: true },
    selectedDecryptorTypeId: null,
    selectedDecryptorName: 'No Decryptor',
    options: [{
      manufacturingJobs: 1,
      decryptorTypeId: null,
      decryptorName: 'No Decryptor',
      me: 2,
      te: 4,
      runs: 10,
      probability: 0.4958,
      inventionCost: 26_000,
      totalProductionCost: 131_000,
      bestMargin: 19.69,
      expectedAttempts: 0.2,
      decryptorCost: 0,
      costPerUnit: 3_275,
      costPerUnitDelta: 0,
    }],
  },
  sellPrices: {
    jitaSell: null,
    structureSell: 4_150,
    structureBuy: 4_000,
    contractSell: null,
    contractCount: null,
    jitaSellCoveragePercent: null,
    structureId: 1_035_466_617_946,
    structureName: 'Tatara Alpha',
  },
  brokerFeeRate: 0.05,
  salesTaxRate: 0.02,
  margins: {},
  dailyVolume: null,
  missingData: [],
  warnings: [],
}

function signInWith(characters: Character[]): void {
  useAuthStore().user = {
    id: 'user-1',
    authStatus: 'active',
    mainCharacter: characters.find(candidate => candidate.isMain) ?? null,
    characters,
    corporationId: 98000001,
    corporationName: 'Corp',
  } as User
}

async function mountSabreAnalysis(): Promise<VueWrapper> {
  vi.mocked(apiRequest).mockImplementation((path: string) => {
    if (path === `/industry/types/${SABRE_TYPE_ID}`) return Promise.resolve(SABRE)
    if (path.startsWith(PROFIT_MARGIN_ENDPOINT)) return Promise.resolve(SABRE_MARGIN_RESULT)
    return Promise.resolve(null)
  })
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, fr } })
  const wrapper = mount(ProfitMarginTab, {
    global: { plugins: [i18n], stubs: { ProductSearch: true } },
    props: { initialTypeId: SABRE_TYPE_ID },
  })
  await flushPromises()
  return wrapper
}

function marginRequests(): URLSearchParams[] {
  return vi.mocked(apiRequest).mock.calls
    .map(([path]) => path)
    .filter(path => path.startsWith(PROFIT_MARGIN_ENDPOINT))
    .map(path => new URLSearchParams(path.split('?')[1] ?? ''))
}

async function chooseInventor(wrapper: VueWrapper, value: string): Promise<void> {
  await wrapper.find('select[data-test="inventor-select"]').setValue(value)
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.mocked(apiRequest).mockReset()
  signInWith([character(MAIN_ID, 'Pilot Prime', true), character(ALT_ID, 'Pilot Alt', false)])
})

describe('ProfitMarginTab inventor of the invention', () => {
  it('analyzes with the main character as inventor by default', async () => {
    await mountSabreAnalysis()

    expect(marginRequests().map(query => query.get('inventorCharacterId'))).toEqual([MAIN_ID])
  })

  it('analyzes again with the chosen character', async () => {
    const wrapper = await mountSabreAnalysis()

    await chooseInventor(wrapper, ALT_ID)

    expect(marginRequests().map(query => query.get('inventorCharacterId'))).toEqual([MAIN_ID, ALT_ID])
  })

  it('analyzes again without inventor parameter when no skills is chosen', async () => {
    const wrapper = await mountSabreAnalysis()

    await chooseInventor(wrapper, '')

    expect(marginRequests().map(query => query.has('inventorCharacterId'))).toEqual([true, false])
  })

  it('sends no inventor when the user has no main character', async () => {
    signInWith([character(ALT_ID, 'Pilot Alt', false)])

    await mountSabreAnalysis()

    expect(marginRequests().map(query => query.has('inventorCharacterId'))).toEqual([false])
  })
})
