import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import SharedShoppingList from '@/views/SharedShoppingList.vue'
import type { AppraisalItem, AppraisalTotals } from '@/components/shopping/AppraisalResults.vue'

vi.mock(import('vue-router'), async importOriginal => ({
  ...(await importOriginal()),
  useRoute: vi.fn(() => ({ params: { token: 'shared-token' } })) as never,
}))

const UNKNOWN_TYPE_NAME = 'Mystery Ore'
const MISSING_VOLUME_WARNING = 'Unknown volume in the SDE for:'

function appraisalItem(overrides: Partial<AppraisalItem>): AppraisalItem {
  return {
    typeId: 35,
    typeName: 'Pyerite',
    quantity: 10,
    volume: 2,
    totalVolume: 20,
    sellPrice: 1000,
    sellTotal: 10000,
    buyPrice: 900,
    buyTotal: 9000,
    splitPrice: 950,
    splitTotal: 9500,
    sellPriceWeighted: null,
    sellTotalWeighted: null,
    buyPriceWeighted: null,
    buyTotalWeighted: null,
    splitPriceWeighted: null,
    splitTotalWeighted: null,
    sellCoverage: null,
    buyCoverage: null,
    ...overrides,
  }
}

function totals(volume: number | null): AppraisalTotals {
  return {
    sellTotal: 10000,
    buyTotal: 9000,
    splitTotal: 9500,
    volume,
    sellTotalWeighted: null,
    buyTotalWeighted: null,
    splitTotalWeighted: null,
  }
}

async function mountSharedList(items: AppraisalItem[], volume: number | null) {
  const body = {
    token: 'shared-token',
    items,
    notFound: [],
    totals: totals(volume),
    transportCostPerM3: 1200,
    structureId: null,
    structureName: null,
    createdAt: '2026-10-18T10:00:00Z',
    expiresAt: '2026-10-25T10:00:00Z',
  }
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(body), { status: 200 })))
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(SharedShoppingList, { global: { plugins: [i18n], stubs: { OpenInGameButton: true } } })
  await flushPromises()
  return wrapper
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('SharedShoppingList', () => {
  it('names the shared item whose total volume is unknown in a warning', async () => {
    const wrapper = await mountSharedList(
      [appraisalItem({ typeId: 34, typeName: UNKNOWN_TYPE_NAME, volume: null, totalVolume: null }), appraisalItem({})],
      null,
    )
    expect(wrapper.text()).toContain(`${MISSING_VOLUME_WARNING} ${UNKNOWN_TYPE_NAME}.`)
    expect(wrapper.text()).not.toContain(`${MISSING_VOLUME_WARNING} Pyerite`)
  })

  it('shows no warning for a list saved before the fix where every volume is known', async () => {
    const wrapper = await mountSharedList([appraisalItem({})], 20)
    expect(wrapper.text()).toContain('Pyerite')
    expect(wrapper.text()).not.toContain(MISSING_VOLUME_WARNING)
  })
})
