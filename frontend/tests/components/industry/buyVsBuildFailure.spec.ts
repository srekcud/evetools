import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import BuyVsBuildTab from '@/components/industry/BuyVsBuildTab.vue'
import { apiRequest } from '@/services/api'
import { useScannerStore } from '@/stores/industry/scanner'
import type { BuyVsBuildResult } from '@/stores/industry/types'
import { apiOutcomesByPath, rejectWith, respondWith, type ApiOutcome } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ISHTAR_TYPE_ID = 12005
const VEXOR_TYPE_ID = 626
const SUSTAINED_SHIELD_EMITTER_TYPE_ID = 11_553
const BLUEPRINT_NOT_FOUND = 'Blueprint not found (fictif)'

const ISHTAR_BUY_VS_BUILD: BuyVsBuildResult = {
  typeId: ISHTAR_TYPE_ID,
  typeName: 'Ishtar',
  isT2: true,
  runs: 1,
  totalProductionCost: 212_400_000,
  sellPrice: 268_000_000,
  marginPercent: 20.75,
  components: [
    {
      typeId: SUSTAINED_SHIELD_EMITTER_TYPE_ID,
      typeName: 'Sustained Shield Emitter',
      quantity: 8,
      stage: 'T2 Component',
      buildCost: 9_120_000,
      buildMaterials: [
        { typeId: 11_399, typeName: 'Morphite', quantity: 120, unitPrice: 9_400, totalPrice: 1_128_000 },
      ],
      buildJobInstallCost: 184_000,
      buyCostJita: 10_560_000,
      buyCostStructure: null,
      buyCostJitaCoveragePercent: null,
      verdict: 'build',
      savings: 1_440_000,
      savingsPercent: 13.64,
      meUsed: 10,
      runs: 8,
      missingData: [],
      warnings: [],
    },
  ],
  buildAllCost: 9_120_000,
  buyAllCost: 10_560_000,
  optimalMixCost: 9_120_000,
  buildTypeIds: [SUSTAINED_SHIELD_EMITTER_TYPE_ID],
  buyTypeIds: [],
  missingData: [],
  warnings: [],
  sellStructureId: null,
}

const outcomes = apiOutcomesByPath()

function respondToBuyVsBuildOf(typeId: number, outcome: ApiOutcome): void {
  outcomes.respondTo(`/industry/buy-vs-build/${typeId}`, outcome)
}

function redErrorBoxes(wrapper: VueWrapper): string[] {
  return wrapper.findAll('div').filter(box => box.classes('bg-red-500/20')).map(box => box.text())
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  outcomes.clear()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (endpoint: string) => {
    const answer = outcomes.answer(endpoint)
    return answer === undefined ? null : answer
  })
})

describe('BuyVsBuildTab analysis failure (issue 189)', () => {
  it('shows the failure of the Vexor analysis without the Ishtar buy vs build', async () => {
    respondToBuyVsBuildOf(ISHTAR_TYPE_ID, respondWith(ISHTAR_BUY_VS_BUILD))
    respondToBuyVsBuildOf(VEXOR_TYPE_ID, rejectWith(new Error(BLUEPRINT_NOT_FOUND)))
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
    const wrapper = mount(BuyVsBuildTab, {
      global: { plugins: [i18n], stubs: { ProductSearch: true } },
      props: { initialTypeId: ISHTAR_TYPE_ID },
    })
    await flushPromises()
    const ishtarShownBeforeFailure = wrapper.text().includes('Sustained Shield Emitter')

    await wrapper.setProps({ initialTypeId: VEXOR_TYPE_ID })
    await flushPromises()

    expect({
      ishtarShownBeforeFailure,
      errorBoxes: redErrorBoxes(wrapper),
      ishtarShown: wrapper.text().includes('Ishtar') || wrapper.text().includes('Sustained Shield Emitter'),
      buyVsBuildResult: useScannerStore().buyVsBuildResult,
    }).toEqual({
      ishtarShownBeforeFailure: true,
      errorBoxes: [BLUEPRINT_NOT_FOUND],
      ishtarShown: false,
      buyVsBuildResult: null,
    })
  })
})
