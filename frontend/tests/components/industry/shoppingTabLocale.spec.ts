import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import en from '@/i18n/locales/en.json'
import fr from '@/i18n/locales/fr.json'
import ShoppingTab from '@/components/industry/ShoppingTab.vue'
import { apiRequest } from '@/services/api'
import { useSyncStore } from '@/stores/sync'
import type { ShoppingListItem, ShoppingListResponse } from '@/stores/industry/types'
import { expectNoneOf, expectOnlyLocaleTexts, messageOf, TEST_LOCALES, type TestLocale } from '../../localeTexts'

vi.mock(import('@/services/api'), async importOriginal => ({
  ...(await importOriginal()),
  apiRequest: vi.fn(),
}))

const PROJECT_ID = 'project-221'
const SYNC_STRUCTURE_PATH = '/shopping-list/sync-structure-market'
const GATEWAY_TIMEOUT = 'Gateway timeout (fictif)'

const FORMER_FRENCH_TEXTS = ['Synchronisation du marche...', 'en stock +', 'achetes']
const FORMER_ENGLISH_TEXTS = ['Sync failed']

const PYERITE_WITH_LINKED_PURCHASE: ShoppingListItem = {
  typeId: 35,
  typeName: 'Pyerite',
  quantity: 5_000,
  volume: 0.01,
  totalVolume: 50,
  jitaUnitPrice: 10,
  jitaTotal: 50_000,
  importCost: 0,
  jitaWithImport: 50_000,
  structureUnitPrice: null,
  structureTotal: null,
  bestLocation: 'jita',
  bestPrice: 50_000,
  savings: null,
  purchasedQuantity: 1_200,
  extraQuantity: 0,
  jitaWeightedUnitPrice: null,
  jitaWeightedTotal: null,
  jitaCoverage: null,
}

const SHOPPING_LIST: ShoppingListResponse = {
  materials: [PYERITE_WITH_LINKED_PURCHASE],
  structureId: 1049588174021,
  structureName: null,
  structureAccessible: true,
  structureFromCache: false,
  structureLastSync: null,
  transportCostPerM3: 1200,
  totals: {
    jita: 50_000,
    import: 0,
    jitaWithImport: 50_000,
    structure: null,
    volume: 50,
    best: 50_000,
    savingsVsJitaWithImport: 0,
    savingsVsStructure: null,
  },
  priceError: null,
  missingVolumeTypeIds: [],
  failedProducts: [],
  missingPriceTypeIds: [],
}

interface ShoppingTabExposed {
  shoppingPriceError: string | null
  loadShoppingList: () => Promise<void>
}

async function mountLoadedShoppingTab(locale: TestLocale): Promise<VueWrapper> {
  const i18n = createI18n({ legacy: false, locale, messages: { en, fr } })
  const wrapper = mount(ShoppingTab, {
    global: {
      plugins: [i18n],
      stubs: { ShoppingConfigPanel: true, ShoppingMaterialsTable: true, ShoppingPurchasesPanel: true },
    },
    props: { projectId: PROJECT_ID },
  })
  await (wrapper.vm as unknown as ShoppingTabExposed).loadShoppingList()
  await flushPromises()
  return wrapper
}

function formerTextsOtherThan(locale: TestLocale): string[] {
  return locale === 'fr' ? FORMER_ENGLISH_TEXTS : FORMER_FRENCH_TEXTS
}

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
  vi.mocked(apiRequest).mockReset()
  vi.mocked(apiRequest).mockImplementation(async (url: string) => {
    if (url.includes('/shopping-list')) return SHOPPING_LIST
    throw new Error(`Unexpected request ${url}`)
  })
})

enableAutoUnmount(afterEach)

describe('ShoppingTab locale', () => {
  it.each(TEST_LOCALES)('shows the %s market synchronisation text while the structure market syncs without a message', async (locale) => {
    useSyncStore().syncStatus['market-structure'] = {
      syncType: 'market-structure',
      status: 'in_progress',
      progress: null,
      message: null,
      data: null,
      timestamp: '2026-11-03T08:00:00+00:00',
    }

    const wrapper = await mountLoadedShoppingTab(locale)

    expectOnlyLocaleTexts(wrapper.text(), locale, ['industry.shoppingTab.marketSyncInProgress'])
    expectNoneOf(wrapper.text(), formerTextsOtherThan(locale))
  })

  it.each(TEST_LOCALES)('shows the message of the synchronisation instead of the %s fallback when it carries one', async (locale) => {
    const MESSAGE_OF_THE_SYNC = 'Fetching orders page 3 (fictif)'
    useSyncStore().syncStatus['market-structure'] = {
      syncType: 'market-structure',
      status: 'in_progress',
      progress: 40,
      message: MESSAGE_OF_THE_SYNC,
      data: null,
      timestamp: '2026-11-03T08:00:00+00:00',
    }

    const wrapper = await mountLoadedShoppingTab(locale)

    expect(wrapper.text()).toContain(MESSAGE_OF_THE_SYNC)
    expect(wrapper.text()).not.toContain(messageOf(locale, 'industry.shoppingTab.marketSyncInProgress'))
  })

  it.each(TEST_LOCALES)('shows only %s texts for the structure name fallback and the stock and purchase duplicates', async (locale) => {
    const wrapper = await mountLoadedShoppingTab(locale)
    const configPanel = wrapper.findComponent({ name: 'ShoppingConfigPanel' })

    configPanel.vm.$emit('update:pasted-stock', 'Pyerite\t800')
    configPanel.vm.$emit('analyze-stock')
    await flushPromises()

    const warning = messageOf(locale, 'industry.shoppingTab.stockPurchaseWarning')
      .replace('{name}', 'Pyerite')
      .replace('{stock}', (800).toLocaleString())
      .replace('{purchased}', (1_200).toLocaleString())
    expect(wrapper.text()).toContain(warning)
    expect(wrapper.text()).toContain(messageOf(locale, 'industry.shoppingTab.priceInfo').replace('{structure}', messageOf(locale, 'industry.shoppingTab.structure')))
    expectNoneOf(wrapper.text(), formerTextsOtherThan(locale))
  })

  it.each(TEST_LOCALES)('keeps the %s generic failure when the structure market synchronisation fails without a message', async (locale) => {
    const wrapper = await mountLoadedShoppingTab(locale)
    vi.mocked(apiRequest).mockImplementation(async (url: string) => {
      if (url === SYNC_STRUCTURE_PATH) throw GATEWAY_TIMEOUT
      throw new Error(`Unexpected request ${url}`)
    })

    wrapper.findComponent({ name: 'ShoppingConfigPanel' }).vm.$emit('sync-structure')
    await flushPromises()

    expect((wrapper.vm as unknown as ShoppingTabExposed).shoppingPriceError).toBe(messageOf(locale, 'common.errors.syncFailed'))
  })
})
