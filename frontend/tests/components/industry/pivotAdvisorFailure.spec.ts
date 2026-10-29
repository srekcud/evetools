import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import PivotAdvisorTab from '@/components/industry/PivotAdvisorTab.vue'
import { ApiError, apiRequest } from '@/services/api'
import { useScannerStore } from '@/stores/industry/scanner'
import type { PivotAnalysisResult } from '@/stores/industry/types'
import { apiOutcomesByPath, rejectWith, respondWith, type ApiOutcome } from '../../deferred'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const ISHTAR_TYPE_ID = 12005
const VEXOR_TYPE_ID = 626

const ISHTAR_PIVOT: PivotAnalysisResult = {
  typeId: ISHTAR_TYPE_ID,
  sourceProduct: {
    typeId: ISHTAR_TYPE_ID,
    typeName: 'Ishtar',
    groupName: 'Heavy Assault Cruiser',
    marginPercent: 14.2,
    sellPrice: 268_000_000,
    dailyVolume: 37,
    keyComponents: [],
  },
  matrix: [],
  candidates: [],
  matrixProductIds: [ISHTAR_TYPE_ID],
}

const outcomes = apiOutcomesByPath()

function respondToPivotOf(typeId: number, outcome: ApiOutcome): void {
  outcomes.respondTo(`/industry/pivot-advisor/${typeId}`, outcome)
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

describe('PivotAdvisorTab analysis failure (issue 189)', () => {
  it('shows the failure of the Vexor analysis without the Ishtar pivots', async () => {
    respondToPivotOf(ISHTAR_TYPE_ID, respondWith(ISHTAR_PIVOT))
    respondToPivotOf(VEXOR_TYPE_ID, rejectWith(new ApiError('API error: 503', 503)))
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
    const wrapper = mount(PivotAdvisorTab, {
      global: { plugins: [i18n], stubs: { ProductSearch: true } },
      props: { initialTypeId: ISHTAR_TYPE_ID },
    })
    await flushPromises()
    const ishtarShownBeforeFailure = wrapper.find('h2').exists() && wrapper.find('h2').text() === 'Ishtar'

    await wrapper.setProps({ initialTypeId: VEXOR_TYPE_ID })
    await flushPromises()

    expect({
      ishtarShownBeforeFailure,
      errorBoxes: redErrorBoxes(wrapper),
      ishtarShown: wrapper.text().includes('Ishtar'),
      pivotResult: useScannerStore().pivotResult,
    }).toEqual({
      ishtarShownBeforeFailure: true,
      errorBoxes: ['API error: 503'],
      ishtarShown: false,
      pivotResult: null,
    })
  })
})
